<?php
session_start();
if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    exit("Forbidden");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username']);
    $domain_id = $_POST['domain_id'] ?? '';

    // Валидация логина
    $username = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
    if (empty($username)) {
        die("Неверное имя пользователя");
    }

    try {
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

        header("Location: ../index.php");
        exit;
    } catch (Exception $e) {
        die("Ошибка при создании клиента: " . $e->getMessage());
    }
}

function cat_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}