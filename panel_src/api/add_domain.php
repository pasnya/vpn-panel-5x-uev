<?php
require_once __DIR__ . '/security.php';
require_admin(true);

$domain = strtolower(trim($_POST['domain'] ?? ''));
$naive = strtolower(trim($_POST['naive_sub'] ?? ''));
$decoy = $_POST['decoy'] ?? 'blog';
$valid = static function (string $name): bool {
    return (bool) preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name);
};
if (!$valid($domain) || !$valid($naive) || substr($naive, -strlen('.' . $domain)) !== '.' . $domain || !in_array($decoy, ['blog', 'portfolio', 'landing'], true)) {
    http_response_code(400);
    exit('Некорректный домен или поддомен');
}

try {
    $db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $paths = json_decode(file_get_contents('/var/www/panel/db/paths.json'), true) ?: [];
    $stmt = $db->prepare("INSERT INTO domains (domain_name, naive_sub, decoy_type, ssl_status, admin_path) VALUES (?, ?, ?, 'requesting', ?) ON CONFLICT(domain_name) DO UPDATE SET naive_sub=excluded.naive_sub, decoy_type=excluded.decoy_type, ssl_status='requesting'");
    $stmt->execute([$domain, $naive, $decoy, $paths['admin_path'] ?? 'admin']);
    header('Location: ../index.php?domain_pending=1');
} catch (Throwable $e) {
    error_log('Domain request failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Не удалось добавить домен');
}
