#!/bin/bash
set -euo pipefail

ACTION="${1:-check}"
CORE="${2:-all}"

get_latest_version() {
    local repo="$1"
    local tag=""
    for i in 1 2 3; do
        tag=$(curl -sf --connect-timeout 10 --max-time 30 "https://api.github.com/repos/${repo}/releases/latest" 2>/dev/null | jq -r '.tag_name // empty' 2>/dev/null)
        if [ -n "$tag" ]; then
            echo "$tag"
            return
        fi
        sleep 2
    done
    echo "unknown"
}

strip_tag() {
    echo "$1" | sed 's/^v//' | sed 's|^app/||'
}

get_xray_version() {
    /usr/local/bin/xray version 2>/dev/null | awk '{print $2}' | head -1 || echo "not installed"
}

get_hysteria_version() {
    hysteria version 2>/dev/null | grep -oP 'Version:\s+v\K[^\s]+' || echo "not installed"
}

get_mita_version() {
    mita version 2>/dev/null | head -1 || echo "not installed"
}

get_caddy_version() {
    caddy version 2>/dev/null | grep -oP 'v[^\s]+' | head -1 || echo "not installed"
}

do_check() {
    echo "{"
    local first=true

    for core_name in xray hysteria mita caddy; do
        if [ "$CORE" != "all" ] && [ "$CORE" != "$core_name" ]; then
            continue
        fi

        case $core_name in
            xray)
                current=$(get_xray_version)
                raw=$(get_latest_version "XTLS/Xray-core")
                latest=$(strip_tag "$raw")
                ;;
            hysteria)
                current=$(get_hysteria_version)
                raw=$(get_latest_version "apernet/hysteria")
                latest=$(strip_tag "$raw")
                ;;
            mita)
                current=$(get_mita_version)
                raw=$(get_latest_version "enfein/mieru")
                latest=$(strip_tag "$raw")
                ;;
            caddy)
                current=$(get_caddy_version)
                raw=$(get_latest_version "caddyserver/caddy")
                latest=$(strip_tag "$raw")
                ;;
        esac

        [ "$first" = true ] && first=false || echo ","
        printf '  "%s": {"current": "%s", "latest": "%s"}' "$core_name" "$current" "$latest"
    done

    echo ""
    echo "}"
}

do_update_xray() {
    echo "Updating Xray..."
    bash -c "$(curl -L https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" install
    systemctl restart xray
    echo "Xray updated to $(get_xray_version)"
}

do_update_hysteria() {
    echo "Updating Hysteria..."
    bash -c "$(curl -fsSL https://get.hy2.sh)"
    systemctl restart hysteria-server
    echo "Hysteria updated to $(get_hysteria_version)"
}

do_update_mita() {
    echo "Updating Mita..."
    local raw latest
    raw=$(get_latest_version "enfein/mieru")
    latest=$(strip_tag "$raw")
    if [ "$latest" = "unknown" ]; then
        echo "ERROR: Could not determine latest version"
        exit 1
    fi
    local deb="mita_${latest}_amd64.deb"
    cd /tmp
    wget -q "https://github.com/enfein/mieru/releases/download/v${latest}/${deb}" -O "$deb"
    dpkg -i "$deb" || apt-get install -f -y
    rm -f "$deb"
    systemctl restart mita
    echo "Mita updated to $(get_mita_version)"
}

do_update_caddy() {
    echo "Updating Caddy..."
    export GOPATH=$HOME/go
    export PATH=$PATH:$GOPATH/bin
    go install github.com/caddyserver/xcaddy/cmd/xcaddy@latest
    GOWORK=off xcaddy build --with github.com/caddyserver/forwardproxy@caddy2=github.com/klzgrad/forwardproxy@naive
    mv caddy /usr/local/bin/caddy
    chmod +x /usr/local/bin/caddy
    systemctl restart caddy
    echo "Caddy updated to $(get_caddy_version)"
}

case "$ACTION" in
    check)
        do_check
        ;;
    update)
        case "$CORE" in
            xray)    do_update_xray ;;
            hysteria) do_update_hysteria ;;
            mita)    do_update_mita ;;
            caddy)   do_update_caddy ;;
            all)
                do_update_xray
                do_update_hysteria
                do_update_mita
                do_update_caddy
                ;;
            *) echo "Unknown core: $CORE"; exit 1 ;;
        esac
        ;;
    *)
        echo "Usage: $0 {check|update} {xray|hysteria|mita|caddy|all}"
        exit 1
        ;;
esac
