<?php
$db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $db->prepare("UPDATE users SET status = 'disabled' WHERE role = 'client' AND status = 'active' AND expires_at IS NOT NULL AND expires_at < datetime('now')");
$stmt->execute();
if ($stmt->rowCount() > 0) {
    require_once __DIR__ . '/api/rebuild_configs.php';
    rebuild_configs();
}
