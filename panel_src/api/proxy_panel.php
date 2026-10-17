<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/proxy_transport.php';
require_once __DIR__ . '/proxy_bundle.php';
require_admin($_SERVER['REQUEST_METHOD'] === 'POST');

$settingsFile = '/var/www/panel/db/proxy_panel.json';
$action = $_POST['action'] ?? $_GET['action'] ?? 'status';

function relay_pair_code(string $code): array {
    $raw = base64_decode(strtr(trim($code), '-_', '+/'), true);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) throw new InvalidArgumentException('Неверная строка подключения');
    $host = $data['host'] ?? '';
    $port = $data['port'] ?? null;
    $token = $data['token'] ?? '';
    $pin = $data['pin'] ?? '';
    if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        || $port !== 8443 || !preg_match('/^[a-f0-9]{64}$/', $token)
        || !preg_match('~^sha256//[A-Za-z0-9+/]{43}=$~', $pin)) {
        throw new InvalidArgumentException('Строка подключения содержит неверный адрес или ключ');
    }
    return ['host' => $host, 'port' => $port, 'token' => $token, 'pin' => $pin];
}

try {
    if ($action === 'status') {
        $settings = relay_settings();
        if (!$settings) $result = ['connected' => false];
        else {
            try { $result = ['connected' => true, 'host' => $settings['host'], 'status' => relay_call($settings, '/api/status')]; }
            catch (Throwable $error) { $result = ['connected' => true, 'host' => $settings['host'], 'error' => $error->getMessage()]; }
        }
    } elseif ($action === 'connect') {
        if (relay_settings()) throw new RuntimeException('Прокладка уже подключена. Сначала отключите её.');
        $settings = relay_pair_code((string)($_POST['pair_code'] ?? ''));
        relay_call($settings, '/api/status');
        $db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
        $username = 'relay_' . bin2hex(random_bytes(6));
        $stmt = $db->prepare("INSERT INTO users (username, password, uuid, role) VALUES (?, ?, ?, 'relay')");
        $stmt->execute([$username, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $uuid]);
        $userId = (int)$db->lastInsertId();
        try {
            require_once __DIR__ . '/rebuild_configs.php';
            rebuild_configs();
            $bundle = relay_bundle($db, $uuid);
            relay_call($settings, '/api/configure', $bundle);
            $remoteStatus = relay_call($settings, '/api/status');
            relay_sync_clients($db, $settings);
            $settings['user_id'] = $userId;
            $settings['bundle_hash'] = hash('sha256', json_encode($bundle, JSON_THROW_ON_ERROR));
            $temporary = $settingsFile . '.new';
            if (file_put_contents($temporary, json_encode($settings, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить связку');
            chmod($temporary, 0600);
            if (!rename($temporary, $settingsFile)) throw new RuntimeException('Не удалось сохранить связку');
            $result = ['connected' => true, 'status' => $remoteStatus];
        } catch (Throwable $error) {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            try { rebuild_configs(); } catch (Throwable $ignored) {}
            throw $error;
        }
    } elseif ($action === 'disconnect') {
        $settings = relay_settings();
        if ($settings) {
            $db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->prepare("UPDATE users SET status = 'disabled' WHERE id = ? AND role = 'relay'")->execute([$settings['user_id']]);
            require_once __DIR__ . '/rebuild_configs.php';
            rebuild_configs();
            unlink($settingsFile);
        }
        $result = ['connected' => false];
    } elseif ($action === 'download') {
        $settings = relay_settings();
        if (!$settings) throw new RuntimeException('Прокладка не подключена');
        $name = (string)($_POST['username'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,48}$/', $name)) throw new InvalidArgumentException('Неверное имя профиля');
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $stmt = $db->prepare("SELECT id, expires_at FROM users WHERE username = ? AND role = 'client' AND status = 'active'");
        $stmt->execute([$name]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || ($user['expires_at'] && strtotime($user['expires_at']) < time())) throw new RuntimeException('Профиль не найден или отключён');
        $remote = relay_call($settings, '/api/client', ['name' => $name, 'expiresAt' => $user['expires_at'] ? strtotime($user['expires_at']) : null]);
        $ovpn = $remote['ovpn'] ?? '';
        if (!is_string($ovpn) || strpos($ovpn, '<ca>') === false || strpos($ovpn, '<key>') === false) throw new RuntimeException('Прокладка не вернула профиль');
        header('Content-Type: application/x-openvpn-profile');
        header('Content-Disposition: attachment; filename="' . $name . '.ovpn"');
        header('Cache-Control: no-store');
        echo $ovpn;
        exit;
    } else {
        throw new InvalidArgumentException('Неизвестное действие');
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
