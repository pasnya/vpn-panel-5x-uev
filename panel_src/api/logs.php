<?php
require_once __DIR__ . '/security.php';
require_admin();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$service = $_GET['service'] ?? '';
if (!in_array($service, ['nginx', 'caddy', 'xray', 'mita', 'hysteria-server'], true)) {
    http_response_code(400);
    exit('Invalid service');
}
$cmd = '/usr/bin/sudo /usr/bin/journalctl -u ' . $service . ' -n 100 --no-pager -o short-iso 2>&1';
exec($cmd, $lines, $code);
if ($code !== 0) http_response_code(500);
echo implode("\n", $lines);
