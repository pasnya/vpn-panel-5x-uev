<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';
require_admin(false);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$checks = [];

$services = [
    'nginx' => 'Веб-сервер Nginx',
    'xray' => 'Ядро Xray (Reality / xHTTP)',
    'caddy' => 'Caddy (NaiveProxy)',
    'hysteria-server' => 'Hysteria 2 (UDP/QUIC)',
    'mita' => 'Mita (Mieru)',
    'warp-svc' => 'Cloudflare WARP',
    'cron' => 'Планировщик Cron (SSL/лимиты)',
];

$allServicesOk = true;
$downServices = [];
foreach ($services as $srv => $label) {
    $sub = trim((string)@shell_exec('systemctl show -p SubState --value ' . escapeshellarg($srv) . ' 2>/dev/null'));
    if ($sub !== 'running') {
        $allServicesOk = false;
        $downServices[] = $label . ($sub ? " ({$sub})" : ' (остановлен)');
    }
}
$checks[] = [
    'id' => 'services',
    'ok' => $allServicesOk,
    'label' => 'Системные службы',
    'detail' => $allServicesOk ? 'Все 7 служб работают штатно.' : 'Не работают: ' . implode(', ', $downServices),
];

$ssOut = (string)@shell_exec('ss -H -lntu 2>/dev/null');
$port80 = (bool)preg_match('/\btcp\b.*?:80\s/', $ssOut);
$port443Tcp = (bool)preg_match('/\btcp\b.*?:443\s/', $ssOut);
$port443Udp = (bool)preg_match('/\budp\b.*?:443\s/', $ssOut);
$portsOk = $port80 && $port443Tcp && $port443Udp;
$missingPorts = [];
if (!$port80) $missingPorts[] = '80/tcp';
if (!$port443Tcp) $missingPorts[] = '443/tcp';
if (!$port443Udp) $missingPorts[] = '443/udp';
$checks[] = [
    'id' => 'ports',
    'ok' => $portsOk,
    'label' => 'Слушающие порты (80, 443)',
    'detail' => $portsOk ? 'Порты 80/tcp, 443/tcp и 443/udp открыты локально.' : 'Не слушают: ' . implode(', ', $missingPorts),
];

$diskFree = @disk_free_space('/');
$diskTotal = @disk_total_space('/');
$diskFreeGb = $diskFree !== false ? round($diskFree / 1073741824, 1) : null;
$diskOk = $diskFreeGb === null || $diskFreeGb >= 1.0;
$checks[] = [
    'id' => 'disk',
    'ok' => $diskOk,
    'label' => 'Свободное место на диске',
    'detail' => $diskFreeGb !== null ? "Свободно {$diskFreeGb} ГБ" . ($diskOk ? '' : ' (рекомендуется минимум 1 ГБ)') : 'Не удалось определить',
];

$memInfo = (string)@file_get_contents('/proc/meminfo');
$memAvailMb = null;
if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $memInfo, $m)) {
    $memAvailMb = (int)round(((int)$m[1]) / 1024);
}
$memOk = $memAvailMb === null || $memAvailMb >= 100;
$checks[] = [
    'id' => 'memory',
    'ok' => $memOk,
    'label' => 'Оперативная память',
    'detail' => $memAvailMb !== null ? "Доступно {$memAvailMb} МБ" : 'Не удалось определить',
];

$overall = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $overall = false;
        break;
    }
}

echo json_encode(['success' => true, 'healthy' => $overall, 'checks' => $checks], JSON_UNESCAPED_UNICODE);
