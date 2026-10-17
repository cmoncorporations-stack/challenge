<?php
/* Tests du relais — `php relay/tests/run.php`, sans dépendance.
   Brevo est simulé : chaque appel est consigné et l'état des contacts tenu
   en mémoire, ce qui permet de vérifier ce que le relais écrit vraiment.
   Le dernier groupe lance le vrai point d'entrée (public/index.php) dans le
   serveur intégré de PHP, avec un faux Brevo joint par cURL. */

declare(strict_types=1);

require __DIR__ . '/../src/Relay.php';

use ClubCatalist\Relay;

const ORIGIN = 'https://clubcatalist.cmon-sports.com';
const CFG = ['BREVO_API_KEY' => 'test-key', 'NOTIFY_TO' => 'sales@example.com'];

$failures = 0;
$count = 0;
function test(string $name, callable $fn): void
{
    global $failures, $count;
    $count++;
    try {
        $fn();
        echo "ok   $name\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "FAIL $name\n     " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
function eq(mixed $actual, mixed $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        throw new \Exception(($msg ? "$msg : " : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}
function ok(bool $cond, string $msg): void
{
    if (!$cond) throw new \Exception($msg);
}
function tmpdir(): string
{
    $d = sys_get_temp_dir() . '/cc-relay-' . bin2hex(random_bytes(6));
    mkdir($d, 0700, true);
    return $d;
}
function ksorted(array $a): array { ksort($a); return $a; }

/* ---------------- Faux Brevo ---------------- */
final class FakeBrevo
{
    public array $calls = [];
    public function __construct(
        public array $contacts = [],
        public ?array $attributes = null,
        public bool $failSmtp = false,
        public bool $failEvents = false,
        public ?int $getStatus = null,
    ) {
        $this->attributes ??= array_column(Relay::ATTRIBUTES, 0);
    }
    public function __invoke(string $method, string $path, mixed $body = null): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
        $res = fn(int $s, mixed $d = null) => ['ok' => $s >= 200 && $s < 300, 'status' => $s, 'data' => $d];
        if ($path === '/contacts/attributes' && $method === 'GET')
            return $res(200, ['attributes' => array_map(fn($n) => ['name' => $n], $this->attributes)]);
        if (str_starts_with($path, '/contacts/attributes/normal/')) {
            $this->attributes[] = basename($path);
            return $res(201, []);
        }
        if (str_starts_with($path, '/contacts/lists/')) return $res(200, ['id' => 63]);
        if (preg_match('#^/contacts/(.+)$#', $path, $m)) {
            $email = rawurldecode($m[1]);
            if ($method === 'GET') {
                if ($this->getStatus) return $res($this->getStatus, []);
                return isset($this->contacts[$email]) ? $res(200, $this->contacts[$email]) : $res(404, ['code' => 'document_not_found']);
            }
            if ($method === 'PUT') {
                $c = &$this->contacts[$email];
                $c['attributes'] = array_merge($c['attributes'] ?? [], $body['attributes']);
                $c['listIds'] = array_values(array_unique(array_merge($c['listIds'] ?? [], $body['listIds'])));
                return $res(204);
            }
        }
        if ($path === '/contacts' && $method === 'POST') {
            if (isset($this->contacts[$body['email']])) return $res(400, ['code' => 'duplicate_parameter']);
            $this->contacts[$body['email']] = ['email' => $body['email'], 'emailBlacklisted' => $body['emailBlacklisted'],
                                               'attributes' => $body['attributes'], 'listIds' => $body['listIds']];
            return $res(201, ['id' => 1]);
        }
        if ($path === '/events') return $this->failEvents ? $res(400, []) : $res(204);
        if ($path === '/smtp/email') return $this->failSmtp ? $res(400, []) : $res(201, ['messageId' => 'm']);
        return $res(500, []);
    }
    public function writes(): array { return array_values(array_filter($this->calls, fn($c) => $c['method'] !== 'GET')); }
    public function to(string $path): array { return array_values(array_filter($this->calls, fn($c) => $c['path'] === $path)); }
}

function relay(FakeBrevo $b, array $cfg = CFG, ?string $var = null): Relay
{
    return new Relay($cfg, $b, $var ?? tmpdir());
}
function post(Relay $r, mixed $body, string $origin = ORIGIN, ?string $raw = null): array
{
    [$status, $headers, $out] = $r->handle(['method' => 'POST', 'path' => '/sync', 'origin' => $origin, 'ip' => '203.0.113.7',
        'body' => $raw ?? json_encode($body, JSON_UNESCAPED_UNICODE)]);
    return ['status' => $status, 'headers' => $headers, 'body' => $out];
}

/* ================= Tests ================= */

test("CORS : seule l'origine du jeu est servie", function () {
    $r = relay(new FakeBrevo());
    [$s, $h] = $r->handle(['method' => 'OPTIONS', 'path' => '/sync', 'origin' => ORIGIN, 'ip' => '1']);
    eq($s, 204);
    eq($h['Access-Control-Allow-Origin'] ?? null, ORIGIN);
    [$s] = $r->handle(['method' => 'OPTIONS', 'path' => '/sync', 'origin' => 'https://evil.test', 'ip' => '1']);
    eq($s, 403);
    eq(post($r, ['email' => 'a@b.ch'], 'https://evil.test')['status'], 403);
});

test('nouveau joueur sans opt-in : créé dans la liste 63, exclu des campagnes', function () {
    $b = new FakeBrevo();
    $res = post(relay($b), ['email' => 'Joueur@Club.CH', 'pseudo' => 'joueur', 'optIn' => false, 'org' => 'HC Test',
                            'role' => 'Marketing & communication', 'sport' => 4, 'lang' => 'de']);
    eq($res['status'], 200);
    eq($res['body'], ['ok' => true]);
    $c = $b->contacts['joueur@club.ch'];
    eq($c['emailBlacklisted'], true);
    eq($c['listIds'], [63]);
    eq($c['attributes']['OPT_IN'], false);
    eq($c['attributes']['CMON_SOLUTION_CLUBCATALIST'], true);
    eq($c['attributes']['COMPANY'], 'HC Test');
    eq($c['attributes']['JOB_TITLE'], 'Marketing & communication');
    eq($c['attributes']['SPORT'], 4);
    eq($c['attributes']['CC_LANG'], 'de');
});

test('nouveau joueur avec opt-in : pas sur liste noire', function () {
    $b = new FakeBrevo();
    post(relay($b), ['email' => 'fan@club.ch', 'optIn' => true]);
    eq($b->contacts['fan@club.ch']['emailBlacklisted'], false);
    eq($b->contacts['fan@club.ch']['attributes']['OPT_IN'], true);
});

test('contact existant : jamais mis sur liste noire, champs partagés préservés', function () {
    $b = new FakeBrevo(contacts: ['client@club.ch' => [
        'email' => 'client@club.ch', 'emailBlacklisted' => false, 'listIds' => [24, 34],
        'attributes' => ['OPT_IN' => true, 'COMPANY' => 'Vraie Société', 'SPORT' => '2', 'PRENOM' => 'Anne']]]);
    $res = post(relay($b), ['email' => 'client@club.ch', 'optIn' => false, 'org' => 'Autre chose', 'role' => 'Autre', 'sport' => 4]);
    eq($res['status'], 200);
    $put = array_values(array_filter($b->writes(), fn($c) => $c['method'] === 'PUT'))[0] ?? null;
    ok($put !== null, 'mise à jour attendue');
    ok(!array_key_exists('emailBlacklisted', $put['body']), 'emailBlacklisted ne doit pas être envoyé');
    foreach (['OPT_IN', 'COMPANY', 'SPORT'] as $k) ok(!array_key_exists($k, $put['body']['attributes']), "$k ne doit pas être écrasé");
    eq($put['body']['attributes']['JOB_TITLE'], 'Autre', 'vide auparavant : complété');
    $c = $b->contacts['client@club.ch'];
    eq($c['emailBlacklisted'], false);
    eq($c['attributes']['OPT_IN'], true);
    eq($c['attributes']['COMPANY'], 'Vraie Société');
    eq($c['listIds'], [24, 34, 63]);
    eq(count(array_filter($b->writes(), fn($x) => $x['path'] === '/contacts')), 0, 'aucune création');
});

test('contact existant qui consent : OPT_IN passe à vrai, liste noire inchangée', function () {
    $b = new FakeBrevo(contacts: ['x@y.ch' => ['email' => 'x@y.ch', 'emailBlacklisted' => true, 'attributes' => ['OPT_IN' => false]]]);
    post(relay($b), ['email' => 'x@y.ch', 'optIn' => true]);
    $put = array_values(array_filter($b->writes(), fn($c) => $c['method'] === 'PUT'))[0];
    eq($put['body']['attributes']['OPT_IN'], true);
    ok(!array_key_exists('emailBlacklisted', $put['body']), 'liste noire inchangée');
});

test("la réponse ne révèle pas si l'adresse existait", function () {
    $r = relay(new FakeBrevo(contacts: ['connu@x.ch' => ['email' => 'connu@x.ch', 'attributes' => []]]));
    eq(post($r, ['email' => 'connu@x.ch']), post($r, ['email' => 'inconnu@x.ch']));
});

test("signaux d'usage et questionnaire transmis", function () {
    $b = new FakeBrevo();
    post(relay($b), ['email' => 'p@x.ch', 'tuto' => 'Terminé',
        'q' => ['CC_MATURITY' => 'Base peu exploitée', 'CC_PRIORITY' => 'Structuration marketing digital', 'CC_TIMING' => 'Pas maintenant'],
        'cc' => ['seasons' => 2, 'services' => 'Fan Equity Audit, Fan Data Factory', 'cert' => 'Gold', 'lastPlayed' => '2026-10-15']]);
    $a = $b->contacts['p@x.ch']['attributes'];
    eq($a['CC_SEASONS'], 2);
    eq($a['CC_SERVICES'], 'Fan Equity Audit, Fan Data Factory');
    eq($a['CC_CERT'], 'Gold');
    eq($a['CC_LAST_PLAYED'], '2026-10-15');
    eq($a['CC_TUTO'], 'Terminé');
    eq($a['CC_MATURITY'], 'Base peu exploitée');
    eq($a['CC_TIMING'], 'Pas maintenant');
    ok(!isset($a['CC_HOT_AT']), "pas d'appel chaud");
    eq(count($b->to('/smtp/email')), 0);
});

test('événements : les valides partent vers Brevo, les autres sont ignorés', function () {
    $b = new FakeBrevo();
    $at = gmdate('Y-m-d\TH:i:s', time() - 3600) . '.000Z';
    $res = post(relay($b), ['email' => 'e@x.ch', 'events' => [
        ['name' => 'club_catalist_service_tried', 'props' => ['service' => 'Fan Data Factory'], 'at' => $at],
        ['name' => 'club_catalist_service_tried', 'props' => ['service' => 'Inventé']],
        ['name' => 'club_catalist_season_completed', 'props' => ['seasons' => 1, 'cert' => 'Silver'], 'at' => '2001-01-01T00:00:00Z'],
        ['name' => 'club_catalist_questionnaire', 'props' => ['timing' => 'Budget prévu', 'priority' => 'Conformité RGPD / nLPD']],
        ['name' => 'autre_chose', 'props' => []],
    ]]);
    eq($res['status'], 200);
    $ev = $b->to('/events');
    eq(count($ev), 3);
    eq($ev[0]['body'], ['event_name' => 'club_catalist_service_tried', 'identifiers' => ['email_id' => 'e@x.ch'],
                        'event_properties' => ['service' => 'Fan Data Factory'], 'event_date' => $at]);
    ok(!array_key_exists('event_date', $ev[1]['body']), 'date trop ancienne : omise');
    eq(ksorted($ev[2]['body']['event_properties']), ksorted(['priority' => 'Conformité RGPD / nLPD', 'timing' => 'Budget prévu', 'hot' => true]));
});

test('un événement refusé par Brevo ne fait pas échouer la synchronisation', function () {
    $res = post(relay(new FakeBrevo(failEvents: true)), ['email' => 'e@x.ch',
        'events' => [['name' => 'club_catalist_service_tried', 'props' => ['service' => 'Fan Lab Pro']]]]);
    eq($res['status'], 200);
});

test('appel chaud : une seule alerte par contact', function () {
    $b = new FakeBrevo();
    $r = relay($b);
    $body = ['email' => 'chaud@club.ch', 'org' => 'FC <Test>', 'q' => ['CC_TIMING' => 'Budget prévu']];
    post($r, $body);
    $mails = $b->to('/smtp/email');
    eq(count($mails), 1);
    eq($mails[0]['body']['to'], [['email' => 'sales@example.com']]);
    eq($mails[0]['body']['replyTo'], ['email' => 'chaud@club.ch']);
    ok(str_contains($mails[0]['body']['htmlContent'], 'FC &lt;Test&gt;'), 'organisation échappée dans le HTML');
    ok((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $b->contacts['chaud@club.ch']['attributes']['CC_HOT_AT'] ?? ''), 'CC_HOT_AT daté');
    post($r, $body);
    eq(count($b->to('/smtp/email')), 1, 'pas de seconde alerte');
});

test("appel chaud : si l'alerte échoue, elle sera retentée", function () {
    $b = new FakeBrevo(failSmtp: true);
    $res = post(relay($b), ['email' => 'chaud@club.ch', 'q' => ['CC_TIMING' => "À l'étude"]]);
    eq($res['status'], 200);
    ok(!isset($b->contacts['chaud@club.ch']['attributes']['CC_HOT_AT']), 'CC_HOT_AT reste vide');
});

test('validation : adresse, service et taille', function () {
    $r = relay(new FakeBrevo());
    eq(post($r, ['email' => 'pas-une-adresse'])['status'], 400);
    eq(post($r, ['email' => 'a@b.ch', 'cc' => ['seasons' => 1, 'services' => 'Fan Equity Audit, Piratage']])['status'], 400);
    eq(post($r, ['email' => 'a@b.ch', 'role' => 'Pirate'])['status'], 400);
    eq(post($r, ['email' => 'a@b.ch', 'sport' => 99])['status'], 400);
    eq(post($r, null, raw: '{pas du json')['status'], 400);
    eq(post($r, null, raw: json_encode(['email' => 'a@b.ch', 'pseudo' => str_repeat('x', 20000)]))['status'], 413);
    eq(post($r, ['email' => 'a@b.ch', 'role' => 'Pirate'])['body'], ['ok' => false, 'error' => 'invalid', 'field' => 'role']);
});

test('erreur Brevo : 502, sans écriture', function () {
    $b = new FakeBrevo(getStatus: 401);
    $res = post(relay($b), ['email' => 'a@b.ch']);
    eq($res['status'], 502);
    eq($res['body'], ['ok' => false, 'error' => 'brevo', 'status' => 401]);
    eq(count($b->writes()), 0);
});

test('attributs manquants créés une seule fois', function () {
    $b = new FakeBrevo(attributes: ['PSEUDO']);
    $r = relay($b);
    post($r, ['email' => 'a@b.ch']);
    post($r, ['email' => 'c@d.ch']);
    $created = array_map(fn($c) => basename($c['path']),
        array_values(array_filter($b->calls, fn($c) => str_starts_with($c['path'], '/contacts/attributes/normal/'))));
    sort($created);
    $expected = array_values(array_diff(array_column(Relay::ATTRIBUTES, 0), ['PSEUDO']));
    sort($expected);
    eq($created, $expected);
    eq(count(array_filter($b->calls, fn($c) => $c['path'] === '/contacts/attributes')), 1, 'liste relue une seule fois');
});

test('santé : liste et attributs', function () {
    [$s, , $body] = relay(new FakeBrevo())->handle(['method' => 'GET', 'path' => '/health', 'origin' => ORIGIN, 'ip' => '1']);
    eq($s, 200);
    eq($body, ['ok' => true, 'brevo' => 'ok', 'listId' => 63, 'list' => true,
               'expected' => count(Relay::ATTRIBUTES), 'missing' => [], 'notify' => true]);
});

test('limite de débit respectée, par adresse IP', function () {
    $r = relay(new FakeBrevo(), CFG + ['RATE_LIMIT' => 3]);
    $hit = fn(string $ip) => $r->handle(['method' => 'GET', 'path' => '/health', 'origin' => ORIGIN, 'ip' => $ip])[0];
    eq([$hit('1.1.1.1'), $hit('1.1.1.1'), $hit('1.1.1.1')], [200, 200, 200]);
    eq($hit('1.1.1.1'), 429);
    eq($hit('2.2.2.2'), 200, 'une autre adresse garde son quota');
});

test("l'adresse IP n'est jamais écrite en clair", function () {
    $var = tmpdir();
    relay(new FakeBrevo(), CFG, $var)->handle(['method' => 'GET', 'path' => '/health', 'origin' => ORIGIN, 'ip' => '198.51.100.23']);
    $files = glob("$var/rate/*") ?: [];
    eq(count($files), 1);
    ok(!str_contains(implode('', $files) . file_get_contents($files[0]), '198.51.100.23'), 'IP en clair trouvée');
});

test('clé absente : erreur de configuration explicite', function () {
    [$s, , $body] = relay(new FakeBrevo(), ['NOTIFY_TO' => ''])->handle(['method' => 'GET', 'path' => '/health', 'origin' => ORIGIN, 'ip' => '1']);
    eq([$s, $body], [500, ['ok' => false, 'error' => 'config']]);
});

/* Les valeurs canoniques doivent rester celles du jeu : un libellé changé
   d'un seul côté ferait rejeter tous les joueurs concernés. */
test('valeurs canoniques identiques à jouer/index.html', function () {
    $html = file_get_contents(__DIR__ . '/../../jouer/index.html');
    $block = function (string $name) use ($html): string {
        ok((bool)preg_match('/const ' . $name . ' = ([\[{][\s\S]*?\n?[\]}]);/', $html, $m), "$name introuvable");
        return $m[1];
    };
    preg_match_all('/:\s*"([^"]+)"/', $block('CRM_SERVICES'), $m);
    $services = $m[1]; sort($services);
    $exp = Relay::SERVICES; sort($exp);
    eq($services, $exp, 'CRM_SERVICES');
    preg_match_all('/:\s*"([^"]+)"/', $block('CRM_CERT'), $m);
    ok(str_contains($html, ': "Non audité"'), '« Non audité » attendu dans le jeu');
    $certs = [...$m[1], 'Non audité']; sort($certs);
    $exp = Relay::CERTS; sort($exp);
    eq($certs, $exp, 'CRM_CERT');
    $start = strpos($html, 'const QUALIFY = [');
    $qualify = substr($html, $start, strpos($html, '];', $start) - $start);
    $parts = array_slice(preg_split('/\{ attr: /', $qualify), 1);
    eq(count($parts), count(Relay::QUALIFY), 'nombre de questions');
    foreach ($parts as $part) {
        preg_match('/^"([A-Z_]+)"/', $part, $a);
        preg_match_all('/\["([^"]+)", `/', $part, $o);
        eq($o[1], Relay::QUALIFY[$a[1]], $a[1]);
    }
    preg_match_all('/<option value="([^"]+)" data-i18n="ui\.login\.role\./', $html, $m);
    eq(array_map(fn($v) => str_replace('&amp;', '&', $v), $m[1]), Relay::ROLES, 'rôles');
    preg_match_all('/tutoFinish\("([^"]+)"\)/', $html, $m);
    $tutos = array_values(array_unique($m[1])); sort($tutos);
    $exp = Relay::TUTO; sort($exp);
    eq($tutos, $exp, 'tutoriel');
});

/* ---------------- Point d'entrée réel, via le serveur intégré de PHP ---------------- */

function serve(string $docroot, string $router, int $port): mixed
{
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot, $router];
    $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port);
        if ($s) { fclose($s); return $proc; }
        usleep(100000);
    }
    throw new \Exception("serveur $port injoignable");
}
function http(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = (string)curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'headers' => strtolower(substr($raw, 0, $size)), 'body' => substr($raw, $size)];
}

test('point d\'entrée web : routes, CORS, clé envoyée à Brevo, config hors web', function () {
    $root = tmpdir();
    // Copie du relais avec une config de test pointant vers un faux Brevo
    foreach (['src/Relay.php', 'public/index.php'] as $f) {
        @mkdir(dirname("$root/$f"), 0700, true);
        copy(__DIR__ . "/../$f", "$root/$f");
    }
    mkdir("$root/var");
    $log = "$root/brevo.log";
    file_put_contents("$root/brevo.php", '<?php
        file_put_contents(' . var_export($log, true) . ', json_encode(["m" => $_SERVER["REQUEST_METHOD"], "p" => parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH),
            "k" => $_SERVER["HTTP_API_KEY"] ?? null, "b" => json_decode(file_get_contents("php://input"), true)]) . "\n", FILE_APPEND);
        $p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        header("content-type: application/json");
        if ($p === "/v3/contacts/attributes") { echo json_encode(["attributes" => []]); return; }
        if (str_starts_with($p, "/v3/contacts/attributes/normal/")) { http_response_code(201); echo "{}"; return; }
        if (str_starts_with($p, "/v3/contacts/lists/")) { echo "{\"id\":63}"; return; }
        if ($_SERVER["REQUEST_METHOD"] === "GET" && str_starts_with($p, "/v3/contacts/")) { http_response_code(404); echo "{\"code\":\"document_not_found\"}"; return; }
        if ($p === "/v3/contacts") { http_response_code(201); echo "{\"id\":1}"; return; }
        http_response_code(204);');
    file_put_contents("$root/config.php", '<?php return ' . var_export(CFG + [
        'BREVO_API_BASE' => 'http://127.0.0.1:18766/v3', 'ALLOWED_ORIGINS' => ORIGIN, 'RATE_LIMIT' => 30], true) . ';');

    $brevo = serve($root, "$root/brevo.php", 18766);
    $relay = serve("$root/public", "$root/public/index.php", 18765);
    try {
        $o = ['Origin: ' . ORIGIN];
        $pre = http('OPTIONS', 'http://127.0.0.1:18765/sync', null, $o);
        eq($pre['status'], 204, 'pré-vol CORS');
        ok(str_contains($pre['headers'], 'access-control-allow-origin: ' . ORIGIN), 'en-tête CORS');

        $h = http('GET', 'http://127.0.0.1:18765/health', null, $o);
        eq($h['status'], 200, '/health');
        eq(json_decode($h['body'], true)['brevo'] ?? null, 'ok');
        ok(str_contains($h['headers'], 'application/json'), 'réponse JSON');

        $s = http('POST', 'http://127.0.0.1:18765/sync', json_encode(['email' => 'web@test.ch', 'optIn' => false]),
                  [...$o, 'content-type: application/json']);
        eq([$s['status'], $s['body']], [200, '{"ok":true}'], '/sync');
        $s2 = http('POST', 'http://127.0.0.1:18765/index.php/sync', json_encode(['email' => 'web2@test.ch']),
                   [...$o, 'content-type: application/json']);
        eq($s2['status'], 200, '/index.php/sync (sans réécriture)');

        eq(http('POST', 'http://127.0.0.1:18765/sync', '{}', ['Origin: https://evil.test'])['status'], 403, 'origine refusée');
        eq(http('GET', 'http://127.0.0.1:18765/nope', null, $o)['status'], 404);

        $calls = array_map(fn($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES));
        ok(count($calls) > 0, 'Brevo appelé');
        ok(array_reduce($calls, fn($c, $x) => $c && $x['k'] === 'test-key', true), 'clé API transmise à chaque appel');
        $created = array_values(array_filter($calls, fn($x) => $x['p'] === '/v3/contacts'));
        eq($created[0]['b']['email'] ?? null, 'web@test.ch');
        eq($created[0]['b']['emailBlacklisted'] ?? null, true);

        // La configuration n'est pas sous la racine web
        eq(http('GET', 'http://127.0.0.1:18765/config.php', null, $o)['status'], 404, 'config.php hors web');
    } finally {
        proc_terminate($relay);
        proc_terminate($brevo);
    }
});

echo "\n$count tests, " . ($count - $failures) . " réussis, $failures en échec\n";
exit($failures ? 1 : 0);
