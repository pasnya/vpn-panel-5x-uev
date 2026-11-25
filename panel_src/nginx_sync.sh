#!/usr/bin/env bash
# Root-хелпер панели: собирает конфиги nginx для активных доменов.
# Панель (www-data) не пишет в /etc/nginx сама: она только вызывает этот скрипт через sudo.
# Все данные, которые приходят из базы панели, проверяются здесь строгими шаблонами,
# поэтому подложить в конфиг nginx произвольную директиву через базу нельзя.
set -euo pipefail
umask 022

DB=/var/www/panel/db/panel.db
PATHS=/var/www/panel/db/paths.json
SITES_AVAILABLE=/etc/nginx/sites-available
SITES_ENABLED=/etc/nginx/sites-enabled
DOMAINS_MAP=/etc/nginx/conf.d/domains.map
NAME_RE='^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'
HEX_RE='^[a-f0-9]{8,64}$'

[[ $# -eq 0 ]] || { echo 'Скрипт не принимает аргументов.' >&2; exit 2; }

php_sock=''
for candidate in /run/php/php[0-9]*-fpm.sock; do
    [[ -S "$candidate" ]] && { php_sock=$candidate; break; }
done
[[ -n "$php_sock" ]] || { echo 'Не найден сокет php-fpm.' >&2; exit 1; }

admin_path=$(jq -r '.admin_path // empty' "$PATHS")
xhttp_path=$(jq -r '.xhttp_path // empty' "$PATHS")
xhttp_path=${xhttp_path//\//}
[[ "$admin_path" =~ $HEX_RE ]] || { echo 'Некорректный admin_path.' >&2; exit 1; }
[[ "$xhttp_path" =~ $HEX_RE ]] || { echo 'Некорректный xhttp_path.' >&2; exit 1; }

domains=()
naives=()
while IFS='|' read -r domain naive; do
    [[ "$domain" =~ $NAME_RE && "$naive" =~ $NAME_RE && "$naive" == *".$domain" ]] || continue
    [[ -f "/etc/letsencrypt/live/$domain/fullchain.pem" && -f "/etc/letsencrypt/live/$domain/privkey.pem" ]] || continue
    domains+=("$domain")
    naives+=("$naive")
done < <(sqlite3 -readonly -separator '|' "$DB" "SELECT domain_name, naive_sub FROM domains WHERE ssl_status = 'active' ORDER BY id")

stage=$(mktemp -d)
backup=$(mktemp -d)
trap 'rm -rf "$stage" "$backup"' EXIT

: > "$stage/domains.map"
for i in "${!domains[@]}"; do
    printf '%s nginx_web;\n%s caddy_naive;\n' "${domains[$i]}" "${naives[$i]}" >> "$stage/domains.map"
done

for domain in "${domains[@]}"; do
    cat > "$stage/$domain" <<EOF
server {
    listen 127.0.0.1:8443 ssl http2;
    server_name $domain;

    ssl_certificate /etc/letsencrypt/live/$domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;

    root /var/www/decoy/$domain;
    index index.html;

    # Проверка пользователей Hysteria нужна только самой Hysteria через 127.0.0.1:8080.
    location = /$admin_path/api/hysteria_auth.php {
        return 404;
    }

    location /$admin_path/assets/ {
        root /var/www;
        rewrite ^/$admin_path/assets/(.*)\$ /panel/assets/\$1 break;
    }

    location /$admin_path/ {
        root /var/www/panel;
        rewrite ^/$admin_path/(.*)\$ /\$1 break;
        include snippets/fastcgi-php.conf;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:$php_sock;
    }

    location ~ ^/sub/([a-fA-F0-9-]{36})\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/panel/api/sub.php;
        fastcgi_param QUERY_STRING token=\$1;
        fastcgi_pass unix:$php_sock;
    }

    location /$xhttp_path {
        proxy_redirect off;
        proxy_pass http://127.0.0.1:10001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_buffering off;
        proxy_request_buffering off;
    }

    location / {
        try_files \$uri \$uri/ =404;
    }
}
EOF
done

# Ничего не изменилось - nginx не трогаем.
changed=0
cmp -s "$stage/domains.map" "$DOMAINS_MAP" 2>/dev/null || changed=1
for domain in "${domains[@]}"; do
    cmp -s "$stage/$domain" "$SITES_AVAILABLE/$domain" 2>/dev/null || changed=1
    [[ "$(readlink "$SITES_ENABLED/$domain" 2>/dev/null)" == "$SITES_AVAILABLE/$domain" ]] || changed=1
done
for file in "$SITES_AVAILABLE"/*; do
    name=$(basename "$file")
    [[ "$name" == default || ! -e "$file" ]] && continue
    [[ " ${domains[*]} " == *" $name "* ]] || changed=1
done
[[ $changed -eq 1 ]] || exit 0

# Резервная копия на случай, если nginx -t не примет новую конфигурацию.
mkdir -p "$backup/available" "$backup/enabled"
cp -a "$DOMAINS_MAP" "$backup/domains.map" 2>/dev/null || true
for file in "$SITES_AVAILABLE"/*; do
    if [[ -e "$file" && "$(basename "$file")" != default ]]; then cp -a "$file" "$backup/available/"; fi
done
for file in "$SITES_ENABLED"/*; do
    if [[ ( -L "$file" || -e "$file" ) && "$(basename "$file")" != default ]]; then cp -a "$file" "$backup/enabled/"; fi
done

restore() {
    for file in "$SITES_AVAILABLE"/* "$SITES_ENABLED"/*; do
        [[ "$(basename "$file")" == default ]] || rm -f "$file"
    done
    cp -a "$backup/available/." "$SITES_AVAILABLE/"
    cp -a "$backup/enabled/." "$SITES_ENABLED/"
    if [[ -f "$backup/domains.map" ]]; then cp -a "$backup/domains.map" "$DOMAINS_MAP"; fi
}

for file in "$SITES_AVAILABLE"/* "$SITES_ENABLED"/*; do
    name=$(basename "$file")
    [[ "$name" == default ]] && continue
    [[ " ${domains[*]} " == *" $name "* ]] || rm -f "$file"
done
install -o root -g root -m 644 "$stage/domains.map" "$DOMAINS_MAP"
for domain in "${domains[@]}"; do
    install -o root -g root -m 644 "$stage/$domain" "$SITES_AVAILABLE/$domain"
    ln -sfn "$SITES_AVAILABLE/$domain" "$SITES_ENABLED/$domain"
done

if ! output=$(/usr/sbin/nginx -t 2>&1); then
    restore
    echo "$output" >&2
    exit 1
fi
/usr/bin/systemctl reload nginx
