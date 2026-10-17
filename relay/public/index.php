<?php
/* C'mon Sports Challenge — relais CRM : point d'entrée web.
   Seul fichier exposé : la logique (../src) et la clé (../config.php)
   restent hors du dossier public. Routes : POST /sync, GET /health —
   avec ou sans réécriture d'URL (/index.php/sync fonctionne aussi). */

declare(strict_types=1);

require __DIR__ . '/../src/Relay.php';

use CmonSportsChallenge\Relay;

$cfgFile = __DIR__ . '/../config.php';
$cfg = is_file($cfgFile) ? (array)require $cfgFile : [];
// Une variable d'environnement prend le pas sur le fichier, si l'hébergement en fournit une
if (getenv('BREVO_API_KEY')) $cfg['BREVO_API_KEY'] = getenv('BREVO_API_KEY');

// Route : PATH_INFO (/index.php/sync), sinon chemin réécrit (/sync)
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '') {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
}

// Adresse du visiteur : celle de la connexion, sauf en-tête désigné dans config.php
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';
$hdr = (string)($cfg['CLIENT_IP_HEADER'] ?? '');
if ($hdr !== '' && !empty($_SERVER[$hdr])) $ip = trim(explode(',', (string)$_SERVER[$hdr])[0]);

$relay = new Relay($cfg, Relay::curlClient($cfg), __DIR__ . '/../var');
[$status, $headers, $body] = $relay->handle([
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'path'   => $path,
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
    'ip'     => $ip,
    'body'   => ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        ? (string)file_get_contents('php://input', false, null, 0, Relay::MAX_BODY + 1) : '',
]);

http_response_code($status);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
foreach ($headers as $k => $v) header("$k: $v");
if ($body !== null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
