<?php
require_once __DIR__ . '/security.php';
header('Content-Type: application/json');
require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['service'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Bad Request"]);
    exit;
}

$allowed_services = ['nginx', 'caddy', 'xray', 'mita', 'hysteria-server'];
$service = trim($_POST['service']);

if (!in_array($service, $allowed_services)) {
    echo json_encode(["success" => false, "message" => "Invalid service name"]);
    exit;
}

$verb = $service === 'nginx' ? 'reload' : 'restart';
$cmd = "sudo /usr/bin/systemctl " . $verb . " " . escapeshellarg($service);
exec($cmd, $output, $return_var);

// Проверяем реальный статус после рестарта
$subState = trim(shell_exec("systemctl show -p SubState --value " . escapeshellarg($service)));

if ($return_var === 0 && $subState === 'running') {
    echo json_encode(["success" => true, "message" => "Service restarted successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to cleanly restart service. State: " . $subState]);
}
?>
