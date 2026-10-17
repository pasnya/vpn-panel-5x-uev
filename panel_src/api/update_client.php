<?php
require_once __DIR__ . '/security.php';
require_admin(true);

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$status = $_POST['status'] ?? '';
$expires = trim($_POST['expires_at'] ?? '');
$limitGb = filter_input(INPUT_POST, 'traffic_limit_gb', FILTER_VALIDATE_FLOAT);
if (!$id || !in_array($status, ['active', 'disabled'], true) || ($expires !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $expires)) || $limitGb === false || $limitGb === null || $limitGb < 0 || $limitGb > 100000) {
    http_response_code(400);
    exit('Invalid client data');
}

try {
    $db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $nameStmt = $db->prepare("SELECT username FROM users WHERE id = ? AND role = 'client'");
    $nameStmt->execute([$id]);
    $name = $nameStmt->fetchColumn();
    if (!$name) throw new RuntimeException('Профиль не найден');
    require_once __DIR__ . '/proxy_transport.php';
    $relay = relay_settings();
    $stmt = $db->prepare("UPDATE users SET status = ?, expires_at = ?, traffic_limit = ? WHERE id = ? AND role = 'client'");
    $stmt->execute([$status, $expires === '' ? null : $expires . ' 23:59:59', (int)round($limitGb * 1073741824), $id]);
    require_once __DIR__ . '/rebuild_configs.php';
    rebuild_configs();
    $relaySyncError = false;
    if ($relay) {
        try { relay_sync_clients($db, $relay); }
        catch (Throwable $syncError) {
            error_log('Relay sync failed: ' . $syncError->getMessage());
            $relaySyncError = true;
        }
    }
    header('Location: ../index.php' . ($relaySyncError ? '?relay_sync_error=1' : ''));
} catch (Throwable $e) {
    error_log('Client update failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Не удалось обновить клиента');
}
