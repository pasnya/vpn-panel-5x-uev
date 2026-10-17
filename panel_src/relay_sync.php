<?php
require_once __DIR__ . '/api/proxy_transport.php';

$settings = relay_settings();
if (!$settings) exit;

try {
    $db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    relay_sync_clients($db, $settings);
} catch (Throwable $error) {
    error_log('Relay sync failed: ' . $error->getMessage());
    exit(1);
}
