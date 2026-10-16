<?php
require_once __DIR__ . '/security.php';
require_admin();
if (!class_exists(ZipArchive::class)) {
    http_response_code(500);
    exit('PHP zip extension unavailable');
}
$tmp = tempnam(sys_get_temp_dir(), 'vpn-backup-');
$snapshot = tempnam(sys_get_temp_dir(), 'vpn-db-');
unlink($snapshot);
try {
    $db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('VACUUM INTO ' . $db->quote($snapshot));
    $db = null;
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create archive');
    $files = [
        'panel.db' => $snapshot,
        'paths.json' => '/var/www/panel/db/paths.json',
        'warp.json' => '/var/www/panel/db/warp.json',
        'xray-config.json' => '/etc/xray/config.json',
        'xray-public.key' => '/etc/xray/public.key',
        'hysteria-config.yaml' => '/etc/hysteria/config.yaml',
        'mita-config.json' => '/etc/mita/config.json',
        'Caddyfile' => '/etc/caddy/Caddyfile',
    ];
    foreach ($files as $name => $path) if (is_file($path)) $zip->addFile($path, $name);
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="vpn-panel-backup-' . gmdate('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
} catch (Throwable $e) {
    error_log('Backup failed: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
} finally {
    @unlink($snapshot);
    @unlink($tmp);
}
