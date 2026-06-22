<?php
session_start();
if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    exit(json_encode(["error" => "Forbidden"]));
}

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

function curl_json($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT => 'VPN-Panel/1.0',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ? json_decode($resp, true) : null;
}

function get_latest_version($repo) {
    for ($i = 0; $i < 3; $i++) {
        $data = curl_json("https://api.github.com/repos/{$repo}/releases/latest");
        if (isset($data['tag_name'])) {
            return $data['tag_name'];
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
    $script = '/var/www/panel/update_cores.sh';
    if (!file_exists($script)) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Update script not found"]);
        exit;
    }

    $cmd = "/usr/bin/sudo /bin/bash " . $script . " update " . escapeshellarg($core) . " 2>&1";
    $output = shell_exec($cmd);
    echo json_encode(["success" => true, "output" => $output]);
}
?>
