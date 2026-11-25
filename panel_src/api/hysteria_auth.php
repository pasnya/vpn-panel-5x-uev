<?php
header('Content-Type: application/json');
// Hysteria обращается сюда напрямую на 127.0.0.1:8080. Через домен (8443) запросы тоже приходят
// с 127.0.0.1 (их проксирует nginx stream), поэтому проверяем ещё и порт.
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || (string)($_SERVER['SERVER_PORT'] ?? '') !== '8080' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit(json_encode(['ok' => false]));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(["ok" => false, "msg" => "Invalid JSON input"]);
    exit;
}

$token = $input['auth'] ?? $input['payload'] ?? null;
if (!$token) {
    echo json_encode(["ok" => false, "msg" => "Missing authentication token"]);
    exit;
}

try {
    $db = new PDO('sqlite:/var/www/panel/db/panel.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $db->prepare("SELECT * FROM users WHERE uuid = :token AND status = 'active'");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Проверка лимитов трафика
        if ($user['traffic_limit'] > 0 && $user['traffic_used'] >= $user['traffic_limit']) {
            echo json_encode([
                "ok" => false,
                "msg" => "Traffic limit exceeded"
            ]);
            exit;
        }
        // Проверка срока действия
        if ($user['expires_at'] && strtotime($user['expires_at']) < time()) {
            echo json_encode([
                "ok" => false,
                "msg" => "Account expired"
            ]);
            exit;
        }

        echo json_encode([
            "ok" => true,
            "id" => $user['username'],
            "msg" => "Access granted"
        ]);
    } else {
        echo json_encode([
            "ok" => false,
            "msg" => "Invalid token"
        ]);
    }
} catch (Exception $e) {
    error_log('Hysteria auth failed: ' . $e->getMessage());
    echo json_encode([
        "ok" => false,
        "msg" => "Internal error"
    ]);
}
?>
