<?php
require_once __DIR__ . '/security.php';
require_admin();

header('Content-Type: application/json');

$core = $_GET['core'] ?? '';
$action = $_GET['action'] ?? 'check';

$allowed_cores = ['xray', 'hysteria', 'mita', 'caddy', 'all'];
$allowed_actions = ['check', 'update'];

if (!in_array($core, $allowed_cores) || !in_array($action, $allowed_actions)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid parameters"]);
    exit;
}
if ($action === 'update') require_admin(true);
if ($action === 'update' && $core === 'all') {
    http_response_code(400);
    exit(json_encode(['error' => 'Update one core at a time']));
}

// Последний тег релиза через редирект github.com/.../releases/latest: без GitHub API,
// у которого лимит 60 запросов в час на IP (на общих IP облаков он часто исчерпан).
function github_latest_tag($repo) {
    $ch = curl_init("https://github.com/{$repo}/releases/latest");
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT => 'VPN-Panel/1.0',
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) < 400;
    $url = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if (!$ok || !preg_match('#/releases/tag/([^/?\#]+)$#', $url, $m)) return null;
    return rawurldecode($m[1]);
}

function get_latest_version($repo) {
    for ($i = 0; $i < 3; $i++) {
        $tag = github_latest_tag($repo);
        if ($tag !== null && $tag !== '') {
            return $tag;
        }
        sleep(1);
    }
    return "unknown";
}

function strip_tag($tag) {
    return preg_replace('/^v/', '', preg_replace('/^app\//', '', $tag));
}

function get_current_version($core) {
    switch ($core) {
        case 'xray':
            $out = @shell_exec('/usr/local/bin/xray version 2>/dev/null');
            if (preg_match('/^Xray\s+(\S+)/', trim($out), $m)) return $m[1];
            return 'not installed';
        case 'hysteria':
            $out = @shell_exec('/usr/local/bin/hysteria version 2>/dev/null');
            if (preg_match('/Version:\s+v(\S+)/', $out, $m)) return $m[1];
            return 'not installed';
        case 'mita':
            $out = @shell_exec('/usr/bin/mita version 2>/dev/null');
            $line = trim(explode("\n", $out ?? '')[0]);
            if (!empty($line)) return $line;
            return 'not installed';
        case 'caddy':
            $out = @shell_exec('/usr/local/bin/caddy version 2>/dev/null');
            if (preg_match('/v(\S+)/', $out, $m)) return $m[1];
            return 'not installed';
    }
    return 'unknown';
}

if ($action === 'check') {
    $repos = [
        'xray' => 'XTLS/Xray-core',
        'hysteria' => 'apernet/hysteria',
        'mita' => 'enfein/mieru',
        'caddy' => 'caddyserver/caddy'
    ];

    $cores_to_check = ($core === 'all') ? array_keys($repos) : [$core];
    $result = [];

    foreach ($cores_to_check as $c) {
        $current = get_current_version($c);
        $latest = strip_tag(get_latest_version($repos[$c]));
        $result[$c] = ['current' => $current, 'latest' => $latest];
    }

    echo json_encode($result);

} elseif ($action === 'update') {
    $script = '/usr/local/sbin/vpn-update-cores';
    if (!file_exists($script)) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Update script not found"]);
        exit;
    }

    $cmd = "/usr/bin/sudo " . $script . " update " . escapeshellarg($core) . " 2>&1";
    exec($cmd, $lines, $code);
    if ($code !== 0) http_response_code(500);
    echo json_encode(["success" => $code === 0, "output" => implode("\n", $lines)]);
}
?>
