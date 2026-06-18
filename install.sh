#!/usr/bin/env bash
# ==============================================================================
# Установочный скрипт мультипротокольной VPN/Proxy панели управления
# Единый порт 443 + IP Админка | Ubuntu 20.04 / 22.04 / 24.04 (x86_64)
# ==============================================================================

set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0;0m'

log_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

if [[ $EUID -ne 0 ]]; then
    log_error "Этот скрипт должен быть запущен от имени root (sudo)."
fi

ARCH=$(uname -m)
if [[ "$ARCH" != "x86_64" ]]; then
    log_error "Поддерживается только архитектура x86_64. Текущая: $ARCH"
fi

# ── Генерация секретов ──────────────────────────────────────────────────────
ADMIN_SECRET=$(openssl rand -hex 12)
CLIENT_UUID=$(cat /proc/sys/kernel/random/uuid)
ADMIN_PORT=$((RANDOM % 50001 + 10000))
XHTTP_PATH=$(openssl rand -hex 8)

# ── Обновление пакетов и установка зависимостей ─────────────────────────────
log_info "Начало установки. Обновление пакетов и установка зависимостей..."
apt-get update
apt-get upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"
apt-get install -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold" \
    curl wget git unzip zip socat sqlite3 jq libnginx-mod-stream \
    nginx certbot python3-certbot-nginx php-fpm php-sqlite3 php-curl openssl \
    iptables-persistent netfilter-persistent

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
PHP_FPM_SERVICE="php${PHP_VER}-fpm"

# ── Структура директорий ────────────────────────────────────────────────────
log_info "Создание структуры директорий..."
mkdir -p /var/www/panel/db
mkdir -p /var/www/decoy
mkdir -p /etc/nginx/conf.d/domains
mkdir -p /etc/caddy
mkdir -p /etc/xray
mkdir -p /etc/mita
mkdir -p /etc/hysteria

touch /etc/nginx/conf.d/domains.map

log_info "Сгенерированы секретные пути защиты:"
log_info "-> Ссылка на панель управления: /${ADMIN_SECRET}"

cat > /var/www/panel/db/paths.json << EOF
{
    "admin_path": "${ADMIN_SECRET}",
    "admin_port": "${ADMIN_PORT}",
    "xhttp_path": "${XHTTP_PATH}"
}
EOF

# ── Установка Caddy с NaiveProxy ────────────────────────────────────────────
log_info "Установка Caddy с поддержкой NaiveProxy..."
log_info "Сборка Caddy через xcaddy..."
apt-get install -y golang
export GOPATH=$HOME/go
export PATH=$PATH:$GOPATH/bin
if ! command -v xcaddy &> /dev/null; then
    go install github.com/caddyserver/xcaddy/cmd/xcaddy@latest
fi
if GOWORK=off xcaddy build --with github.com/caddyserver/forwardproxy@caddy2=github.com/klzgrad/forwardproxy@naive; then
    mv caddy /usr/local/bin/caddy
    chmod +x /usr/local/bin/caddy
    log_success "Caddy успешно собран и установлен."
else
    log_error "Сбой при установке Caddy."
fi

# ── Установка Xray Core ─────────────────────────────────────────────────────
log_info "Установка Xray Core..."
export XRAY_INSTALL_NONINTERACTIVE=1
bash -c "$(curl -L https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" install
usermod -aG www-data xray || true

# ── Установка Mieru (mita) ─────────────────────────────────────────────────
log_info "Установка Mieru (mita)..."
MITA_VERSION=$(curl -s https://api.github.com/repos/enfein/mieru/releases/latest | grep tag_name | cut -d '"' -f 4 | sed 's/v//')
MITA_DEB="mita_${MITA_VERSION}_amd64.deb"
if wget -q "https://github.com/enfein/mieru/releases/download/v${MITA_VERSION}/${MITA_DEB}"; then
    dpkg -i "$MITA_DEB" || apt-get install -f -y
    rm -f "$MITA_DEB"
else
    log_error "Не удалось скачать пакет Mieru."
fi

# ── Установка Hysteria 2 ───────────────────────────────────────────────────
log_info "Установка Hysteria 2..."
bash -c "$(curl -fsSL https://get.hy2.sh)"
usermod -aG www-data hysteria || true

# ── Nginx: stream block ─────────────────────────────────────────────────────
log_info "Создание базовых конфигурационных файлов Nginx..."

if ! grep -q "stream {" /etc/nginx/nginx.conf; then
    cat >> /etc/nginx/nginx.conf << 'EOF'

stream {
    log_format proxy '$remote_addr [$time_local] '
                     '$protocol $status $bytes_sent $bytes_received '
                     '$session_time "$ssl_preread_server_name"';

    access_log /var/log/nginx/stream_access.log proxy;
    error_log /var/log/nginx/stream_error.log;

    map $ssl_preread_server_name $backend_tcp {
        hostnames;
        include /etc/nginx/conf.d/domains.map;
        "" nginx_mita;
        default xray_reality;
    }

    upstream xray_reality { server 127.0.0.1:10002; }
    upstream nginx_web { server 127.0.0.1:8443; }
    upstream nginx_mita { server 127.0.0.1:8081; }
    upstream caddy_naive { server 127.0.0.1:7443; }
    upstream xray_xhttp { server 127.0.0.1:10001; }

    server {
        listen 0.0.0.0:443 reuseport;
        ssl_preread on;
        proxy_pass $backend_tcp;
    }
}
EOF
fi

# ── Nginx: dummy SSL cert ───────────────────────────────────────────────────
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/nginx/conf.d/dummy.key \
    -out /etc/nginx/conf.d/dummy.crt \
    -subj '/CN=localhost' 2>/dev/null

# ── Nginx: default site ─────────────────────────────────────────────────────
cat > /etc/nginx/sites-available/default << EOF
server {
    listen 0.0.0.0:80 default_server;
    server_name _;
    location /.well-known/acme-challenge/ { root /var/www/html; allow all; }
    location / { return 301 https://\$host\$request_uri; }
}

server {
    listen 127.0.0.1:8443 ssl http2 default_server;
    server_name _;
    ssl_certificate /etc/nginx/conf.d/dummy.crt;
    ssl_certificate_key /etc/nginx/conf.d/dummy.key;
    location / { return 403; }
}

server {
    listen 127.0.0.1:8080 default_server;
    server_name _;
    root /var/www;
    index index.php index.html;

    location /${ADMIN_SECRET}/assets/ {
        rewrite ^/${ADMIN_SECRET}/assets/(.*)$ /panel/assets/\$1 break;
    }

    location /${ADMIN_SECRET}/ {
        root /var/www/panel;
        rewrite ^/${ADMIN_SECRET}/(.*)$ /\$1 break;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${PHP_SOCK};
    }
    location / { return 403; }
}

server {
    listen 0.0.0.0:${ADMIN_PORT} default_server;
    server_name _;
    root /var/www;
    index index.php index.html;

    location /${ADMIN_SECRET}/assets/ {
        rewrite ^/${ADMIN_SECRET}/assets/(.*)$ /panel/assets/\$1 break;
    }

    location /${ADMIN_SECRET}/ {
        root /var/www/panel;
        rewrite ^/${ADMIN_SECRET}/(.*)$ /\$1 break;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${PHP_SOCK};
    }
    location / { return 403; }
}
EOF

# ── Caddy: дефолтный конфиг ─────────────────────────────────────────────────
cat > /etc/caddy/Caddyfile << EOF
{
    admin 127.0.0.1:2019
    auto_https off
    order forward_proxy before file_server
}
:7443 {
    forward_proxy {
        basic_auth placeholder placeholder_pass
        hide_ip
        hide_via
        probe_resistance
    }
    file_server {
        root /var/www/decoy
    }
}
EOF

# ── Caddy: systemd service ─────────────────────────────────────────────────
cat > /etc/systemd/system/caddy.service << 'EOF'
[Unit]
Description=Caddy for NaiveProxy
After=network.target network-online.target
Requires=network-online.target

[Service]
Type=notify
User=root
Group=root
ExecStart=/usr/local/bin/caddy run --environ --config /etc/caddy/Caddyfile
ExecReload=/usr/local/bin/caddy reload --config /etc/caddy/Caddyfile --force
TimeoutStopSec=5s
LimitNOFILE=1048576
LimitNPROC=512
PrivateTmp=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
EOF

# ── Xray: Reality ключи и конфигурация ──────────────────────────────────────
log_info "Генерация Xray Reality ключей..."
REALITY_KEYS=$(/usr/local/bin/xray x25519)
PRIVATE_KEY=$(echo "$REALITY_KEYS" | grep -i "privatekey" | awk -F': ' '{print $2}' | tr -d ' \r\n')
PUBLIC_KEY=$(echo "$REALITY_KEYS" | grep -i "publickey" | awk -F': ' '{print $2}' | tr -d ' \r\n')
echo "$PUBLIC_KEY" > /etc/xray/public.key
chmod 644 /etc/xray/public.key

cat > /etc/xray/config.json << EOF
{
  "log": { "loglevel": "warning" },
  "inbounds": [
    {
      "port": 10001,
      "listen": "127.0.0.1",
      "protocol": "vless",
      "settings": { "clients": [], "decryption": "none" },
      "streamSettings": {
        "network": "xhttp",
        "security": "none",
        "xhttpSettings": { "path": "/${XHTTP_PATH}/" }
      }
    },
    {
      "port": 10002,
      "listen": "127.0.0.1",
      "protocol": "vless",
      "settings": { "clients": [], "decryption": "none", "fallbacks": [{ "dest": 8080, "xver": 0 }] },
      "streamSettings": {
        "network": "tcp",
        "security": "reality",
        "realitySettings": {
          "show": false, "dest": "yahoo.com:443", "xver": 0,
          "serverNames": [ "yahoo.com", "www.yahoo.com" ],
          "privateKey": "${PRIVATE_KEY}",
          "shortIds": [ "0123456789abcdef" ]
        }
      }
    }
  ],
  "outbounds": [{ "protocol": "freedom" }]
}
EOF
mkdir -p /usr/local/etc/xray
ln -sf /etc/xray/config.json /usr/local/etc/xray/config.json

# ── Mieru (mita): конфигурация ─────────────────────────────────────────────
cat > /etc/mita/config.json << 'EOF'
{
  "portBindings": [{ "port": 8081, "protocol": "TCP" }],
  "users": [{ "name": "placeholder", "password": "placeholder_pass_12345" }],
  "loggingLevel": "INFO"
}
EOF

mkdir -p /etc/systemd/system/mita.service.d
cat > /etc/systemd/system/mita.service.d/10-config.conf << 'EOF'
[Service]
Environment="MITA_CONFIG_JSON_FILE=/etc/mita/config.json"
EOF

# ── Hysteria 2: самоподписанный cert для первого старта ─────────────────────
log_info "Генерация временного SSL для первого старта Hysteria 2..."
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/hysteria/selfsigned.key \
    -out /etc/hysteria/selfsigned.crt \
    -subj '/CN=localhost'

cat > /etc/hysteria/config.yaml << EOF
listen: 0.0.0.0:443
auth:
  type: http
  http:
    url: http://127.0.0.1:8080/${ADMIN_SECRET}/api/hysteria_auth.php
tls:
  cert: /etc/hysteria/selfsigned.crt
  key: /etc/hysteria/selfsigned.key
obfs:
  type: salamander
  salamander:
    password: "CHANGE_SALAMANDER_OBFS_PASSWORD"
quic:
  initConnFlowControlWindow: 8388608
  maxConnFlowControlWindow: 25165824
  initStreamFlowControlWindow: 2097152
  maxStreamFlowControlWindow: 8388608
EOF

SALAMANDER_PASS=$(openssl rand -hex 16)
sed -i "s/CHANGE_SALAMANDER_OBFS_PASSWORD/$SALAMANDER_PASS/g" /etc/hysteria/config.yaml

# ── Sudoers ─────────────────────────────────────────────────────────────────
log_info "Настройка расширенных правил sudoers..."
cat > /etc/sudoers.d/panel << 'EOF'
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload nginx
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart nginx
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart xray
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart caddy
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart mita
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart hysteria-server
www-data ALL=(ALL) NOPASSWD: /usr/bin/mita reload
www-data ALL=(ALL) NOPASSWD: /usr/bin/certbot certonly *
www-data ALL=(ALL) NOPASSWD: /usr/bin/chgrp -R www-data /etc/letsencrypt
www-data ALL=(ALL) NOPASSWD: /usr/bin/chmod -R g+rX /etc/letsencrypt
www-data ALL=(ALL) NOPASSWD: /bin/bash /var/www/panel/update_cores.sh *
EOF
echo "Defaults:www-data !use_pty" > /etc/sudoers.d/panel_pty
chmod 440 /etc/sudoers.d/panel_pty
chmod 440 /etc/sudoers.d/panel

# ── Certbot renewal hook ───────────────────────────────────────────────────
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/01-permissions.sh << 'EOF'
#!/bin/sh
chgrp -R www-data /etc/letsencrypt || true
chmod -R g+rX /etc/letsencrypt || true
EOF
chmod +x /etc/letsencrypt/renewal-hooks/deploy/01-permissions.sh

# ── Копирование панели ─────────────────────────────────────────────────────
log_info "Копирование исходных файлов веб-интерфейса панели..."
if [ -d "panel_src" ]; then
    cp -pRL panel_src/. /var/www/panel/
else
    log_info "Локальная папка panel_src не найдена. Клонирование из GitHub репозитория..."
    TEMP_DIR=$(mktemp -d)
    if git clone https://github.com/pasnya/vpn-panel-5x-uev.git "$TEMP_DIR"; then
        cp -pRL "$TEMP_DIR/panel_src/." /var/www/panel/
        rm -rf "$TEMP_DIR"
        log_success "Исходные файлы панели успешно скачаны и скопированы в /var/www/panel."
    else
        rm -rf "$TEMP_DIR"
        log_error "Не удалось скачать файлы панели из GitHub репозитория."
    fi
fi

# ── SQLite3: инициализация БД ───────────────────────────────────────────────
log_info "Инициализация SQLite3..."
if [ ! -f /var/www/panel/db/panel.db ]; then
    sqlite3 /var/www/panel/db/panel.db << 'EOF'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    uuid TEXT NOT NULL,
    role TEXT DEFAULT 'client',
    status TEXT DEFAULT 'active',
    traffic_limit INTEGER DEFAULT 0,
    traffic_used INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME
);
CREATE TABLE IF NOT EXISTS client_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    protocol TEXT,
    domain_id INTEGER,
    credential_key TEXT,
    status TEXT DEFAULT 'active',
    FOREIGN KEY(user_id) REFERENCES users(id),
    FOREIGN KEY(domain_id) REFERENCES domains(id)
);
CREATE TABLE IF NOT EXISTS domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_name TEXT UNIQUE NOT NULL,
    decoy_type TEXT DEFAULT 'blog',
    ssl_status TEXT DEFAULT 'none',
    admin_path TEXT NOT NULL,
    naive_sub TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
EOF

    ADMIN_PASS_HASH=$(php -r "echo password_hash('admin_password_change_me', PASSWORD_DEFAULT);")
    ADMIN_UUID=$(cat /proc/sys/kernel/random/uuid)
    sqlite3 /var/www/panel/db/panel.db "INSERT OR IGNORE INTO users (username, password, uuid, role) VALUES ('admin', '$ADMIN_PASS_HASH', '$ADMIN_UUID', 'admin');"
else
    log_info "База данных уже существует, пропуск инициализации."
fi

# ── Права на файлы ─────────────────────────────────────────────────────────
log_info "Настройка прав доступа..."
chown -R root:www-data /etc/caddy /etc/xray /etc/mita /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/conf.d /etc/hysteria
chmod -R 775 /etc/caddy /etc/xray /etc/mita /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/conf.d /etc/hysteria
chmod 664 /etc/caddy/Caddyfile /etc/xray/config.json /etc/mita/config.json /etc/nginx/conf.d/domains.map /etc/hysteria/config.yaml || true

chown -R www-data:www-data /var/www/panel
chown -R www-data:www-data /var/www/decoy
chmod -R 775 /var/www/panel/db
chmod 664 /var/www/panel/db/panel.db || true

echo "<h1>Welcome to default decoy page</h1>" > /var/www/html/index.html
chown www-data:www-data /var/www/html/index.html

# ── Запуск служб ───────────────────────────────────────────────────────────
log_info "Запуск системных служб..."
systemctl daemon-reload
systemctl enable nginx "$PHP_FPM_SERVICE" caddy xray mita hysteria-server
systemctl restart nginx "$PHP_FPM_SERVICE" caddy xray mita hysteria-server

chgrp -R www-data /etc/letsencrypt || true
chmod -R g+rX /etc/letsencrypt || true

# ── Sysctl ──────────────────────────────────────────────────────────────────
log_info "Настройка sysctl: включение пересылки пакетов и BBR..."
sysctl -w net.ipv4.ip_forward=1
sysctl -w net.ipv6.conf.all.forwarding=1
sysctl -w net.core.default_qdisc=fq
sysctl -w net.ipv4.tcp_congestion_control=bbr

grep -q "net.ipv4.ip_forward=1" /etc/sysctl.conf || echo "net.ipv4.ip_forward=1" >> /etc/sysctl.conf
grep -q "net.ipv6.conf.all.forwarding=1" /etc/sysctl.conf || echo "net.ipv6.conf.all.forwarding=1" >> /etc/sysctl.conf
grep -q "net.core.default_qdisc=fq" /etc/sysctl.conf || echo "net.core.default_qdisc=fq" >> /etc/sysctl.conf
grep -q "net.ipv4.tcp_congestion_control=bbr" /etc/sysctl.conf || echo "net.ipv4.tcp_congestion_control=bbr" >> /etc/sysctl.conf

# ── iptables: Port Hopping для Hysteria ─────────────────────────────────────
log_info "Настройка iptables для Hysteria 2 Port Hopping..."
iptables -t nat -D PREROUTING -p udp --dport 20000:50000 -j REDIRECT --to-ports 443 2>/dev/null || true
iptables -t nat -A PREROUTING -p udp --dport 20000:50000 -j REDIRECT --to-ports 443
netfilter-persistent save

# ── Итог ────────────────────────────────────────────────────────────────────
IPV4_SERVER=$(curl -4 -s --max-time 10 ifconfig.me || echo "IP_СЕРВЕРА")

log_success "Установка завершена успешно!"
echo -e "\n=================================================================="
echo -e "Reality Public Key: $PUBLIC_KEY"
echo -e "Прямой доступ к панели по внешнему IP-адресу сервера:"
echo -e "URL: http://${IPV4_SERVER}:${ADMIN_PORT}/${ADMIN_SECRET}/index.php"
echo -e "Логин: admin"
echo -e "Пароль: admin_password_change_me"
echo -e "=================================================================="
