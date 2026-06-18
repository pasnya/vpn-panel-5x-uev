<?php
function rebuild_configs() {
    try {
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $all_users = $db->query("SELECT * FROM users WHERE role = 'client' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $domains = $db->query("SELECT * FROM domains WHERE ssl_status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

        $paths_file = '/var/www/panel/db/paths.json';
        $admin_path = 'admin';
        $admin_port = '8080';
        $xhttp_path = 'xhttp';
        if (file_exists($paths_file)) {
            $paths = json_decode(file_get_contents($paths_file), true);
            if (is_array($paths)) {
                $admin_path = $paths['admin_path'] ?? $admin_path;
                $admin_port = $paths['admin_port'] ?? $admin_port;
                $xhttp_path = $paths['xhttp_path'] ?? '';
            }
        }
        if (empty($xhttp_path)) {
            $xhttp_path = bin2hex(random_bytes(4));
            $paths_data = [
                'admin_path' => $admin_path,
                'admin_port' => $admin_port,
                'xhttp_path' => $xhttp_path
            ];
            file_put_contents($paths_file, json_encode($paths_data, JSON_PRETTY_PRINT));
        }

        $php_ver = PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;
        $php_sock = "/run/php/php" . $php_ver . "-fpm.sock";

        // --- NGINX DOMAINS.MAP (только naive поддомены) ---
        $map_content = "";
        foreach ($domains as $dom) {
            $map_content .= $dom["domain_name"] . " nginx_web;
";
            $map_content .= $dom["naive_sub"] . " caddy_naive;
";
        }
        file_put_contents("/etc/nginx/conf.d/domains.map", $map_content);

        // --- NGINX SITE CONFIGS (для каждого домена) ---
        foreach ($domains as $dom) {
            $d_name = $dom['domain_name'];
            $nginx_conf = "/etc/nginx/sites-available/" . $d_name;

            $conf = "
server {
    listen 127.0.0.1:8443 ssl http2;
    server_name " . $d_name . ";

    ssl_certificate /etc/letsencrypt/live/" . $d_name . "/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/" . $d_name . "/privkey.pem;

    root /var/www/decoy/" . $d_name . ";
    index index.html;

    location /" . $admin_path . "/assets/ {
        root /var/www;
        rewrite ^/" . $admin_path . "/assets/(.*)$ /panel/assets/\$1 break;
    }

    location /" . $admin_path . "/ {
        root /var/www/panel;
        rewrite ^/" . $admin_path . "/(.*)$ /\$1 break;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:" . $php_sock . ";
    }

    location /" . $xhttp_path . " {
        proxy_redirect off;
        proxy_pass http://127.0.0.1:10001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \"upgrade\";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_buffering off;
        proxy_request_buffering off;
    }

    location / {
        try_files \$uri \$uri/ =404;
    }
}";

            file_put_contents($nginx_conf, $conf);

            $symlink = "/etc/nginx/sites-enabled/" . $d_name;
            if (file_exists($symlink) || is_link($symlink)) { @unlink($symlink); }
            @symlink($nginx_conf, $symlink);
        }

        // Удалить старые конфиги доменов, которых больше нет в БД
        $active_domains = array_column($domains, 'domain_name');
        $sites_available = glob("/etc/nginx/sites-available/*");
        foreach ($sites_available as $f) {
            $basename = basename($f);
            if ($basename === 'default') continue;
            if (!in_array($basename, $active_domains)) {
                @unlink($f);
                @unlink("/etc/nginx/sites-enabled/" . $basename);
            }
        }

        shell_exec("sudo /usr/bin/systemctl reload nginx");

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

        foreach ($domains as $dom) {
            $n_sub = $dom['naive_sub'];
            $d_name = $dom['domain_name'];

            $caddyfile_content .= "\n:7443 {\n";
            $caddyfile_content .= "    tls /etc/letsencrypt/live/{$d_name}/fullchain.pem /etc/letsencrypt/live/{$d_name}/privkey.pem\n";
            if (!empty($caddy_users_block)) {
                $caddyfile_content .= str_replace("forward_proxy {", "forward_proxy {\n        hide_ip\n        hide_via\n        probe_resistance", $caddy_users_block);
            }
            $caddyfile_content .= "    root * /var/www/decoy/" . $d_name . "\n    file_server\n}\n";
        }

        file_put_contents("/etc/caddy/Caddyfile", $caddyfile_content);
        shell_exec("sudo /usr/bin/systemctl restart caddy");

        // --- XRAY (REALITY + XHTTP) ---
        $xray_config_path = '/etc/xray/config.json';
        if (file_exists($xray_config_path)) {
            $xray_config = json_decode(file_get_contents($xray_config_path), true);
            if ($xray_config) {
                foreach ($xray_config['inbounds'] as &$inbound) {
                    if ($inbound['protocol'] == 'vless') {
                        $inbound['settings']['clients'] = [];
                        foreach ($all_users as $u) {
                            $client = ["id" => $u['uuid']];
                            if ($inbound['port'] == 10002) { $client["flow"] = "xtls-rprx-vision"; }
                            $inbound['settings']['clients'][] = $client;
                        }
                    }
                    if ($inbound['port'] == 10001) {
                        $inbound['streamSettings']['xhttpSettings']['path'] = "/" . $xhttp_path . "/";
                    }
                }
                file_put_contents($xray_config_path, json_encode($xray_config, JSON_PRETTY_PRINT));
                shell_exec("sudo /usr/bin/systemctl restart xray");
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

            file_put_contents($hysteria_config_path, $hysteria_yaml);
            shell_exec("sudo /usr/bin/systemctl restart hysteria-server");
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
                file_put_contents($mita_config_path, json_encode($mita_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                shell_exec("sudo /usr/bin/systemctl restart mita");
            }
        }

    } catch (Exception $e) {
        error_log("Rebuild config error: " . $e->getMessage());
    }
}
?>
