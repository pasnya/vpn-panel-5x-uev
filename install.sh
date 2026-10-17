#!/usr/bin/env bash
# Подготовка веб-установщика. Системные компоненты ставятся только из браузера.
set -euo pipefail

PANEL_REF=${PANEL_REF:-v0.8.0}
INSTALLER_DIR=/opt/vpn-panel-installer
INSTALLER_PORT=8444
BASE_URL="https://raw.githubusercontent.com/pasnya/vpn-panel-5x-uev/${PANEL_REF}"

[[ $EUID -eq 0 ]] || { echo 'Запустите команду от root (sudo).' >&2; exit 1; }
[[ ! -e /var/www/panel/db/panel.db ]] || { echo 'Панель уже установлена. Веб-установщик предназначен для нового сервера.' >&2; exit 1; }
. /etc/os-release
[[ $ID == ubuntu && $VERSION_ID =~ ^(20\.04|22\.04|24\.04)$ ]] || { echo 'Нужна Ubuntu 20.04, 22.04 или 24.04.' >&2; exit 1; }
case "$(uname -m):$VERSION_ID" in
    x86_64:*|aarch64:22.04|aarch64:24.04) ;;
    *) echo 'Неподдерживаемая архитектура для этой версии Ubuntu.' >&2; exit 1 ;;
esac
for tool in curl python3 openssl systemctl; do
    command -v "$tool" >/dev/null || { echo "На сервере отсутствует $tool." >&2; exit 1; }
done

install -d -m 700 "$INSTALLER_DIR"
if [[ ! -s "$INSTALLER_DIR/token" ]]; then
    openssl rand -hex 32 > "$INSTALLER_DIR/token"
    chmod 600 "$INSTALLER_DIR/token"
fi

tmp_script=$(mktemp "$INSTALLER_DIR/full_install.sh.XXXXXX")
tmp_web=$(mktemp "$INSTALLER_DIR/web_installer.py.XXXXXX")
trap 'rm -f "$tmp_script" "$tmp_web"' EXIT
curl -fsSL --retry 3 "$BASE_URL/full_install.sh" -o "$tmp_script"
curl -fsSL --retry 3 "$BASE_URL/web_installer.py" -o "$tmp_web"
bash -n "$tmp_script"
python3 -m py_compile "$tmp_web"
install -m 700 "$tmp_script" "$INSTALLER_DIR/full_install.sh"
install -m 700 "$tmp_web" "$INSTALLER_DIR/web_installer.py"

if [[ ! -s "$INSTALLER_DIR/tls.crt" || ! -s "$INSTALLER_DIR/tls.key" ]]; then
    openssl req -x509 -newkey rsa:3072 -sha256 -nodes -days 30 \
        -keyout "$INSTALLER_DIR/tls.key" -out "$INSTALLER_DIR/tls.crt" \
        -subj '/CN=VPN Panel Installer' >/dev/null 2>&1
    chmod 600 "$INSTALLER_DIR/tls.key"
fi

cat > /etc/systemd/system/vpn-panel-installer.service <<'SYSTEMD'
[Unit]
Description=Temporary VPN Panel web installer
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/python3 /opt/vpn-panel-installer/web_installer.py
WorkingDirectory=/opt/vpn-panel-installer
Restart=on-failure
RestartSec=2
UMask=0077

[Install]
WantedBy=multi-user.target
SYSTEMD
systemctl daemon-reload
systemctl enable --now vpn-panel-installer.service >/dev/null

IP=$(curl -4fsS --max-time 8 https://api.ipify.org || true)
[[ $IP =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || IP='IP_СЕРВЕРА'
TOKEN=$(cat "$INSTALLER_DIR/token")
echo
echo 'Веб-установщик готов. Откройте ссылку в браузере:'
echo "https://${IP}:${INSTALLER_PORT}/?token=${TOKEN}"
echo
echo "Откройте входящий TCP-порт ${INSTALLER_PORT}, если на сервере включён сетевой экран."
echo 'Сертификат временный и самоподписанный: браузер покажет предупреждение.'
echo 'Полная установка начнётся только после подтверждения на странице.'
