#!/usr/bin/env bash
# Отдельный сервер-прокладка: OpenVPN -> проверенный прокси-канал -> основной VPN-сервер.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

PANEL_REF=${PANEL_REF:-v0.7.0}
RELAY_DIR=/opt/vpn-relay
EASY_DIR=/etc/openvpn/easy-rsa
PANEL_PORT=8443
OPENVPN_PORT=51820

[[ $EUID -eq 0 ]] || { echo 'Запустите установщик от root.' >&2; exit 1; }
. /etc/os-release
[[ $ID == ubuntu && $VERSION_ID =~ ^(20\.04|22\.04|24\.04)$ ]] || { echo 'Поддерживается Ubuntu 20.04/22.04/24.04.' >&2; exit 1; }
case "$(uname -m)" in x86_64|aarch64) ;; *) echo 'Нужен сервер x86_64 или arm64.' >&2; exit 1 ;; esac
[[ ! -e "$RELAY_DIR/settings.json" ]] || { echo 'Прокладка уже установлена. Повторная установка не перезаписывает ключи.' >&2; exit 1; }

echo 'Устанавливаю OpenVPN и панель прокладки...'
apt-get update
apt-get install -y openvpn easy-rsa redsocks curl git jq openssl ca-certificates iptables-persistent netfilter-persistent
if ! command -v node >/dev/null || [[ $(node -p 'process.versions.node.split(".")[0]') -lt 18 ]]; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

if [[ ! -x /usr/local/bin/xray ]]; then
    export XRAY_INSTALL_NONINTERACTIVE=1
    bash -c "$(curl -fsSL https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" install
fi

mkdir -p "$RELAY_DIR/configs" /var/log/openvpn
chmod 700 "$RELAY_DIR" "$RELAY_DIR/configs"
SOURCE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
if [[ ! -f "$SOURCE_DIR/server.js" ]]; then
    SOURCE_DIR=$(mktemp -d)
    git clone --depth 1 --branch "$PANEL_REF" https://github.com/pasnya/vpn-panel-5x-uev.git "$SOURCE_DIR/source"
    SOURCE_DIR="$SOURCE_DIR/source/proxy_panel"
fi
install -m 700 "$SOURCE_DIR/server.js" "$RELAY_DIR/server.js"
install -m 700 "$SOURCE_DIR/balancer.sh" "$RELAY_DIR/balancer.sh"
install -m 700 "$SOURCE_DIR/check-client.sh" "$RELAY_DIR/check-client.sh"
printf '{}\n' > "$RELAY_DIR/clients.json"
chmod 600 "$RELAY_DIR/clients.json"

make-cadir "$EASY_DIR"
cd "$EASY_DIR"
export EASYRSA_BATCH=1 EASYRSA_PKI="$EASY_DIR/pki"
./easyrsa init-pki
./easyrsa build-ca nopass
./easyrsa build-server-full server nopass
./easyrsa gen-dh
./easyrsa gen-crl
chmod 600 "$EASY_DIR/pki/private/ca.key" "$EASY_DIR/pki/private/server.key"
install -m 644 "$EASY_DIR/pki/crl.pem" /etc/openvpn/crl.pem
openvpn --genkey secret "$EASY_DIR/pki/ta.key"
chmod 600 "$EASY_DIR/pki/ta.key"

cat > /etc/openvpn/server.conf <<OPENVPN
port $OPENVPN_PORT
proto udp
dev tun
topology subnet
server 10.8.0.0 255.255.255.0
server-ipv6 fd15:53b6:dead::/64
ca $EASY_DIR/pki/ca.crt
cert $EASY_DIR/pki/issued/server.crt
key $EASY_DIR/pki/private/server.key
dh $EASY_DIR/pki/dh.pem
tls-crypt $EASY_DIR/pki/ta.key
crl-verify /etc/openvpn/crl.pem
script-security 2
client-connect $RELAY_DIR/check-client.sh
push "redirect-gateway def1"
push "redirect-gateway ipv6"
push "dhcp-option DNS 1.1.1.1"
push "dhcp-option DNS 8.8.8.8"
block-ipv6
keepalive 10 120
data-ciphers AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305
persist-key
persist-tun
status /var/log/openvpn/status.log
verb 3
OPENVPN

cat > "$RELAY_DIR/redsocks.conf.template" <<'REDSOCKS'
base {
    log_debug = off;
    log_info = on;
    log = "syslog:daemon";
    daemon = off;
    redirector = iptables;
}
redsocks {
    local_ip = 0.0.0.0;
    local_port = 12345;
    ip = 127.0.0.1;
    port = __SOCKS_PORT__;
    type = socks5;
}
dnstc {
    local_ip = 0.0.0.0;
    local_port = 12346;
}
REDSOCKS
sed 's/__SOCKS_PORT__/40004/' "$RELAY_DIR/redsocks.conf.template" > "$RELAY_DIR/redsocks.conf"
mkdir -p /etc/systemd/system/redsocks.service.d
cat > /etc/systemd/system/redsocks.service.d/vpn-relay.conf <<'SYSTEMD'
[Service]
Type=simple
ExecStart=
ExecStart=/usr/sbin/redsocks -c /opt/vpn-relay/redsocks.conf
SYSTEMD

cat > /etc/systemd/system/vpn-relay-xray@.service <<'SYSTEMD'
[Unit]
Description=VPN relay Xray channel %i
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/xray run -config /opt/vpn-relay/configs/%i.json
Restart=on-failure
RestartSec=3
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
SYSTEMD

cat > /etc/systemd/system/vpn-relay-balancer.service <<'SYSTEMD'
[Unit]
Description=Check VPN relay channels
After=network-online.target

[Service]
Type=oneshot
ExecStart=/opt/vpn-relay/balancer.sh
SYSTEMD
cat > /etc/systemd/system/vpn-relay-balancer.timer <<'SYSTEMD'
[Unit]
Description=Check VPN relay channels every minute

[Timer]
OnBootSec=20s
OnUnitActiveSec=60s
AccuracySec=5s

[Install]
WantedBy=timers.target
SYSTEMD

# Перенаправленные соединения попадают в локальный прокси. Любой другой
# форвардинг из OpenVPN запрещён: при отказе канала прямого выхода нет.
iptables -t nat -C PREROUTING -i tun0 -p tcp -j REDIRECT --to-ports 12345 2>/dev/null || iptables -t nat -I PREROUTING 1 -i tun0 -p tcp -j REDIRECT --to-ports 12345
iptables -t nat -C PREROUTING -i tun0 -p udp --dport 53 -j REDIRECT --to-ports 12346 2>/dev/null || iptables -t nat -I PREROUTING 1 -i tun0 -p udp --dport 53 -j REDIRECT --to-ports 12346
iptables -C FORWARD -i tun0 -j REJECT 2>/dev/null || iptables -I FORWARD 1 -i tun0 -j REJECT
iptables -C INPUT -i tun0 -p tcp --dport 12345 -j ACCEPT 2>/dev/null || iptables -I INPUT 1 -i tun0 -p tcp --dport 12345 -j ACCEPT
iptables -C INPUT -i tun0 -p udp --dport 12346 -j ACCEPT 2>/dev/null || iptables -I INPUT 1 -i tun0 -p udp --dport 12346 -j ACCEPT
iptables -C INPUT ! -i tun0 -p tcp --dport 12345 -j DROP 2>/dev/null || iptables -I INPUT 1 ! -i tun0 -p tcp --dport 12345 -j DROP
iptables -C INPUT ! -i tun0 -p udp --dport 12346 -j DROP 2>/dev/null || iptables -I INPUT 1 ! -i tun0 -p udp --dport 12346 -j DROP
netfilter-persistent save >/dev/null

PUBLIC_IP=$(curl -4fsS --max-time 8 https://api.ipify.org)
[[ "$PUBLIC_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || { echo 'Не удалось определить внешний IPv4.' >&2; exit 1; }
ADMIN_PASSWORD=$(openssl rand -hex 16)
API_TOKEN=$(openssl rand -hex 32)
openssl req -x509 -newkey rsa:3072 -sha256 -nodes -days 3650 \
    -keyout "$RELAY_DIR/tls.key" -out "$RELAY_DIR/tls.crt" \
    -subj "/CN=$PUBLIC_IP" -addext "subjectAltName=IP:$PUBLIC_IP" >/dev/null 2>&1
chmod 600 "$RELAY_DIR/tls.key"
PASSWORD_HASH=$(printf '%s' "$ADMIN_PASSWORD" | sha256sum | cut -d' ' -f1)
node - "$RELAY_DIR/settings.json" "$API_TOKEN" "$PASSWORD_HASH" "$PUBLIC_IP" "$PANEL_PORT" "$OPENVPN_PORT" <<'NODE'
const fs = require('fs');
const [file, apiToken, passwordHash, publicIp, panelPort, openvpnPort] = process.argv.slice(2);
fs.writeFileSync(file, JSON.stringify({ apiToken, passwordHash, publicIp, panelPort: Number(panelPort), openvpnPort: Number(openvpnPort) }, null, 2), { mode: 0o600 });
NODE

cat > /etc/systemd/system/vpn-relay-panel.service <<'SYSTEMD'
[Unit]
Description=VPN relay control panel
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/node /opt/vpn-relay/server.js
Restart=on-failure
RestartSec=3
WorkingDirectory=/opt/vpn-relay
UMask=0077

[Install]
WantedBy=multi-user.target
SYSTEMD

systemctl daemon-reload
systemctl enable --now openvpn@server.service vpn-relay-panel.service vpn-relay-balancer.timer
if command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then
    ufw allow "$OPENVPN_PORT/udp"
    ufw allow "$PANEL_PORT/tcp"
fi

PIN=$(openssl x509 -in "$RELAY_DIR/tls.crt" -pubkey -noout | openssl pkey -pubin -outform DER | openssl dgst -sha256 -binary | openssl base64 -A)
PAIR_CODE=$(node -e 'process.stdout.write(Buffer.from(JSON.stringify({host:process.argv[1],port:Number(process.argv[2]),token:process.argv[3],pin:"sha256//"+process.argv[4]})).toString("base64url"))' "$PUBLIC_IP" "$PANEL_PORT" "$API_TOKEN" "$PIN")
echo
echo 'Прокладка установлена.'
echo "Панель: https://$PUBLIC_IP:$PANEL_PORT"
echo "Пароль администратора: $ADMIN_PASSWORD"
echo "OpenVPN: $PUBLIC_IP:$OPENVPN_PORT/udp"
echo 'Вставьте эту строку в основную VPN-панель для связки:'
echo "$PAIR_CODE"
echo 'Сохраните строку и пароль: они выводятся только сейчас.'
