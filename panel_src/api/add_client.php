<?php
require_once __DIR__ . '/security.php';
require_admin(true);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $domain_id = $_POST['domain_id'] ?? 'auto';

    // Валидация логина
    $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
    if (empty($username) || strlen($username) > 48 || strtolower($username) === 'server') {
        die("Неверное имя пользователя");
    }

    try {
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($domain_id === 'auto') {
            $domain_id = $db->query("SELECT id FROM domains WHERE ssl_status = 'active' ORDER BY id LIMIT 1")->fetchColumn() ?: '';
        } elseif ($domain_id !== '') {
            $check = $db->prepare("SELECT id FROM domains WHERE id = ? AND ssl_status = 'active'");
            $check->execute([$domain_id]);
            if (!$check->fetchColumn()) {
                http_response_code(400);
                exit('Домен ещё не готов');
            }
        }

        // Генерация UUID и пароля
        $uuid = cat_uuid();
        $password_hash = password_hash($uuid, PASSWORD_DEFAULT);

        // 1. Добавление записи в БД
        $stmt = $db->prepare("INSERT INTO users (username, password, uuid, role) VALUES (:username, :password, :uuid, 'client')");
        $stmt->execute([':username' => $username, ':password' => $password_hash, ':uuid' => $uuid]);
        $user_id = $db->lastInsertId();

        if (!empty($domain_id)) {
            $stmt = $db->prepare("INSERT INTO client_credentials (user_id, protocol, domain_id, credential_key) VALUES (:uid, 'all', :did, :uuid)");
            $stmt->execute([':uid' => $user_id, ':did' => $domain_id, ':uuid' => $uuid]);
        }

        require_once 'rebuild_configs.php';
        rebuild_configs();

        header("Location: ../index.php?created=" . $user_id);
        exit;
    } catch (Exception $e) {
        die("Ошибка при создании клиента: " . $e->getMessage());
    }
}

function cat_uuid() {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
