<?php
declare(strict_types=1);

function relay_settings(): ?array {
    $file = '/var/www/panel/db/proxy_panel.json';
    if (!is_file($file)) return null;
    $value = json_decode((string)file_get_contents($file), true);
    return is_array($value) ? $value : null;
}

function relay_call(array $settings, string $path, ?array $body = null): array {
    $url = 'https://' . $settings['host'] . ':' . $settings['port'] . $path;
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $settings['token'], 'Accept: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $path === '/api/client' ? 120 : 45,
        CURLOPT_HTTPHEADER => $headers,
        // Сертификат прокладки самоподписанный. Закреплённый публичный ключ
        // из строки установки обязательно проверяется cURL.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_PINNEDPUBLICKEY => $settings['pin'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Не удалось связаться с прокладкой: ' . $error);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Прокладка вернула неверный ответ');
    if ($code < 200 || $code >= 300) throw new RuntimeException('Прокладка: ' . ($data['error'] ?? 'ошибка соединения'));
    return $data;
}

function relay_sync_clients(PDO $db, array $settings): void {
    $users = $db->query("SELECT username, status, expires_at, traffic_limit, traffic_used FROM users WHERE role = 'client'")->fetchAll(PDO::FETCH_ASSOC);
    $clients = [];
    foreach ($users as $user) {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,48}$/', $user['username'])) continue;
        $expiry = $user['expires_at'] ? strtotime($user['expires_at']) : null;
        $enabled = $user['status'] === 'active'
            && ($expiry === null || $expiry >= time())
            && ((int)$user['traffic_limit'] <= 0 || (int)$user['traffic_used'] < (int)$user['traffic_limit']);
        $clients[] = ['name' => $user['username'], 'enabled' => $enabled, 'expiresAt' => $expiry];
    }
    relay_call($settings, '/api/sync', ['clients' => $clients]);
}
