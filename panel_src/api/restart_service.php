<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit;
}

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

// Хард-килл для Nginx для сброса зависших TCP туннелей
if ($service === 'nginx') {
    shell_exec("sudo /usr/bin/killall -9 nginx");
}

$cmd = "sudo /usr/bin/systemctl restart " . escapeshellarg($service);
exec($cmd, $output, $return_var);

// Проверяем реальный статус после рестарта
$subState = trim(shell_exec("systemctl show -p SubState --value " . escapeshellarg($service)));

if ($return_var === 0 && $subState === 'running') {
    echo json_encode(["success" => true, "message" => "Service restarted successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to cleanly restart service. State: " . $subState]);
}
?>