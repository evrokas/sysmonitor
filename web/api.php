<?php
// Entry point: expose this directory (only) through the web server, over HTTPS.
require __DIR__ . '/../src/bootstrap.php';

$cfg = Sysmon\Config::load(null);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($cfg->get('trust_proxy_headers') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
[$code, $body] = (new Sysmon\Api($cfg))->handle($_GET, function_exists('getallheaders') ? getallheaders() : [
    'Authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
], $https);
http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
