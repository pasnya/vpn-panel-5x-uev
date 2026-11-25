<?php
function panel_service_action(string $service, string $action): void {
    $allowed = ['nginx' => ['reload'], 'caddy' => ['reload'], 'xray' => ['restart'], 'hysteria-server' => ['restart'], 'mita' => ['restart']];
    if (!in_array($action, $allowed[$service] ?? [], true)) {
        throw new RuntimeException('Unsupported service action');
    }
    exec('/usr/bin/sudo /usr/bin/systemctl ' . $action . ' ' . $service . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("Failed to $action $service: " . implode("\n", $output));
    }
}

function panel_write(string $path, string $content): void {
    if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
        throw new RuntimeException('Failed to write ' . $path);
    }
}

// Пишет файл только при изменении содержимого. true - файл изменился и службу надо обновить.
function panel_write_if_changed(string $path, string $content): bool {
    if (@file_get_contents($path) === $content) return false;
    panel_write($path, $content);
    return true;
}

function panel_xray_without_clients(array $config): array {
    foreach ($config['inbounds'] ?? [] as $i => $inbound) {
        if (($inbound['protocol'] ?? '') === 'vless') $config['inbounds'][$i]['settings']['clients'] = [];
    }
    return $config;
}

// Запускает `xray api adu/rmu` и проверяет итоговую строку "... N user(s) ...":
// при частичной ошибке команда всё равно завершается с кодом 0.
function panel_xray_api(string $command, int $expected): bool {
    exec('/usr/local/bin/xray api ' . $command . ' 2>&1', $output, $code);
    if ($code !== 0 || !preg_match_all('/(\d+)\s+user/i', implode("\n", $output), $m)) return false;
    return (int)end($m[1]) === $expected;
}

// Меняет пользователей работающего Xray без рестарта, чтобы не обрывать соединения остальных.
// false - менялось что-то кроме пользователей или API не справился: тогда нужен рестарт.
function panel_xray_hot_update(?array $old, array $new): bool {
    if (!$old || panel_xray_without_clients($old) != panel_xray_without_clients($new)) return false;
    if (!in_array('HandlerService', $new['api']['services'] ?? [], true)) return false;
    $server = '--server=127.0.0.1:10085';
    foreach ($new['inbounds'] as $i => $inbound) {
        if (($inbound['protocol'] ?? '') !== 'vless') continue;
        $tag = (string)($inbound['tag'] ?? '');
        if ($tag === '') return false;
        $before = [];
        foreach ($old['inbounds'][$i]['settings']['clients'] ?? [] as $client) $before[$client['email']] = $client;
        $after = [];
        foreach ($inbound['settings']['clients'] ?? [] as $client) $after[$client['email']] = $client;
        $remove = [];
        $add = [];
        foreach ($before as $email => $client) if (!isset($after[$email]) || $after[$email] != $client) $remove[] = (string)$email;
        foreach ($after as $email => $client) if (!isset($before[$email]) || $before[$email] != $client) $add[] = $client;
        if ($remove && !panel_xray_api('rmu ' . $server . ' -tag=' . escapeshellarg($tag) . ' ' . implode(' ', array_map('escapeshellarg', $remove)), count($remove))) return false;
        if ($add) {
            $file = tempnam(sys_get_temp_dir(), 'xray-adu-');
            if ($file === false) return false;
            chmod($file, 0600);
            $inbound['settings']['clients'] = $add;
            file_put_contents($file, json_encode(['inbounds' => [$inbound]]));
            $ok = panel_xray_api('adu ' . $server . ' ' . escapeshellarg($file), count($add));
            unlink($file);
            if (!$ok) return false;
        }
    }
    return true;
}

// Cloudflare WARP подключается как WireGuard-выход Xray. Остальные ядра (Hysteria 2, Mieru, Naive)
// при включённом WARP отправляют исходящий трафик в локальный SOCKS-вход Xray, и общая
// маршрутизация решает, что идёт через WARP, а что напрямую.
const WARP_CHAIN_PORT = 10808;
const WARP_CHAIN_TAG = 'warp-chain';
const WARP_PEER_PUBLIC_KEY = 'bmXOC+F1FxEMF9dyiK2H5/1SUtzH0JuVo51h2wPfgyo=';
// IPv4-адрес, а не engage.cloudflareclient.com: доменный endpoint Xray разрешает случайно в IPv4
// или IPv6, а на сервере без IPv6 такое рукопожатие не проходит.
const WARP_PEER_ENDPOINT = '162.159.192.1:2408';
// Умный режим: через WARP идут только сервисы, которые не любят IP дата-центров.
const WARP_SMART_DOMAINS = [
    'domain:openai.com', 'domain:chatgpt.com', 'domain:oaistatic.com', 'domain:oaiusercontent.com', 'domain:sora.com',
    'domain:netflix.com', 'domain:netflix.net', 'domain:nflxvideo.net', 'domain:nflximg.net', 'domain:nflximg.com',
    'domain:nflxext.com', 'domain:nflxso.net',
    'domain:ipify.org',
];
const PRIVATE_IP_RANGES = [
    '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16',
    '::1/128', 'fc00::/7', 'fe80::/10',
];

// Включённый WARP из warp.json или null. Битый файл считается выключенным WARP.
function panel_warp_settings(): ?array {
    $raw = @file_get_contents('/var/www/panel/db/warp.json');
    $warp = $raw ? json_decode($raw, true) : null;
    if (!is_array($warp) || ($warp['status'] ?? '') !== 'enabled') return null;
    $data = $warp['data'] ?? [];
    $key = (string)($data['private_key'] ?? '');
    if (!preg_match('~^[A-Za-z0-9+/]{43}=$~', $key)) return null;
    $address = [];
    if (filter_var($data['v4'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $address[] = $data['v4'] . '/32';
    if (filter_var($data['v6'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $address[] = $data['v6'] . '/128';
    if (!$address) return null;
    $reserved = $data['reserved'] ?? [];
    $reserved = (is_array($reserved) && count($reserved) === 3) ? array_map('intval', array_values($reserved)) : [0, 0, 0];
    $peerKey = (string)($data['peer_public_key'] ?? '');
    if (!preg_match('~^[A-Za-z0-9+/]{43}=$~', $peerKey)) $peerKey = WARP_PEER_PUBLIC_KEY;
    $endpoint = (string)($data['endpoint'] ?? '');
    if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}:\d{1,5}$/', $endpoint)) $endpoint = WARP_PEER_ENDPOINT;
    return [
        'mode' => ($warp['mode'] ?? 'smart') === 'all' ? 'all' : 'smart',
        'outbound' => [
            'tag' => 'warp',
            'protocol' => 'wireguard',
            'settings' => [
                'secretKey' => $key,
                'address' => $address,
                'peers' => [['publicKey' => $peerKey, 'endpoint' => $endpoint, 'allowedIPs' => ['0.0.0.0/0', '::/0']]],
                'reserved' => $reserved,
                'mtu' => 1280,
                'noKernelTun' => true,
            ],
        ],
    ];
}

// Убирает из YAML ключи верхнего уровня вместе с вложенными строками.
function panel_yaml_strip_keys(string $yaml, array $keys): string {
    $out = [];
    $skip = false;
    foreach (preg_split('/\r?\n/', $yaml) as $line) {
        if (preg_match('/^([A-Za-z0-9_]+)\s*:/', $line, $m)) {
            $skip = in_array($m[1], $keys, true);
        }
        if (!$skip) $out[] = $line;
    }
    return rtrim(implode("\n", $out)) . "\n";
}

function rebuild_configs() {
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        require_once __DIR__ . '/traffic.php';
        collect_xray_traffic($db);
        $db->exec("UPDATE users SET status = 'disabled' WHERE role = 'client' AND status = 'active' AND ((expires_at IS NOT NULL AND expires_at < datetime('now')) OR (traffic_limit > 0 AND traffic_used >= traffic_limit))");

        $all_users = $db->query("SELECT * FROM users WHERE role IN ('client', 'relay') AND status = 'active' AND (expires_at IS NULL OR expires_at >= datetime('now'))")->fetchAll(PDO::FETCH_ASSOC);
        // Порядок важен: первый активный домен даёт сертификат Hysteria 2 и общий вход Caddy.
        $domains = $db->query("SELECT * FROM domains WHERE ssl_status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        // --- CLOUDFLARE WARP (WireGuard-выход Xray) ---
        $warp = panel_warp_settings();
        $chain_url = 'socks5://127.0.0.1:' . WARP_CHAIN_PORT;

        $paths_file = '/var/www/panel/db/paths.json';
        $admin_path = 'admin';
        $xhttp_path = 'xhttp';
        if (file_exists($paths_file)) {
            $paths = json_decode(file_get_contents($paths_file), true);
            $admin_path = $paths['admin_path'] ?? $admin_path;
            $xhttp_path = trim($paths['xhttp_path'] ?? $xhttp_path, '/');
        }

        // --- NGINX ---
        // Конфиги nginx собирает root-хелпер из проверенных данных базы: панель в /etc/nginx не пишет.
        exec('/usr/bin/sudo /usr/local/sbin/vpn-nginx-sync 2>&1', $nginx_output, $nginx_code);
        if ($nginx_code !== 0) throw new RuntimeException('Nginx config invalid: ' . implode("\n", $nginx_output));

        // --- CADDY (NAIVE) ---
        $caddy_users_block = "";
        if (!empty($all_users)) {
             $caddy_users_block = "    forward_proxy {\n";
             foreach ($all_users as $u) {
                 $caddy_users_block .= "        basic_auth " . $u['username'] . " " . $u['uuid'] . "\n";
             }
             $caddy_users_block .= "    }\n";
        }

        $caddyfile_content = "{\n    admin 127.0.0.1:2019\n    auto_https off\n    order forward_proxy before file_server\n}\n";

        // Адрес сайта в Caddyfile должен быть уникальным: два блока ":7443" Caddy не загрузит.
        // Поэтому каждый поддомен Naive получает свой блок со своим сертификатом (выбор по SNI),
        // а общий блок ":7443" без tls принимает CONNECT-запросы: у них Host - это адрес
        // назначения, а не поддомен, и в блоки с именем хоста они не попадают.
        $forward_proxy = '';
        if (!empty($caddy_users_block)) {
            $forward_proxy = str_replace("forward_proxy {", "forward_proxy {\n        hide_ip\n        hide_via\n        probe_resistance", $caddy_users_block);
            if ($warp) {
                // С upstream Naive отдаёт все соединения в Xray, а тот решает: WARP или напрямую.
                $forward_proxy = str_replace("probe_resistance", "probe_resistance\n        upstream " . $chain_url, $forward_proxy);
            }
        }
        foreach ($domains as $dom) {
            $d_name = $dom['domain_name'];
            $caddyfile_content .= "\n" . $dom['naive_sub'] . ":7443 {\n";
            $caddyfile_content .= "    tls /etc/letsencrypt/live/{$d_name}/fullchain.pem /etc/letsencrypt/live/{$d_name}/privkey.pem\n";
            $caddyfile_content .= $forward_proxy;
            $caddyfile_content .= "    root * /var/www/decoy/" . $d_name . "\n    file_server\n}\n";
        }
        if (!empty($domains)) {
            $caddyfile_content .= "\n:7443 {\n" . $forward_proxy;
            $caddyfile_content .= "    root * /var/www/decoy/" . $domains[0]['domain_name'] . "\n    file_server\n}\n";
        }

        if (panel_write_if_changed("/etc/caddy/Caddyfile", $caddyfile_content)) {
            panel_service_action('caddy', 'reload');
        }

        // --- XRAY (REALITY + XHTTP) ---
        $xray_config_path = '/etc/xray/config.json';
        if (file_exists($xray_config_path)) {
            $xray_raw = file_get_contents($xray_config_path);
            $xray_config = json_decode($xray_raw, true);
            $xray_before = $xray_config;
            if ($xray_config) {
                $sniffing = ['enabled' => true, 'destOverride' => ['http', 'tls', 'quic'], 'routeOnly' => true];
                $xray_config['inbounds'] = array_values(array_filter($xray_config['inbounds'], static function ($inbound) {
                    return ($inbound['tag'] ?? '') !== WARP_CHAIN_TAG;
                }));
                foreach ($xray_config['inbounds'] as &$inbound) {
                    if (isset($inbound['protocol']) && $inbound['protocol'] == 'vless') {
                        // Умному режиму нужен домен назначения: sniffing достаёт его из TLS/HTTP/QUIC.
                        if ($warp) $inbound['sniffing'] = $sniffing;
                        else unset($inbound['sniffing']);
                        $inbound['settings']['clients'] = [];
                        foreach ($all_users as $u) {
                            $client = ["id" => $u['uuid'], "email" => $u['username']];
                            if ($inbound['port'] == 10002) { $client["flow"] = "xtls-rprx-vision"; }
                            $inbound['settings']['clients'][] = $client;
                        }
                    }
                    if (isset($inbound['port']) && $inbound['port'] == 10001) {
                        $inbound['streamSettings']['xhttpSettings']['path'] = "/" . $xhttp_path . "/";
                        $inbound['tag'] = $inbound['tag'] ?? 'xhttp';
                    }
                    if (isset($inbound['port']) && $inbound['port'] == 10002) {
                        // Теги нужны API для добавления пользователей без рестарта.
                        $inbound['tag'] = $inbound['tag'] ?? 'reality';
                        // Fallback Reality ведёт на заглушку 8090, а не на админку (8080).
                        foreach ($inbound['settings']['fallbacks'] ?? [] as $f => $fallback) {
                            if (($fallback['dest'] ?? null) == 8080) $inbound['settings']['fallbacks'][$f]['dest'] = 8090;
                        }
                    }
                }
                unset($inbound);

                // Первый выход - выход по умолчанию, поэтому "direct" всегда первым.
                $outbounds = [["tag" => "direct", "protocol" => "freedom"]];
                $rules = [["type" => "field", "inboundTag" => ["api"], "outboundTag" => "api"]];
                if ($warp) {
                    $xray_config['inbounds'][] = [
                        "tag" => WARP_CHAIN_TAG, "listen" => "127.0.0.1", "port" => WARP_CHAIN_PORT, "protocol" => "socks",
                        "settings" => ["auth" => "noauth", "udp" => true], "sniffing" => $sniffing,
                    ];
                    $outbounds[] = $warp['outbound'];
                    $rules[] = ["type" => "field", "ip" => PRIVATE_IP_RANGES, "outboundTag" => "direct"];
                    $rules[] = $warp['mode'] === 'all'
                        ? ["type" => "field", "network" => "tcp,udp", "outboundTag" => "warp"]
                        : ["type" => "field", "domain" => WARP_SMART_DOMAINS, "outboundTag" => "warp"];
                }
                $xray_config['outbounds'] = $outbounds;
                $xray_config['api'] = ["tag" => "api", "services" => ["StatsService", "HandlerService"]];
                $xray_config['stats'] = new stdClass();
                $xray_config['policy'] = ["levels" => ["0" => ["statsUserUplink" => true, "statsUserDownlink" => true]]];
                $xray_config['routing'] = ["rules" => $rules];
                $has_api = false;
                foreach ($xray_config['inbounds'] as $inbound) if (($inbound['tag'] ?? '') === 'api') $has_api = true;
                if (!$has_api) $xray_config['inbounds'][] = ["tag" => "api", "listen" => "127.0.0.1", "port" => 10085, "protocol" => "dokodemo-door", "settings" => ["address" => "127.0.0.1"]];
                unset($xray_config['dns']);

                $xray_new = json_encode($xray_config, JSON_PRETTY_PRINT);
                if ($xray_new !== $xray_raw) {
                    panel_write($xray_config_path, $xray_new);
                    if (!panel_xray_hot_update($xray_before, $xray_config)) {
                        panel_service_action('xray', 'restart');
                    }
                }
            }
        }

        // --- HYSTERIA 2 ---
        $hysteria_config_path = '/etc/hysteria/config.yaml';
        if (file_exists($hysteria_config_path)) {
            $cert_path = "/etc/hysteria/selfsigned.crt";
            $key_path = "/etc/hysteria/selfsigned.key";

            if (!empty($domains)) {
                $check_domain = $domains[0]['domain_name'];
                $le_cert = "/etc/letsencrypt/live/" . $check_domain . "/fullchain.pem";
                $le_key = "/etc/letsencrypt/live/" . $check_domain . "/privkey.pem";

                if (file_exists($le_cert) && file_exists($le_key)) {
                    $cert_path = $le_cert;
                    $key_path = $le_key;
                }
            }

            $hysteria_yaml = file_get_contents($hysteria_config_path);
            $hysteria_yaml = preg_replace('/cert:\s*[^\n\r]+/', 'cert: ' . $cert_path, $hysteria_yaml);
            $hysteria_yaml = preg_replace('/key:\s*[^\n\r]+/', 'key: ' . $key_path, $hysteria_yaml);
            $hysteria_yaml = preg_replace('/url:\s*http:\/\/127\.0\.0\.1:\d+[^\n\r]*/', 'url: http://127.0.0.1:8080/' . $admin_path . '/api/hysteria_auth.php', $hysteria_yaml);

            $hysteria_yaml = panel_yaml_strip_keys($hysteria_yaml, ['outbounds', 'acl']);
            if ($warp) {
                // Единственный выход Hysteria - локальный SOCKS Xray, маршрут выбирает Xray.
                $hysteria_yaml .= "outbounds:\n  - name: xray\n    type: socks5\n    socks5:\n      addr: 127.0.0.1:" . WARP_CHAIN_PORT . "\n";
            }

            if ($hysteria_yaml !== file_get_contents($hysteria_config_path)) {
                panel_write($hysteria_config_path, $hysteria_yaml);
                panel_service_action('hysteria-server', 'restart');
            }
        }

        // --- MITA ---
        $mita_config_path = '/etc/mita/config.json';
        if (file_exists($mita_config_path)) {
            $mita_config = json_decode(file_get_contents($mita_config_path), true);
            if ($mita_config) {
                $mita_config['users'] = [];
                if (!empty($all_users)) {
                    foreach ($all_users as $u) {
                        $mita_config['users'][] = [
                            "name" => $u['username'],
                            "password" => substr(str_replace('-', '', $u['uuid']), 0, 16)
                        ];
                    }
                } else {
                    $mita_config['users'][] = ["name" => "placeholder", "password" => "placeholder_pass_12345"];
                }
                if ($warp) {
                    $mita_config['egress'] = [
                        "proxies" => [["name" => "xray", "protocol" => "SOCKS5_PROXY_PROTOCOL", "host" => "127.0.0.1", "port" => WARP_CHAIN_PORT]],
                        "rules" => [["ipRanges" => ["*"], "domainNames" => ["*"], "action" => "PROXY", "proxyNames" => ["xray"]]],
                    ];
                } else {
                    unset($mita_config['egress']);
                }
                if (panel_write_if_changed($mita_config_path, json_encode($mita_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                    panel_service_action('mita', 'restart');
                }
            }
        }
}
?>
