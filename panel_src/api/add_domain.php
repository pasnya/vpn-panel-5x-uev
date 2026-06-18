<?php
session_start();
if (!isset($_SESSION['logged_in'])) {
    http_response_code(403);
    exit("Forbidden");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['domain'])) {
    $domain = trim($_POST['domain']);
    $naive_sub = trim($_POST['naive_sub'] ?? '');
    $decoy = $_POST['decoy'] ?? 'blog';

    $domain = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $domain);
    $naive_sub = preg_replace('/[^a-zA-Z0-9\-\.]/', '', $naive_sub);

    if (empty($domain) || empty($naive_sub)) {
        die("Ошибка: Основной домен и поддомен для NaiveProxy обязательны!");
    }

    try {
        $db = new PDO('sqlite:/var/www/panel/db/panel.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

        $stmt = $db->prepare("INSERT OR IGNORE INTO domains (domain_name, decoy_type, ssl_status, admin_path, naive_sub) VALUES (:name, :decoy, 'requesting', :admin, :naive)");
        $stmt->execute([':name' => $domain, ':decoy' => $decoy, ':admin' => $admin_path, ':naive' => $naive_sub]);

        $domain_id = $db->lastInsertId();
        if (!$domain_id) {
            $stmt = $db->prepare("SELECT id FROM domains WHERE domain_name = :name");
            $stmt->execute([':name' => $domain]);
            $domain_id = $stmt->fetchColumn();
            $db->query("UPDATE domains SET ssl_status = 'requesting' WHERE id = " . $domain_id);
        }

        $decoy_dir = "/var/www/decoy/" . $domain;
        if (!file_exists($decoy_dir)) { mkdir($decoy_dir, 0755, true); }
        if (!file_exists($decoy_dir . "/index.html")) { file_put_contents($decoy_dir . "/index.html", '<h1>Decoy site</h1>'); }

        $le_live_dir = "/etc/letsencrypt/live/" . $domain;
        $file_found = false;

        if (file_exists($le_live_dir . "/fullchain.pem")) {
            $file_found = true;
        } else {
            $certbot_cmd = "sudo /usr/bin/certbot certonly --webroot -w /var/www/html -d " . escapeshellarg($domain) . " -d " . escapeshellarg($naive_sub) . " --keep-until-expiring --non-interactive --agree-tos --register-unsafely-without-email --preferred-challenges http";
            shell_exec($certbot_cmd);

            shell_exec("sudo /usr/bin/chgrp -R www-data /etc/letsencrypt && sudo /usr/bin/chmod -R g+rX /etc/letsencrypt");

            for ($i = 0; $i < 6; $i++) {
                clearstatcache(true, $le_live_dir . "/fullchain.pem");
                if (file_exists($le_live_dir . "/fullchain.pem")) {
                    $file_found = true;
                    break;
                }
                usleep(500000);
            }
        }

        if (!$file_found) {
            die("Ошибка: Certbot не смог выпустить сертификаты. Убедитесь, что A-записи для $domain и $naive_sub созданы в DNS.");
        }

        $map_path = "/etc/nginx/conf.d/domains.map";
        $current_map = file_exists($map_path) ? file_get_contents($map_path) : "";
        $map_add = "";
        if (strpos($current_map, $naive_sub . " caddy_naive;") === false) { $map_add .= $naive_sub . " caddy_naive;\n"; }
        if (!empty($map_add)) { file_put_contents($map_path, $map_add, FILE_APPEND); }

        $nginx_conf = "/etc/nginx/sites-available/" . $domain;
        $php_ver = PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;
        $php_sock = "/run/php/php" . $php_ver . "-fpm.sock";

        $nginx_conf_final = "
# Основной домен: админка + заглушка + XHTTP (WebSocket)
server {
    listen 127.0.0.1:8443 ssl http2;
    server_name " . $domain . ";

    ssl_certificate /etc/letsencrypt/live/" . $domain . "/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/" . $domain . "/privkey.pem;

    root /var/www/decoy/" . $domain . ";
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

        file_put_contents($nginx_conf, $nginx_conf_final);

        $symlink_path = "/etc/nginx/sites-enabled/" . $domain;
        if (file_exists($symlink_path) || is_link($symlink_path)) { @unlink($symlink_path); }
        @symlink($nginx_conf, $symlink_path);

        $db->query("UPDATE domains SET ssl_status = 'active' WHERE id = " . $domain_id);

        require_once 'rebuild_configs.php';
        rebuild_configs();

        shell_exec("sudo /usr/bin/systemctl reload nginx");

        header("Location: https://" . $domain . "/" . $admin_path . "/index.php");
        exit;
    } catch (Exception $e) {
        die("Ошибка при создании домена: " . $e->getMessage());
    }
}
?>
