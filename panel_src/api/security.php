<?php
declare(strict_types=1);

function panel_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
            'path' => '/',
        ]);
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

// Ограничение подбора пароля. За nginx stream все запросы приходят с 127.0.0.1,
// поэтому счётчик общий: 5 неудачных попыток подряд - пауза 60 секунд.
const LOGIN_THROTTLE_FILE = '/var/www/panel/db/login_throttle.json';
const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_SECONDS = 60;

function login_throttle_update(callable $change): array {
    $handle = @fopen(LOGIN_THROTTLE_FILE, 'c+');
    if (!$handle) return ['count' => 0, 'until' => 0];
    flock($handle, LOCK_EX);
    $state = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($state) || ($state['until'] ?? 0) <= time()) $state = ['count' => 0, 'until' => 0];
    $state = $change($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $state;
}

function login_wait_seconds(): int {
    $state = login_throttle_update(static function (array $state): array { return $state; });
    return $state['count'] >= LOGIN_MAX_FAILURES ? max(0, $state['until'] - time()) : 0;
}

function login_record_failure(): void {
    login_throttle_update(static function (array $state): array {
        return ['count' => $state['count'] + 1, 'until' => time() + LOGIN_LOCK_SECONDS];
    });
}

function login_record_success(): void {
    login_throttle_update(static function (array $state): array { return ['count' => 0, 'until' => 0]; });
}
