<?php
declare(strict_types=1);

function panel_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function require_admin(bool $mutating = false): void {
    panel_session();
    if (empty($_SESSION['logged_in'])) {
        http_response_code(403);
        exit('Forbidden');
    }
    if ($mutating) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            exit('Invalid CSRF token');
        }
    }
}
