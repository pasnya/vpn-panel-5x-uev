<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9-]{36}$/i', $token)) {
    http_response_code(404);
    exit;
}
$db = new PDO('sqlite:/var/www/panel/db/panel.db');
$stmt = $db->prepare("SELECT u.username, u.uuid, d.domain_name, d.naive_sub FROM users u LEFT JOIN client_credentials cc ON cc.user_id = u.id LEFT JOIN domains d ON d.id = cc.domain_id AND d.ssl_status = 'active' WHERE u.uuid = ? AND u.role = 'client' AND u.status = 'active' AND (u.expires_at IS NULL OR u.expires_at >= datetime('now')) LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    exit;
}
$paths = json_decode(@file_get_contents('/var/www/panel/db/paths.json'), true) ?: [];
$server = trim(@file_get_contents('/tmp/server_public_ip.txt') ?: '');
if (!filter_var($server, FILTER_VALIDATE_IP)) $server = $user['domain_name'] ?: '';
if (!$server) {
    http_response_code(503);
    exit('Public server address unavailable');
}
$xray = json_decode(@file_get_contents('/etc/xray/config.json'), true) ?: [];
$sni = 'yahoo.com';
$sid = '';
foreach ($xray['inbounds'] ?? [] as $inbound) {
    if (isset($inbound['streamSettings']['realitySettings'])) {
        $sni = $inbound['streamSettings']['realitySettings']['serverNames'][0] ?? $sni;
        $sid = $inbound['streamSettings']['realitySettings']['shortIds'][0] ?? '';
    }
}
$publicKey = trim(@file_get_contents('/etc/xray/public.key') ?: '');
$uuid = $user['uuid'];
$name = rawurlencode($user['username']);
$links = ["vless://{$uuid}@{$server}:443?security=reality&sni=" . rawurlencode($sni) . "&pbk=" . rawurlencode($publicKey) . "&fp=chrome&type=tcp&flow=xtls-rprx-vision&sid=" . rawurlencode($sid) . "#Reality-{$name}"];
if ($user['domain_name']) {
    $domain = $user['domain_name'];
    $path = '/' . trim($paths['xhttp_path'] ?? 'xhttp', '/') . '/';
    $links[] = "vless://{$uuid}@{$server}:443?security=tls&sni={$domain}&fp=chrome&type=xhttp&host={$domain}&path=" . rawurlencode($path) . "&alpn=h2#XHTTP-{$name}";
    $links[] = "naive+https://{$name}:{$uuid}@{$user['naive_sub']}:443#Naive-{$name}";
    $hysteria = @file_get_contents('/etc/hysteria/config.yaml') ?: '';
    $obfs = preg_match('/password:\s*"([^"]+)"/', $hysteria, $match) ? $match[1] : '';
    $links[] = "hysteria2://{$uuid}@{$server}:443?obfs-password=" . rawurlencode($obfs) . "&security=tls&sni={$domain}#Hysteria-{$name}";
    $mieruPassword = substr(str_replace('-', '', $uuid), 0, 16);
    $links[] = "mieru://{$name}:{$mieruPassword}@{$server}:443?transport=TCP&multiplexing=MULTIPLEXING_LOW#Mieru-{$name}";
}
echo base64_encode(implode("\n", $links) . "\n");
