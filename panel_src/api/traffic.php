<?php
function collect_xray_traffic(PDO $db): void {
    $lock = fopen('/tmp/vpn-panel-traffic.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    try {
        $output = [];
        exec('/usr/local/bin/xray api statsquery --server=127.0.0.1:10085 -pattern "user>>>" -reset 2>&1', $output, $code);
        if ($code !== 0) return;
        $stats = json_decode(implode("\n", $output), true);
        if (!is_array($stats)) return;
        $totals = [];
        foreach ($stats['stat'] ?? [] as $entry) {
            if (preg_match('/^user>>>([a-zA-Z0-9_-]+)>>>traffic>>>(uplink|downlink)$/', $entry['name'] ?? '', $match)) {
                $totals[$match[1]] = ($totals[$match[1]] ?? 0) + (int)($entry['value'] ?? 0);
            }
        }
        $db->beginTransaction();
        $update = $db->prepare("UPDATE users SET traffic_used = traffic_used + ? WHERE username = ? AND role = 'client'");
        foreach ($totals as $username => $bytes) $update->execute([$bytes, $username]);
        $db->commit();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
