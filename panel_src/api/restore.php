<?php
require_once __DIR__ . '/security.php';
require_admin(true);
if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK || $_FILES['backup']['size'] > 20971520 || !is_uploaded_file($_FILES['backup']['tmp_name'])) {
    http_response_code(400);
    exit('Некорректный файл резервной копии');
}
$zip = new ZipArchive();
if ($zip->open($_FILES['backup']['tmp_name']) !== true) {
    http_response_code(400);
    exit('Не удалось открыть архив');
}
$targets = [
    'panel.db' => '/var/www/panel/db/panel.db',
    'paths.json' => '/var/www/panel/db/paths.json',
    'warp.json' => '/var/www/panel/db/warp.json',
    'xray-config.json' => '/etc/xray/config.json',
    'xray-public.key' => '/etc/xray/public.key',
    'hysteria-config.yaml' => '/etc/hysteria/config.yaml',
    'mita-config.json' => '/etc/mita/config.json',
    'Caddyfile' => '/etc/caddy/Caddyfile',
];
$content = [];
$totalSize = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $totalSize += $stat['size'];
    if (!isset($targets[$stat['name']]) || $stat['size'] > 20971520 || $totalSize > 31457280 || isset($content[$stat['name']])) {
        http_response_code(400);
        exit('Недопустимое содержимое архива');
    }
    $content[$stat['name']] = $zip->getFromIndex($i);
}
$zip->close();
if (!isset($content['panel.db'], $content['paths.json'], $content['xray-config.json'], $content['xray-public.key'])) {
    http_response_code(400);
    exit('Архив не содержит обязательных файлов');
}
$currentPaths = json_decode(file_get_contents($targets['paths.json']), true);
$savedPaths = json_decode($content['paths.json'], true);
if (!is_array($savedPaths) || ($savedPaths['admin_path'] ?? '') !== ($currentPaths['admin_path'] ?? '') || !preg_match('/^[a-f0-9]{8,32}$/', $savedPaths['xhttp_path'] ?? '') || !is_array(json_decode($content['xray-config.json'], true))) {
    http_response_code(400);
    exit('Эта копия создана для другой установки или повреждена');
}
$tmpDb = tempnam('/var/www/panel/db', 'restore-');
file_put_contents($tmpDb, $content['panel.db']);
try {
    $db = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') throw new RuntimeException('Database check failed');
    $db->query('SELECT id FROM users LIMIT 1');
    $db->query('SELECT id FROM domains LIMIT 1');
    foreach ($db->query("SELECT username, uuid FROM users WHERE role = 'client'") as $user) {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $user['username']) || !preg_match('/^[a-f0-9-]{36}$/i', $user['uuid'])) throw new RuntimeException('Invalid client in backup');
    }
    foreach ($db->query('SELECT domain_name, naive_sub FROM domains') as $domain) {
        foreach ([$domain['domain_name'], $domain['naive_sub']] as $name) if (!preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/', $name)) throw new RuntimeException('Invalid domain in backup');
    }
    $db = null;
    $old = [];
    foreach ($content as $name => $bytes) $old[$name] = is_file($targets[$name]) ? file_get_contents($targets[$name]) : null;
    foreach ($content as $name => $bytes) {
        if ($name === 'panel.db') continue;
        if (file_put_contents($targets[$name], $bytes, LOCK_EX) === false) throw new RuntimeException('Failed to write ' . $name);
    }
    if (!rename($tmpDb, $targets['panel.db'])) throw new RuntimeException('Failed to replace database');
    require_once __DIR__ . '/rebuild_configs.php';
    rebuild_configs();
    session_destroy();
    header('Location: ../index.php');
} catch (Throwable $e) {
    error_log('Restore failed: ' . $e->getMessage());
    if (isset($old)) foreach ($old as $name => $bytes) if ($bytes !== null) file_put_contents($targets[$name], $bytes, LOCK_EX);
    http_response_code(500);
    exit('Восстановление не удалось; предыдущие файлы возвращены');
} finally {
    @unlink($tmpDb);
}
