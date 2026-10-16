<?php
require_once __DIR__ . '/security.php';
require_admin(true);

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$status = $_POST['status'] ?? '';
$expires = trim($_POST['expires_at'] ?? '');
if (!$id || !in_array($status, ['active', 'disabled'], true) || ($expires !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $expires))) {
    http_response_code(400);
    exit('Invalid client data');
}

try {
    $db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $db->prepare("UPDATE users SET status = ?, expires_at = ? WHERE id = ? AND role = 'client'");
    $stmt->execute([$status, $expires === '' ? null : $expires . ' 23:59:59', $id]);
    require_once __DIR__ . '/rebuild_configs.php';
    rebuild_configs();
    header('Location: ../index.php');
} catch (Throwable $e) {
    error_log('Client update failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Не удалось обновить клиента');
}
