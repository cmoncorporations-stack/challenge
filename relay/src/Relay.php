<?php
/* ═══════════════════════════════════════════════════════════════════════════
   C'mon Sports Challenge — relais CRM (PHP, hébergement Infomaniak)

   Le jeu est un fichier statique : toute clé qu'il contiendrait serait
   publique, et une clé Brevo ouvre le CRM entier. Le relais garde la clé
   (config.php, hors du dossier public) et n'expose qu'une opération :
   inscrire ou mettre à jour un joueur dans la liste C'mon Sports Challenge, avec
   les seuls attributs du jeu, et transmettre ses événements d'usage.

   Trois règles, parce que la liste C'mon Sports Challenge côtoie le reste du CRM :
   — un contact déjà connu garde son statut d'envoi. Le relais ne touche
     jamais à la liste noire d'un contact existant : un prospect qui jouait
     sans cocher la case ne doit pas être exclu de TOUTES les campagnes ;
   — les champs partagés (COMPANY, JOB_TITLE, SPORT) ne sont remplis que
     s'ils sont vides, et OPT_IN ne peut que passer à vrai ;
   — la réponse est la même que l'adresse soit connue ou non : le relais
     ne doit pas servir d'annuaire du CRM.

   Rien ici ne dépend du serveur web : handle() reçoit une requête décrite
   par un tableau et rend un tableau, ce qui permet de tout tester sans
   Apache ni Brevo (tests/run.php).
   ═══════════════════════════════════════════════════════════════════════════ */

declare(strict_types=1);

namespace CmonSportsChallenge;

final class Invalid extends \RuntimeException {}
final class BrevoError extends \RuntimeException {
    public function __construct(public readonly int $status) { parent::__construct("brevo $status"); }
}

final class Relay
{
    public const DEFAULT_ORIGINS = 'https://challenge.cmon-sports.com';
    public const MAX_BODY = 16384;
    public const MAX_EVENTS = 20;
    public const EVENT_MAX_AGE = 90 * 24 * 3600;

    /* Valeurs canoniques — miroir de jouer/index.html (CRM_SERVICES, CRM_CERT,
       QUALIFY et rôles du formulaire). tests/run.php vérifie qu'elles
       concordent : un écart ferait rejeter les joueurs en HTTP 400. */
    public const SERVICES = ['Fan Equity Audit', 'Fan Lab Insight', 'Fan Lab Pro', 'Fan Data Factory', 'Blue Penguin', 'Griddy Picks'];
    public const CERTS = ['Gold', 'Silver', 'Audité non certifié', 'Non audité'];
    public const ROLES = ['Direction générale', 'Marketing & communication', 'Sponsoring & commercial', 'Data & billetterie', 'Autre'];
    public const QUALIFY = [
        'CC_MATURITY' => ['Pas de base de données fans', 'Base peu exploitée', 'CRM et segmentation actifs', 'Hors club'],
        'CC_PRIORITY' => ['Collecte et qualification de données', 'Valorisation fanbase / sponsors',
                          'Structuration marketing digital', 'Conformité RGPD / nLPD', 'Aucune, découverte'],
        'CC_TIMING'   => ['Budget prévu', "À l'étude", 'Pas maintenant'],
    ];
    public const HOT_TIMING = ['Budget prévu', "À l'étude"];
    public const TUTO = ['Terminé', 'Passé'];
    // Catégorie Brevo SPORT : basket 1, foot 2, hockey 4, volley 6
    public const SPORTS = [1 => 'Basketball', 2 => 'Football', 4 => 'Hockey', 6 => 'Volleyball'];
    public const LANGS = ['fr', 'de', 'en'];

    /* Attributs propres au jeu, créés au besoin. Préfixe CC_ pour ne jamais
       heurter les attributs métier du CRM ; PSEUDO plutôt que PRENOM, réservé
       au vrai prénom ; CC_LANG plutôt que LANGUE, une catégorie à valeurs
       figées où un texte libre vaudrait un HTTP 400. */
    public const ATTRIBUTES = [
        ['CMON_SOLUTION_CHALLENGE', 'boolean'], ['PSEUDO', 'text'],
        ['CC_SEASONS', 'float'], ['CC_SERVICES', 'text'], ['CC_CERT', 'text'],
        ['CC_LAST_PLAYED', 'date'], ['CC_LANG', 'text'],
        ['CC_MATURITY', 'text'], ['CC_PRIORITY', 'text'], ['CC_TIMING', 'text'],
        ['CC_TUTO', 'text'],
        // Date de la première alerte « appel chaud » : une seule alerte par contact
        ['CC_HOT_AT', 'date'],
    ];

    /** @var callable(string,string,mixed):array{ok:bool,status:int,data:mixed} */
    private $http;

    /**
     * @param array    $cfg  contenu de config.php
     * @param callable $http appel Brevo : fn(méthode, chemin, corps) → [ok, status, data]
     * @param string   $var  dossier inscriptible hors du web (débit, cache des attributs)
     */
    public function __construct(private array $cfg, callable $http, private string $var)
    {
        $this->http = $http;
    }

    /* ================= Point d'entrée ================= */

    /**
     * @param array $req [method, path, origin, ip, body]
     * @return array [status, headers, body]  body : tableau encodé en JSON, ou null
     */
    public function handle(array $req): array
    {
        $method = strtoupper($req['method'] ?? 'GET');
        $origin = (string)($req['origin'] ?? '');
        $allowed = array_values(array_filter(array_map('trim',
            explode(',', (string)($this->cfg['ALLOWED_ORIGINS'] ?? self::DEFAULT_ORIGINS)))));
        $cors = in_array($origin, $allowed, true) ? ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'] : null;

        if ($method === 'OPTIONS') {
            if (!$cors) return [403, [], null];
            return [204, $cors + [
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'content-type',
                'Access-Control-Max-Age' => '86400',
            ], null];
        }
        // Garde-fou de navigateur, pas une authentification : un script peut
        // forger l'en-tête Origin. La limite de débit et la validation stricte
        // bornent ce qu'il pourrait faire.
        if (!$cors) return [403, [], ['ok' => false, 'error' => 'origin']];

        if (!$this->allow((string)($req['ip'] ?? '?'))) return [429, $cors, ['ok' => false, 'error' => 'rate']];
        if (empty($this->cfg['BREVO_API_KEY'])) return [500, $cors, ['ok' => false, 'error' => 'config']];

        $path = '/' . trim((string)($req['path'] ?? ''), '/');
        try {
            if ($path === '/health' && $method === 'GET') return [200, $cors, $this->health()];
            if ($path === '/sync' && $method === 'POST') {
                $text = (string)($req['body'] ?? '');
                if (strlen($text) > self::MAX_BODY) return [413, $cors, ['ok' => false, 'error' => 'too_large']];
                try {
                    $body = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new Invalid('json');
                }
                $this->syncPlayer($this->parsePlayer($body));
                return [200, $cors, ['ok' => true]];
            }
            return [404, $cors, ['ok' => false, 'error' => 'not_found']];
        } catch (Invalid $e) {
            return [400, $cors, ['ok' => false, 'error' => 'invalid', 'field' => $e->getMessage()]];
        } catch (BrevoError $e) {
            return [502, $cors, ['ok' => false, 'error' => 'brevo', 'status' => $e->status]];
        } catch (\Throwable $e) {
            error_log("relais C'mon Sports Challenge : erreur inattendue — " . $e->getMessage());
            return [500, $cors, ['ok' => false, 'error' => 'internal']];
        }
    }

    /* ================= Limite de débit =================
       30 requêtes par minute et par adresse IP : largement assez pour un
       joueur, trop peu pour remplir le CRM d'adresses inventées. L'adresse
       n'est jamais écrite en clair : seul un condensé salé nomme le
       compteur, effacé dès que sa minute est passée. */
    private function allow(string $ip): bool
    {
        $limit = (int)($this->cfg['RATE_LIMIT'] ?? 30);
        if ($limit <= 0) return true;
        $dir = $this->var . '/rate';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return true;   // non bloquant
        $window = intdiv(time(), 60);
        $salt = hash('sha256', 'cc-rate|' . ($this->cfg['BREVO_API_KEY'] ?? ''));
        $file = $dir . '/' . hash_hmac('sha256', $ip, $salt) . '-' . $window;

        $fh = @fopen($file, 'c+');
        if (!$fh) return true;
        flock($fh, LOCK_EX);
        $n = (int)stream_get_contents($fh) + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string)$n);
        flock($fh, LOCK_UN);
        fclose($fh);

        // Ménage des minutes écoulées, une fois de temps en temps
        if (random_int(1, 50) === 1) {
            foreach (glob($dir . '/*-*') ?: [] as $f) {
                if ((int)substr($f, strrpos($f, '-') + 1) < $window) @unlink($f);
            }
        }
        return $n <= $limit;
    }

    /* ================= Validation de ce qu'envoie le jeu ================= */

    // Texte libre : coupé, et sans caractères de contrôle (il finit dans un objet d'e-mail).
    private static function str(mixed $v, int $max): string
    {
        if (!is_string($v)) return '';
        $v = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    private static function oneOf(mixed $v, array $list, string $field): ?string
    {
        if ($v === null || $v === '') return null;
        if (!is_string($v) || !in_array($v, $list, true)) throw new Invalid($field);
        return $v;
    }

    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    private static function eventProps(string $name, array $p): ?array
    {
        switch ($name) {
            case 'club_catalist_service_tried':
                return in_array($p['service'] ?? null, self::SERVICES, true) ? ['service' => $p['service']] : null;
            case 'club_catalist_season_completed':
                $s = $p['seasons'] ?? null;
                if (!is_int($s) || $s <= 0 || $s >= 1000) return null;
                $out = ['seasons' => $s];
                if (in_array($p['cert'] ?? null, self::CERTS, true)) $out['cert'] = $p['cert'];
                return $out;
            case 'club_catalist_questionnaire':
                $out = [];
                foreach (['maturity' => 'CC_MATURITY', 'priority' => 'CC_PRIORITY', 'timing' => 'CC_TIMING'] as $k => $attr) {
                    if (in_array($p[$k] ?? null, self::QUALIFY[$attr], true)) $out[$k] = $p[$k];
                }
                if (isset($out['timing'])) $out['hot'] = in_array($out['timing'], self::HOT_TIMING, true);
                return $out ?: null;
        }
        return null;
    }

    private static function parseEvent(mixed $e, int $now): ?array
    {
        if (!self::isObject($e) || !is_string($e['name'] ?? null)) return null;
        $props = self::eventProps($e['name'], self::isObject($e['props'] ?? null) ? $e['props'] : []);
        if ($props === null) return null;
        $ev = ['name' => $e['name'], 'props' => $props];
        if (is_string($e['at'] ?? null)) {
            try {
                $t = new \DateTimeImmutable($e['at']);
                $ts = $t->getTimestamp();
                if ($ts <= $now + 300 && $ts >= $now - self::EVENT_MAX_AGE) {
                    $ev['at'] = $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
                }
            } catch (\Exception) { /* date illisible : omise */ }
        }
        return $ev;
    }

    public function parsePlayer(mixed $b, ?int $now = null): array
    {
        $now ??= time();
        if (!self::isObject($b)) throw new Invalid('body');
        $email = mb_strtolower(self::str($b['email'] ?? null, 254));
        if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', $email)) throw new Invalid('email');

        $sport = null;
        $s = $b['sport'] ?? null;
        if ($s !== null && $s !== '') {
            $n = is_int($s) ? $s : (is_string($s) && ctype_digit($s) ? (int)$s : -1);
            if (!isset(self::SPORTS[$n])) throw new Invalid('sport');
            $sport = $n;
        }
        $p = [
            'email'  => $email,
            'optIn'  => ($b['optIn'] ?? null) === true,
            'pseudo' => self::str($b['pseudo'] ?? null, 40) ?: explode('@', $email)[0],
            'org'    => self::str($b['org'] ?? null, 60),
            'role'   => self::oneOf($b['role'] ?? null, self::ROLES, 'role'),
            'sport'  => $sport,
            'lang'   => self::oneOf($b['lang'] ?? null, self::LANGS, 'lang') ?? 'fr',
            'tuto'   => self::oneOf($b['tuto'] ?? null, self::TUTO, 'tuto'),
            'q'      => [],
            'cc'     => null,
            'events' => [],
        ];
        if (($b['q'] ?? null) !== null) {
            if (!self::isObject($b['q'])) throw new Invalid('q');
            foreach (self::QUALIFY as $k => $list) {
                $v = self::oneOf($b['q'][$k] ?? null, $list, $k);
                if ($v !== null) $p['q'][$k] = $v;
            }
        }
        if (($b['cc'] ?? null) !== null) {
            $c = $b['cc'];
            if (!self::isObject($c)) throw new Invalid('cc');
            $seasons = $c['seasons'] ?? null;
            if (is_string($seasons) && ctype_digit($seasons)) $seasons = (int)$seasons;
            if (is_float($seasons) && floor($seasons) === $seasons) $seasons = (int)$seasons;
            if (!is_int($seasons) || $seasons < 0 || $seasons > 999) throw new Invalid('cc.seasons');
            $services = self::str($c['services'] ?? null, 200);
            if ($services !== '') {
                foreach (explode(', ', $services) as $sv) {
                    if (!in_array($sv, self::SERVICES, true)) throw new Invalid('cc.services');
                }
            }
            $last = $c['lastPlayed'] ?? null;
            $p['cc'] = [
                'seasons'    => $seasons,
                'services'   => $services,
                'cert'       => self::oneOf($c['cert'] ?? null, self::CERTS, 'cc.cert'),
                'lastPlayed' => is_string($last) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $last) ? $last : null,
            ];
        }
        if (($b['events'] ?? null) !== null) {
            if (!is_array($b['events']) || !array_is_list($b['events'])) throw new Invalid('events');
            // Un événement inconnu ou mal formé est ignoré plutôt que de bloquer
            // le contact : une ancienne version du jeu ne doit pas rester en échec.
            foreach (array_slice($b['events'], 0, self::MAX_EVENTS) as $e) {
                $ev = self::parseEvent($e, $now);
                if ($ev) $p['events'][] = $ev;
            }
        }
        return $p;
    }

    /* ================= Brevo ================= */

    private function call(string $method, string $path, mixed $body = null): array
    {
        return ($this->http)($method, $path, $body);
    }

    private function listId(): int
    {
        return (int)($this->cfg['BREVO_LIST_ID'] ?? 63);
    }

    /* Un segment Brevo ne peut porter que sur un attribut existant : on crée
       ceux du jeu au premier passage. Un fichier témoin évite de relire la
       liste des attributs à chaque requête ; il expire au bout d'un jour, et
       son nom dépend de la liste attendue : un attribut ajouté ou renommé
       dans une nouvelle version du relais est donc créé dès la requête suivante. */
    private function ensureAttributes(): void
    {
        $flag = $this->var . '/attributes-' . substr(md5(json_encode(self::ATTRIBUTES)), 0, 12) . '.ok';
        if (is_file($flag) && filemtime($flag) > time() - 86400) return;
        $r = $this->call('GET', '/contacts/attributes');
        if (!$r['ok']) return;   // non bloquant : l'écriture du contact dira ce qui manque
        $known = array_column($r['data']['attributes'] ?? [], 'name');
        $allOk = true;
        foreach (self::ATTRIBUTES as [$name, $type]) {
            if (in_array($name, $known, true)) continue;
            $c = $this->call('POST', "/contacts/attributes/normal/$name", ['type' => $type]);
            // 400 = déjà créé entre-temps par une autre requête : ça convient aussi
            if (!$c['ok'] && $c['status'] !== 400) $allOk = false;
        }
        if ($allOk) @touch($flag);
    }

    private static function esc(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /* Alerte interne « appel chaud » : le joueur annonce un budget prévu ou
       un projet à l'étude. Envoyée une seule fois par contact (CC_HOT_AT). */
    private function notifyHot(array $p): bool
    {
        $to = array_values(array_filter(array_map('trim', explode(',', (string)($this->cfg['NOTIFY_TO'] ?? '')))));
        if (!$to) return false;
        $cc = $p['cc'] ?? [];
        $rows = array_filter([
            ['E-mail', $p['email']], ['Pseudo', $p['pseudo']], ['Organisation', $p['org']], ['Rôle', $p['role']],
            ['Sport', $p['sport'] ? self::SPORTS[$p['sport']] : null], ['Maturité', $p['q']['CC_MATURITY'] ?? null],
            ['Priorité', $p['q']['CC_PRIORITY'] ?? null], ['Projet', $p['q']['CC_TIMING'] ?? null],
            ['Services essayés', $cc['services'] ?? null], ['Saisons terminées', $cc['seasons'] ?? null],
            ['Certification', $cc['cert'] ?? null], ['Actualités par e-mail', $p['optIn'] ? 'oui' : 'non'],
        ], fn($r) => $r[1] !== null && $r[1] !== '');
        $table = implode('', array_map(fn($r) =>
            '<tr><th align="left">' . self::esc($r[0]) . '</th><td>' . self::esc($r[1]) . '</td></tr>', $rows));
        $html = "<h2>C'mon Sports Challenge — appel chaud</h2>\n<p>Un joueur vient d'annoncer un projet dans le questionnaire de positionnement.\n"
              . "Répondre à cet e-mail lui écrit directement.</p>\n<table cellpadding=\"4\">$table</table>";
        $r = $this->call('POST', '/smtp/email', [
            'sender'      => ['name' => "C'mon Sports Challenge", 'email' => $this->cfg['NOTIFY_SENDER'] ?? 'noreply@cmon-sports.com'],
            'to'          => array_map(fn($e) => ['email' => $e], $to),
            'replyTo'     => ['email' => $p['email']],
            'subject'     => "C'mon Sports Challenge — appel chaud : " . ($p['org'] ?: $p['email']),
            'htmlContent' => $html,
            'tags'        => ['cmon-sports-challenge', 'hot-lead'],
        ]);
        if (!$r['ok']) error_log("relais C'mon Sports Challenge : alerte appel chaud refusée par Brevo (" . $r['status'] . ')');
        return $r['ok'];
    }

    public function syncPlayer(array $p): void
    {
        $listId = $this->listId();
        $this->ensureAttributes();
        $path = '/contacts/' . rawurlencode($p['email']);

        $found = $this->call('GET', $path);
        if (!$found['ok'] && $found['status'] !== 404) throw new BrevoError($found['status']);
        $existing = $found['ok'] ? ($found['data'] ?? []) : null;
        $prev = is_array($existing) ? ($existing['attributes'] ?? []) : [];

        // Attributs du jeu : toujours à jour
        $attrs = ['CMON_SOLUTION_CHALLENGE' => true, 'PSEUDO' => $p['pseudo'], 'CC_LANG' => $p['lang']];
        if ($p['tuto']) $attrs['CC_TUTO'] = $p['tuto'];
        $attrs += $p['q'];
        if ($p['cc']) {
            $attrs['CC_SEASONS'] = $p['cc']['seasons'];
            $attrs['CC_SERVICES'] = $p['cc']['services'];
            if ($p['cc']['cert']) $attrs['CC_CERT'] = $p['cc']['cert'];
            if ($p['cc']['lastPlayed']) $attrs['CC_LAST_PLAYED'] = $p['cc']['lastPlayed'];
        }
        // Champs partagés avec le reste du CRM : complétés, jamais écrasés
        $empty = fn(string $k) => !isset($prev[$k]) || $prev[$k] === '';
        if ($p['org'] !== '' && $empty('COMPANY')) $attrs['COMPANY'] = $p['org'];
        if ($p['role'] && $empty('JOB_TITLE')) $attrs['JOB_TITLE'] = $p['role'];
        if ($p['sport'] && $empty('SPORT')) $attrs['SPORT'] = $p['sport'];
        if ($existing === null) $attrs['OPT_IN'] = $p['optIn'];
        elseif ($p['optIn'] && ($prev['OPT_IN'] ?? null) !== true) $attrs['OPT_IN'] = true;

        // L'alerte part avant l'écriture : si elle échoue, CC_HOT_AT reste vide
        // et la prochaine synchronisation la retente.
        if (in_array($p['q']['CC_TIMING'] ?? null, self::HOT_TIMING, true) && $empty('CC_HOT_AT')) {
            if ($this->notifyHot($p)) $attrs['CC_HOT_AT'] = gmdate('Y-m-d');
        }

        $update = function () use ($path, $attrs, $listId) {
            $u = $this->call('PUT', $path, ['attributes' => $attrs, 'listIds' => [$listId]]);
            if (!$u['ok']) throw new BrevoError($u['status']);
        };
        if ($existing !== null) {
            $update();
        } else {
            // Nouveau contact : sans consentement, il entre au CRM exclu des campagnes.
            $c = $this->call('POST', '/contacts', [
                'email' => $p['email'], 'listIds' => [$listId], 'emailBlacklisted' => !$p['optIn'], 'attributes' => $attrs,
            ]);
            if (!$c['ok']) {
                // Créé entre la lecture et l'écriture (deux onglets) : simple mise à jour
                if ($c['status'] === 400 && ($c['data']['code'] ?? null) === 'duplicate_parameter') $update();
                else throw new BrevoError($c['status']);
            }
        }

        // Événements : au mieux. Les attributs portent déjà l'état durable ; un
        // événement refusé est journalisé, pas renvoyé au jeu en boucle.
        $refused = [];
        foreach ($p['events'] as $e) {
            $body = ['event_name' => $e['name'], 'identifiers' => ['email_id' => $p['email']], 'event_properties' => $e['props']];
            if (isset($e['at'])) $body['event_date'] = $e['at'];
            $r = $this->call('POST', '/events', $body);
            if (!$r['ok']) $refused[] = $r['status'];
        }
        if ($refused) error_log("relais C'mon Sports Challenge : événements refusés par Brevo (" . implode(', ', $refused) . ')');
    }

    public function health(): array
    {
        $listId = $this->listId();
        $list = $this->call('GET', "/contacts/lists/$listId");
        $attrs = $this->call('GET', '/contacts/attributes');
        $known = $attrs['ok'] ? array_column($attrs['data']['attributes'] ?? [], 'name') : [];
        return [
            'ok'       => $list['ok'] && $attrs['ok'],
            'brevo'    => $attrs['ok'] ? 'ok' : 'http ' . $attrs['status'],
            'listId'   => $listId,
            'list'     => $list['ok'],
            'expected' => count(self::ATTRIBUTES),
            'missing'  => $attrs['ok']
                ? array_values(array_diff(array_column(self::ATTRIBUTES, 0), $known))
                : null,
            'notify'   => trim((string)($this->cfg['NOTIFY_TO'] ?? '')) !== '',
        ];
    }

    /* ================= Appel HTTP réel vers Brevo (cURL) ================= */

    public static function curlClient(array $cfg): callable
    {
        $base = rtrim((string)($cfg['BREVO_API_BASE'] ?? 'https://api.brevo.com/v3'), '/');
        $key = (string)($cfg['BREVO_API_KEY'] ?? '');
        return function (string $method, string $path, mixed $body = null) use ($base, $key): array {
            $ch = curl_init($base . $path);
            $headers = ['api-key: ' . $key, 'accept: application/json'];
            $opts = [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
            ];
            if ($body !== null) {
                $headers[] = 'content-type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);
            $txt = curl_exec($ch);
            $status = $txt === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($txt === false) throw new BrevoError(0);   // Brevo injoignable
            $data = $txt !== '' ? json_decode($txt, true) : null;
            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data];
        };
    }
}
