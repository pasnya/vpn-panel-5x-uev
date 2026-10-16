<?php
$lock = fopen('/tmp/vpn-panel-cert.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
$db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("UPDATE domains SET ssl_status = 'requesting' WHERE ssl_status = 'issuing'");
$domains = $db->query("SELECT id, domain_name, naive_sub FROM domains WHERE ssl_status = 'requesting'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($domains as $domain) {
    $db->prepare("UPDATE domains SET ssl_status = 'issuing' WHERE id = ?")->execute([$domain['id']]);
    $name = $domain['domain_name'];
    $naive = $domain['naive_sub'];
    $cmd = '/usr/bin/sudo /usr/local/sbin/vpn-certbot-helper ' . escapeshellarg($name) . ' ' . escapeshellarg($naive) . ' 2>&1';
    $output = [];
    exec($cmd, $output, $code);
    if ($code !== 0 || !is_file('/etc/letsencrypt/live/' . $name . '/fullchain.pem')) {
        error_log('Certificate issue failed for ' . $name . ': ' . implode("\n", $output));
        $db->prepare("UPDATE domains SET ssl_status = 'error' WHERE id = ?")->execute([$domain['id']]);
        continue;
    }
    $decoy = '/var/www/decoy/' . $name;
    if (!is_dir($decoy)) mkdir($decoy, 0755, true);
    if (!is_file($decoy . '/index.html')) file_put_contents($decoy . '/index.html', '<h1>Welcome</h1>');
    $db->prepare("UPDATE domains SET ssl_status = 'active' WHERE id = ?")->execute([$domain['id']]);
    require_once __DIR__ . '/api/rebuild_configs.php';
    try {
        rebuild_configs();
    } catch (Throwable $e) {
        error_log('Domain activation failed: ' . $e->getMessage());
        $db->prepare("UPDATE domains SET ssl_status = 'error' WHERE id = ?")->execute([$domain['id']]);
    }
}
