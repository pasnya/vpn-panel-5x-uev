<?php
require_once __DIR__ . '/security.php';
require_admin();

header('Content-Type: application/json');

const PANEL_UPDATER = '/usr/local/sbin/vpn-panel-update';

function panel_update_status(): array {
    $version = trim((string)@file_get_contents(__DIR__ . '/../VERSION'));
    $state = json_decode((string)@file_get_contents('/var/lib/vpn-panel-update/state.json'), true);
    if (!is_array($state)) $state = [];
    // Служба обновления ограничена 15 минутами: дольше "running" означает, что её прервали.
    if (($state['status'] ?? '') === 'running' && time() - (int)strtotime((string)($state['changed_at'] ?? '')) > 1200) {
        $state['status'] = 'error';
        $state['message'] = 'Обновление прервалось, подробности в /var/log/vpn-panel-update.log';
    }
    return [
        'current' => $version !== '' ? $version : 'unknown',
        'latest' => (string)($state['latest'] ?? ''),
        'status' => (string)($state['status'] ?? 'idle'),
        'message' => (string)($state['message'] ?? ''),
        'auto' => (bool)($state['auto'] ?? true),
        'checked_at' => (string)($state['checked_at'] ?? ''),
        'changed_at' => (string)($state['changed_at'] ?? ''),
        'installed' => is_file(PANEL_UPDATER),
    ];
}

$action = $_GET['action'] ?? 'status';
$commands = [
    'check' => 'check',
    'update' => 'start',
    'auto_on' => 'enable-auto',
    'auto_off' => 'disable-auto',
];

if ($action === 'status') {
    exit(json_encode(panel_update_status()));
}
if (!isset($commands[$action])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid action']));
}
require_admin(true);
if (!is_file(PANEL_UPDATER)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Updater is not installed']));
}

exec('/usr/bin/sudo ' . PANEL_UPDATER . ' ' . $commands[$action] . ' 2>&1', $output, $code);
$result = panel_update_status();
if ($code === 75) {
    http_response_code(409);
    $result['error'] = 'Обновление уже выполняется';
} elseif ($code !== 0) {
    http_response_code(500);
    $result['error'] = trim(implode("\n", $output)) ?: 'Updater failed';
}
echo json_encode($result);
