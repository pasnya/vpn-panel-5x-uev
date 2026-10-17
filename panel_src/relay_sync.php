<?php
require_once __DIR__ . '/api/proxy_transport.php';
require_once __DIR__ . '/api/proxy_bundle.php';

$settings = relay_settings();
if (!$settings) exit;

try {
    $db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $db->prepare("SELECT uuid FROM users WHERE id = ? AND role = 'relay' AND status = 'active'");
    $stmt->execute([$settings['user_id'] ?? 0]);
    $uuid = $stmt->fetchColumn();
    if (!$uuid) throw new RuntimeException('Служебный профиль прокладки не найден');
    try {
        $bundle = relay_bundle($db, $uuid);
        $hash = hash('sha256', json_encode($bundle, JSON_THROW_ON_ERROR));
        if (($settings['bundle_hash'] ?? '') !== $hash) {
            relay_call($settings, '/api/configure', $bundle);
            $settings['bundle_hash'] = $hash;
            $file = '/var/www/panel/db/proxy_panel.json';
            $temporary = $file . '.new';
            if (file_put_contents($temporary, json_encode($settings, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить параметры прокладки');
            chmod($temporary, 0600);
            rename($temporary, $file);
        }
    } catch (Throwable $configureError) {
        error_log('Relay reconfigure failed: ' . $configureError->getMessage());
    }
    relay_sync_clients($db, $settings);
} catch (Throwable $error) {
    error_log('Relay sync failed: ' . $error->getMessage());
    exit(1);
}
