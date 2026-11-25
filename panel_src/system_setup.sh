#!/usr/bin/env bash
# Системная часть панели: root-хелперы, sudoers, cron, таймер самообновления и права на файлы.
# Запускается от root установщиком (без аргументов) и самообновлением (аргумент - прежняя
# версия или unknown). Повторный запуск безопасен.
set -euo pipefail

PANEL_DIR=/var/www/panel
PREV_VERSION=${1:-}

[[ $EUID -eq 0 ]] || { echo 'Run as root' >&2; exit 1; }
[[ -f $PANEL_DIR/index.php && -d $PANEL_DIR/db ]] || { echo "Panel not found in $PANEL_DIR" >&2; exit 1; }

# put_file РЕЖИМ ПУТЬ < содержимое. Через rename: работающий скрипт не перепишется на лету.
# Временное имя с точкой: sudo и cron такие файлы в своих каталогах пропускают.
put_file() {
    local mode=$1 dest=$2 tmp
    tmp=$(mktemp "$(dirname "$dest")/.$(basename "$dest").XXXXXX")
    cat > "$tmp"
    chown root:root "$tmp"
    chmod "$mode" "$tmp"
    if [[ $dest == /etc/sudoers.d/* ]] && ! visudo -cf "$tmp" >/dev/null; then
        rm -f "$tmp"
        echo "Invalid sudoers file for $dest" >&2
        return 1
    fi
    mv -f "$tmp" "$dest"
}

# ── Root-хелперы ─────────────────────────────────────────────────────────────
put_file 755 /usr/local/sbin/vpn-update-cores < "$PANEL_DIR/update_cores.sh"
put_file 755 /usr/local/sbin/vpn-nginx-sync < "$PANEL_DIR/nginx_sync.sh"
put_file 755 /usr/local/sbin/vpn-panel-update < "$PANEL_DIR/panel_update.sh"
put_file 755 /usr/local/sbin/vpn-certbot-helper << 'EOF'
#!/usr/bin/env bash
set -euo pipefail
[[ $# -eq 2 ]] || exit 2
for domain in "$@"; do
    [[ "$domain" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?\.[a-zA-Z]{2,}$ ]] || exit 2
done
/usr/bin/certbot certonly --webroot -w /var/www/html -d "$1" -d "$2" --keep-until-expiring --non-interactive --agree-tos --register-unsafely-without-email --preferred-challenges http
/usr/bin/chgrp -R www-data /etc/letsencrypt
/usr/bin/chmod -R g+rX /etc/letsencrypt
EOF

# ── sudoers ──────────────────────────────────────────────────────────────────
put_file 440 /etc/sudoers.d/panel << 'EOF'
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload nginx
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart xray
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart caddy
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload caddy
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart mita
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart hysteria-server
www-data ALL=(ALL) NOPASSWD: /usr/bin/mita reload
www-data ALL=(root) NOPASSWD: /usr/local/sbin/vpn-certbot-helper *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/vpn-nginx-sync ""
www-data ALL=(root) NOPASSWD: /usr/local/sbin/vpn-update-cores update xray, /usr/local/sbin/vpn-update-cores update hysteria, /usr/local/sbin/vpn-update-cores update mita, /usr/local/sbin/vpn-update-cores update caddy
www-data ALL=(root) NOPASSWD: /usr/local/sbin/vpn-panel-update check, /usr/local/sbin/vpn-panel-update start, /usr/local/sbin/vpn-panel-update enable-auto, /usr/local/sbin/vpn-panel-update disable-auto
www-data ALL=(root) NOPASSWD: /usr/bin/journalctl -u nginx -n 100 --no-pager -o short-iso, /usr/bin/journalctl -u caddy -n 100 --no-pager -o short-iso, /usr/bin/journalctl -u xray -n 100 --no-pager -o short-iso, /usr/bin/journalctl -u mita -n 100 --no-pager -o short-iso, /usr/bin/journalctl -u hysteria-server -n 100 --no-pager -o short-iso
EOF
put_file 440 /etc/sudoers.d/panel_pty <<< 'Defaults:www-data !use_pty'

# ── cron ─────────────────────────────────────────────────────────────────────
put_file 644 /etc/cron.d/vpn-panel-expiry <<< '*/5 * * * * www-data /usr/bin/php /var/www/panel/expire_clients.php >/dev/null 2>&1'
put_file 644 /etc/cron.d/vpn-panel-traffic <<< '*/5 * * * * www-data /usr/bin/php /var/www/panel/traffic_monitor.php >/dev/null 2>&1'
put_file 644 /etc/cron.d/vpn-panel-relay <<< '* * * * * www-data /usr/bin/php /var/www/panel/relay_sync.php >/dev/null 2>&1'
put_file 644 /etc/cron.d/vpn-panel-cert <<< '* * * * * www-data /usr/bin/php /var/www/panel/issue_domains.php >/dev/null 2>&1'

# ── systemd: самообновление и группа для Xray ────────────────────────────────
put_file 644 /etc/systemd/system/vpn-panel-update.service << 'EOF'
[Unit]
Description=VPN Panel update
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/vpn-panel-update apply
TimeoutStartSec=15min
EOF
put_file 644 /etc/systemd/system/vpn-panel-update-auto.service << 'EOF'
[Unit]
Description=VPN Panel automatic update
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/vpn-panel-update auto
TimeoutStartSec=15min
EOF
put_file 644 /etc/systemd/system/vpn-panel-update-auto.timer << 'EOF'
[Unit]
Description=Daily VPN Panel update check

[Timer]
OnCalendar=*-*-* 04:00
RandomizedDelaySec=4h
Persistent=true

[Install]
WantedBy=timers.target
EOF
# Xray-install запускает xray от nobody: даём процессу группу www-data для чтения конфига.
mkdir -p /etc/systemd/system/xray.service.d
XRAY_DROPIN_NEW=0
[[ -f /etc/systemd/system/xray.service.d/20-panel.conf ]] || XRAY_DROPIN_NEW=1
put_file 644 /etc/systemd/system/xray.service.d/20-panel.conf << 'EOF'
[Service]
SupplementaryGroups=www-data
EOF
systemctl daemon-reload
systemctl enable --now vpn-panel-update-auto.timer >/dev/null 2>&1 || true

# ── Код панели только для чтения, писать www-data может лишь в db/ ───────────
chown -R root:www-data "$PANEL_DIR"
find "$PANEL_DIR" -type d -exec chmod 750 {} +
find "$PANEL_DIR" -type f -exec chmod 640 {} +
chown -R www-data:www-data "$PANEL_DIR/db"
chmod 700 "$PANEL_DIR/db"
find "$PANEL_DIR/db" -type f -exec chmod 600 {} +

# ── Переход со старых версий (до v0.9.7 или без файла VERSION) ───────────────
version_lt() { [[ $1 != "$2" && $(printf '%s\n%s\n' "${1#v}" "${2#v}" | sort -V | head -n 1) == "${1#v}" ]]; }
if [[ -n $PREV_VERSION ]] && { [[ $PREV_VERSION == unknown ]] || version_lt "$PREV_VERSION" v0.9.7; }; then
    echo 'Перенос настроек со старой версии панели'
    # Конфиги ядер: панель их переписывает, службы читают через группу www-data.
    for dir in /etc/caddy /etc/xray /etc/mita /etc/hysteria; do
        [[ -d $dir ]] || continue
        chown -R root:www-data "$dir"
        find "$dir" -type d -exec chmod 750 {} +
        find "$dir" -type f -exec chmod 660 {} +
    done
    chmod 640 /etc/hysteria/selfsigned.key /etc/hysteria/selfsigned.crt 2>/dev/null || true
    chmod 664 /etc/xray/public.key 2>/dev/null || true
    # nginx принадлежит только root: конфиги доменов пишет vpn-nginx-sync.
    chown -R root:root /etc/nginx/sites-available /etc/nginx/sites-enabled /etc/nginx/conf.d
    # Заглушка для fallback Reality (в v0.9.7+ она уже есть в сайте default).
    if ! grep -rqs '127.0.0.1:8090' /etc/nginx/sites-enabled /etc/nginx/conf.d; then
        printf '%s\n' 'server {' '    listen 127.0.0.1:8090 default_server;' '    server_name _;' '    location / { return 404; }' '}' \
            | put_file 644 /etc/nginx/conf.d/reality-fallback.conf
        if nginx -t >/dev/null 2>&1; then
            systemctl reload nginx
        else
            rm -f /etc/nginx/conf.d/reality-fallback.conf
        fi
    fi
    # Старая схема WARP: warp-cli, redsocks и правила iptables по UID служб.
    if command -v iptables-legacy >/dev/null; then
        for owner in vpn mita hysteria; do
            for proto in tcp udp; do
                while iptables-legacy -t nat -D OUTPUT ! -o lo -m owner --uid-owner "$owner" -p "$proto" -j REDIRECT --to-ports 12345 2>/dev/null; do :; done
            done
        done
        netfilter-persistent save >/dev/null 2>&1 || true
    fi
    systemctl disable --now warp-svc redsocks >/dev/null 2>&1 || true
    [[ $XRAY_DROPIN_NEW == 1 ]] && systemctl restart xray
fi
exit 0
