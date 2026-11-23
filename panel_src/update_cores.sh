#!/bin/bash
set -euo pipefail

ACTION="${1:-check}"
CORE="${2:-all}"

# Последний тег релиза через редирект github.com/.../releases/latest: без GitHub API,
# у которого лимит 60 запросов в час на IP (на общих IP облаков он часто исчерпан).
github_latest_tag() {
    local url
    url=$(curl -fsIL --connect-timeout 10 --max-time 30 -o /dev/null -w '%{url_effective}' "https://github.com/$1/releases/latest") || return 1
    [[ $url == */releases/tag/* ]] || return 1
    url=${url##*/releases/tag/}
    printf '%s\n' "${url//%2F//}"
}

get_latest_version() {
    local repo="$1"
    local tag=""
    for i in 1 2 3; do
        if tag=$(github_latest_tag "$repo"); then
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
    local args=()
    local version
    version=$(get_latest_version "XTLS/Xray-core")
    [ "$version" != "unknown" ] && args=(--version "$version")
    bash -c "$(curl -L https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" install "${args[@]}"
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
    local deb="mita_${latest}_$(dpkg --print-architecture).deb"
    cd /tmp
    wget -q "https://github.com/enfein/mieru/releases/download/v${latest}/${deb}" -O "$deb"
    dpkg -i "$deb" || apt-get install -f -y
    rm -f "$deb"
    systemctl restart mita
    echo "Mita updated to $(get_mita_version)"
}

do_update_caddy() {
    echo "Updating Caddy..."
    local available_kb
    available_kb=$(awk '/MemAvailable:|SwapFree:/ { total += $2 } END { print total+0 }' /proc/meminfo)
    if [ "$available_kb" -lt 1572864 ]; then
        echo "ERROR: At least 1.5 GiB of available memory and swap is required to build Caddy"
        exit 1
    fi
    local build_dir
    build_dir=$(mktemp -d)
    cd "$build_dir"
    export GOPATH=$HOME/go
    export PATH=$PATH:$GOPATH/bin
    go install github.com/caddyserver/xcaddy/cmd/xcaddy@latest
    GOWORK=off xcaddy build --with github.com/caddyserver/forwardproxy@caddy2=github.com/klzgrad/forwardproxy@naive
    mv caddy /usr/local/bin/caddy
    chmod +x /usr/local/bin/caddy
    cd /
    rm -rf -- "$build_dir"
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
