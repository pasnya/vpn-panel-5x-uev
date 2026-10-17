#!/usr/bin/env bash
set -euo pipefail

RELAY_DIR=${VPN_RELAY_DIR:-/opt/vpn-relay}
UPSTREAMS="$RELAY_DIR/upstreams.json"
ACTIVE="$RELAY_DIR/active_proxy"
LOCK="$RELAY_DIR/balancer.lock"

exec 9>"$LOCK"
flock -n 9 || exit 0

CLIENTS="$RELAY_DIR/clients.json"
if [[ -s "$CLIENTS" ]] && jq -e --argjson now "$(date +%s)" \
    'to_entries | any(.value.enabled == true and .value.expiresAt != null and .value.expiresAt < $now)' \
    "$CLIENTS" >/dev/null; then
    jq --argjson now "$(date +%s)" \
        'with_entries(if .value.enabled == true and .value.expiresAt != null and .value.expiresAt < $now then .value.enabled = false else . end)' \
        "$CLIENTS" > "$CLIENTS.new"
    mv "$CLIENTS.new" "$CLIENTS"
    systemctl restart openvpn@server.service
fi

HEARTBEAT="$RELAY_DIR/heartbeat"
if [[ ! -f "$HEARTBEAT" ]] || (( $(date +%s) - $(stat -c %Y "$HEARTBEAT") > 180 )); then
    rm -f "$ACTIVE"
    systemctl stop redsocks.service
    exit 1
fi

[[ -s "$UPSTREAMS" ]] || exit 0
current=$(cat "$ACTIVE" 2>/dev/null || true)
selected=''

for name in $(jq -r '.[] | select(. == "reality" or . == "xhttp")' "$UPSTREAMS"); do
    case "$name" in
        reality) port=40004 ;;
        xhttp) port=40005 ;;
    esac
    if systemctl is-active --quiet "vpn-relay-xray@${name}.service" && \
        curl -4 --silent --show-error --fail --socks5-hostname "127.0.0.1:$port" \
            --connect-timeout 3 --max-time 8 https://api.ipify.org | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
        selected=${selected:-$name}
        [[ "$name" == "$current" ]] && { selected=$name; break; }
    fi
done

if [[ -z "$selected" ]]; then
    rm -f "$ACTIVE"
    systemctl stop redsocks.service
    exit 1
fi

if [[ "$selected" != "$current" ]] || ! systemctl is-active --quiet redsocks.service; then
    case "$selected" in
        reality) port=40004 ;;
        xhttp) port=40005 ;;
    esac
    sed "s/__SOCKS_PORT__/$port/" "$RELAY_DIR/redsocks.conf.template" > "$RELAY_DIR/redsocks.conf.new"
    mv "$RELAY_DIR/redsocks.conf.new" "$RELAY_DIR/redsocks.conf"
    systemctl restart redsocks.service
    printf '%s\n' "$selected" > "$ACTIVE"
fi
