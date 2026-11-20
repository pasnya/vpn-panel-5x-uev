<?php
require_once __DIR__ . '/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_dns'])) {
    require_admin(false);
    header('Content-Type: application/json; charset=utf-8');
    $domain = strtolower(trim((string)($_GET['domain'] ?? '')));
    $naive = strtolower(trim((string)($_GET['naive_sub'] ?? '')));
    $valid = static function (string $name): bool {
        return (bool) preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name);
    };
    if (!$valid($domain) || !$valid($naive) || substr($naive, -strlen('.' . $domain)) !== '.' . $domain) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Укажите корректный домен и поддомен (например, n.site.com).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $paths = json_decode((string)@file_get_contents('/var/www/panel/db/paths.json'), true) ?: [];
    $serverIp = $paths['public_ip'] ?? '';
    if (!filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $serverIp = trim((string)@file_get_contents('/tmp/server_public_ip.txt'));
    }
    if (!filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        echo json_encode(['ok' => false, 'message' => 'Не удалось определить внешний IP сервера для сверки.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $missing = [];
    foreach ([$domain, $naive] as $host) {
        $records = @dns_get_record($host, DNS_A) ?: [];
        $ips = array_column($records, 'ip');
        if (!in_array($serverIp, $ips, true)) {
            $missing[] = $host;
        }
    }
    if (empty($missing)) {
        echo json_encode(['ok' => true, 'message' => "DNS настроен верно: оба имени указывают на {$serverIp}."], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ok' => false,
            'message' => 'Создайте A-запись на IP ' . $serverIp . ' для: ' . implode(', ', $missing) . '.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

require_admin(true);

$domain = strtolower(trim($_POST['domain'] ?? ''));
$naive = strtolower(trim($_POST['naive_sub'] ?? ''));
$decoy = $_POST['decoy'] ?? 'blog';
$force = ($_POST['force'] ?? '') === '1';
$valid = static function (string $name): bool {
    return (bool) preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name);
};
if (!$valid($domain) || !$valid($naive) || substr($naive, -strlen('.' . $domain)) !== '.' . $domain || !in_array($decoy, ['blog', 'portfolio', 'landing'], true)) {
    http_response_code(400);
    exit('Некорректный домен или поддомен');
}

if (!$force) {
    $paths = json_decode((string)@file_get_contents('/var/www/panel/db/paths.json'), true) ?: [];
    $serverIp = $paths['public_ip'] ?? '';
    if (!filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $serverIp = trim((string)@file_get_contents('/tmp/server_public_ip.txt'));
    }
    if (filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $missing = [];
        foreach ([$domain, $naive] as $host) {
            $records = @dns_get_record($host, DNS_A) ?: [];
            $ips = array_column($records, 'ip');
            if (!in_array($serverIp, $ips, true)) {
                $missing[] = $host;
            }
        }
        if (!empty($missing)) {
            header('Location: ../index.php?domain_dns_error=' . urlencode(implode(', ', $missing)));
            exit;
        }
    }
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
