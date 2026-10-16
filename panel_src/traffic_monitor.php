<?php
require_once __DIR__ . '/api/traffic.php';
$db = new PDO('sqlite:/var/www/panel/db/panel.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
collect_xray_traffic($db);
$changed = $db->exec("UPDATE users SET status = 'disabled' WHERE role = 'client' AND status = 'active' AND ((expires_at IS NOT NULL AND expires_at < datetime('now')) OR (traffic_limit > 0 AND traffic_used >= traffic_limit))");
if ($changed > 0) {
    require_once __DIR__ . '/api/rebuild_configs.php';
    rebuild_configs();
}
