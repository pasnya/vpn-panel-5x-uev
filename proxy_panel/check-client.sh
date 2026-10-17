#!/usr/bin/env bash
set -euo pipefail

name=${common_name:-}
[[ "$name" =~ ^[a-zA-Z0-9_-]{1,48}$ ]] || exit 1
file=${VPN_RELAY_DIR:-/opt/vpn-relay}/clients.json
[[ -f "$file" ]] || exit 1

jq -e --arg name "$name" --argjson now "$(date +%s)" \
    '(.[$name] // {}) | .enabled == true and (.expiresAt == null or .expiresAt >= $now)' \
    "$file" >/dev/null
