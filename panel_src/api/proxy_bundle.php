<?php
declare(strict_types=1);

function relay_main_ip(): string {
    $cached = trim((string)@file_get_contents('/tmp/server_public_ip.txt'));
    if (filter_var($cached, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $cached;
    $ch = curl_init('https://api.ipify.org');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $ip = trim((string)curl_exec($ch));
    curl_close($ch);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('Не удалось определить IP основного сервера');
    return $ip;
}

function relay_bundle(PDO $db, string $uuid): array {
    $key = trim((string)@file_get_contents('/etc/xray/public.key'));
    if ($key === '') throw new RuntimeException('Не найден публичный ключ Reality');
    $xray = json_decode((string)@file_get_contents('/etc/xray/config.json'), true);
    $sni = '';
    $sid = '';
    foreach (($xray['inbounds'] ?? []) as $inbound) {
        if (($inbound['port'] ?? null) === 10002) {
            $reality = $inbound['streamSettings']['realitySettings'] ?? [];
            $sni = $reality['serverNames'][0] ?? '';
            $sid = $reality['shortIds'][0] ?? '';
            break;
        }
    }
    if ($sni === '') throw new RuntimeException('Не найдены параметры Reality');
    $domain = $db->query("SELECT domain_name FROM domains WHERE ssl_status = 'active' ORDER BY id LIMIT 1")->fetchColumn() ?: '';
    $paths = json_decode((string)@file_get_contents('/var/www/panel/db/paths.json'), true);
    return [
        'serverIp' => relay_main_ip(), 'uuid' => $uuid, 'publicKey' => $key,
        'realitySni' => $sni, 'realitySid' => $sid,
        'domain' => $domain, 'xhttpPath' => trim((string)($paths['xhttp_path'] ?? ''), '/'),
    ];
}
