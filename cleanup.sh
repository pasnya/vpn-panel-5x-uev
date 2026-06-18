#!/usr/bin/env bash
# ==============================================================================
# Скрипт полной очистки сервера от установленных прокси-служб и панели
# Сертификаты Let's Encrypt СОХРАНЯЮТСЯ для повторной установки
# ==============================================================================

set -uo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0;0m'

log_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[WARNING]${NC} $1"; }

if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}[ERROR]${NC} Запусти от root: sudo bash cleanup.sh"
    exit 1
fi

echo "========================================"
echo "  Очистка VPN Panel"
echo "  Сертификаты будут сохранены"
echo "========================================"
echo ""

# 1. Останавливаем и отключаем службы
log_info "Остановка служб..."
for srv in nginx caddy xray mita hysteria-server; do
    systemctl stop "$srv" 2>/dev/null || true
    systemctl disable "$srv" 2>/dev/null || true
done

# php-fpm
for php_srv in $(systemctl list-unit-files --type=service --state=enabled --no-legend | grep 'php.*-fpm' | awk '{print $1}'); do
    log_info "Остановка: $php_srv"
    systemctl stop "$php_srv" 2>/dev/null || true
    systemctl disable "$php_srv" 2>/dev/null || true
done

# 2. Удаляем systemd конфиги
log_info "Удаление systemd конфигов..."
rm -f /etc/systemd/system/caddy.service
rm -f /etc/systemd/system/hysteria-server.service
rm -f /etc/systemd/system/hysteria-server@.service
rm -f /etc/systemd/system/xray.service
rm -f /etc/systemd/system/xray@.service
rm -rf /etc/systemd/system/xray.service.d
rm -rf /etc/systemd/system/mita.service.d
systemctl daemon-reload

# 3. Удаляем бинарники
log_info "Удаление бинарников..."
rm -f /usr/local/bin/caddy
rm -f /usr/local/bin/xray
rm -f /usr/local/bin/hysteria
rm -f /usr/bin/mita
dpkg -r mita 2>/dev/null || true
apt-get purge -y mita 2>/dev/null || true

# 4. Удаляем конфиги служб и веб-контент
log_info "Удаление конфигов и данных..."
rm -rf /etc/caddy
rm -rf /etc/xray
rm -rf /usr/local/etc/xray
rm -rf /var/log/xray
rm -rf /etc/mita
rm -rf /etc/hysteria
rm -rf /var/www/panel
rm -rf /var/www/decoy

# sudoers
rm -f /etc/sudoers.d/panel
rm -f /etc/sudoers.d/panel_pty

# certbot hook (сертификаты сохраняем!)
rm -f /etc/letsencrypt/renewal-hooks/deploy/01-permissions.sh

# 5. Очищаем Nginx
log_info "Очистка Nginx..."
rm -f /etc/nginx/conf.d/domains.map
rm -f /etc/nginx/conf.d/dummy.key
rm -f /etc/nginx/conf.d/dummy.crt
rm -rf /etc/nginx/conf.d/domains
rm -rf /etc/nginx/sites-enabled/*
rm -rf /etc/nginx/sites-available/*

apt-get purge -y nginx nginx-common nginx-extras libnginx-mod-stream 2>/dev/null || true
apt-get autoremove -y
apt-get clean

# Ставим чистый Nginx
apt-get install -y nginx libnginx-mod-stream

# 6. Очистка логов
log_info "Очистка логов..."
rm -f /var/log/nginx/stream_access.log
rm -f /var/log/nginx/stream_error.log
rm -f /var/log/nginx/access.log
# 7. Очистка правил iptables
log_info "Очистка правил iptables..."
iptables -t nat -D PREROUTING -p udp --dport 20000:50000 -j REDIRECT --to-ports 443 2>/dev/null || true
if command -v netfilter-persistent &> /dev/null; then
    netfilter-persistent save 2>/dev/null || true
fi

log_success "Сервер очищен!"
echo ""
echo -e "${YELLOW}Сертификаты Let\'s Encrypt сохранены в /etc/letsencrypt/${NC}"
echo -e "${YELLOW}Повторная установка не вызовет спам от certbot.${NC}"
