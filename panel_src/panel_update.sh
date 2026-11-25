#!/usr/bin/env bash
# Самообновление панели из релизов GitHub (устанавливается как /usr/local/sbin/vpn-panel-update).
#   check         узнать последнюю версию
#   apply         обновиться до последней версии, если она новее установленной
#   auto          то же, что apply, если автообновление включено (иначе только check)
#   start         запустить apply в фоне через systemd (для панели)
#   enable-auto   включить автообновление
#   disable-auto  выключить автообновление
# Каталог db/ не трогается. Если новая версия не встала, возвращается старая.
set -euo pipefail
umask 022

REPO=pasnya/vpn-panel-5x-uev
PANEL_DIR=/var/www/panel
STAGE_DIR=/var/www/panel.new
OLD_DIR=/var/www/panel.old
STATE_DIR=/var/lib/vpn-panel-update
STATE_FILE=$STATE_DIR/state.json
AUTO_OFF=$STATE_DIR/auto-disabled
LOG_FILE=/var/log/vpn-panel-update.log
LOCK_FILE=/run/vpn-panel-update.lock
TAG_RE='^v[0-9]+\.[0-9]+\.[0-9]+$'

[[ $EUID -eq 0 ]] || { echo 'Run as root' >&2; exit 1; }
install -d -m 755 "$STATE_DIR"

current_version() {
    local v
    v=$(tr -d '[:space:]' < "$PANEL_DIR/VERSION" 2>/dev/null || true)
    if [[ $v =~ $TAG_RE ]]; then echo "$v"; else echo unknown; fi
}

# Последний тег через редирект github.com/.../releases/latest: без лимитов GitHub API.
latest_tag() {
    local url
    url=$(curl -fsIL --proto '=https' --proto-redir '=https' --connect-timeout 10 --max-time 30 \
        -o /dev/null -w '%{url_effective}' "https://github.com/$REPO/releases/latest") || return 1
    url=${url##*/releases/tag/}
    [[ $url =~ $TAG_RE ]] || return 1
    echo "$url"
}

# is_newer КАНДИДАТ ТЕКУЩАЯ: откат на старую версию не делаем никогда.
is_newer() {
    [[ $2 == unknown ]] && return 0
    [[ $1 != "$2" && $(printf '%s\n%s\n' "${1#v}" "${2#v}" | sort -V | tail -n 1) == "${1#v}" ]]
}

# set_state СТАТУС СООБЩЕНИЕ [ПОСЛЕДНЯЯ_ВЕРСИЯ]; статус "-" оставляет прежний.
set_state() {
    local old tmp auto=true
    old=$(cat "$STATE_FILE" 2>/dev/null || true)
    jq -e 'type == "object"' >/dev/null 2>&1 <<< "$old" || old='{}'
    [[ -e $AUTO_OFF ]] && auto=false
    tmp=$(mktemp "$STATE_DIR/.state.XXXXXX")
    jq --arg status "$1" --arg message "$2" --arg latest "${3:-}" --arg current "$(current_version)" \
        --argjson auto "$auto" --arg now "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
        . + {current: $current, auto: $auto, changed_at: $now}
        + (if $status == "-" then {} else {status: $status, message: $message} end)
        + (if $latest == "" then {} else {latest: $latest, checked_at: $now} end)' <<< "$old" > "$tmp"
    chmod 644 "$tmp"
    mv -f "$tmp" "$STATE_FILE"
}

log() { echo "[$(date '+%F %T')] $*"; }

take_lock() {
    exec 9> "$LOCK_FILE"
    flock -n 9 || { echo 'Обновление уже выполняется' >&2; exit 75; }
}

do_check() {
    local latest
    if ! latest=$(latest_tag); then
        set_state error 'Не удалось узнать последнюю версию на GitHub'
        return 1
    fi
    if is_newer "$latest" "$(current_version)"; then
        set_state available "Доступна версия $latest" "$latest"
    else
        set_state uptodate 'Установлена последняя версия' "$latest"
    fi
    echo "$latest"
}

php_fpm_reload() {
    local unit
    for unit in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' | awk '{print $1}'); do
        systemctl reload "$unit" || true
    done
}

rebuild_configs() {
    sudo -u www-data /usr/bin/php -r 'require "/var/www/panel/api/rebuild_configs.php"; rebuild_configs();'
}

WORK_DIR=''
SWAPPED=0
on_exit() {
    [[ -z $WORK_DIR ]] || rm -rf -- "$WORK_DIR"
    # Каталог с db/ не удаляем ни при каких условиях.
    [[ -d $STAGE_DIR/db ]] || rm -rf -- "$STAGE_DIR"
}

# Возврат прежней версии, если новая не прошла установку.
rollback() {
    log 'Возврат прежней версии панели'
    if [[ $SWAPPED == 1 && -d $OLD_DIR ]]; then
        mv "$PANEL_DIR" "$STAGE_DIR"
        mv "$STAGE_DIR/db" "$OLD_DIR/db"
        mv "$OLD_DIR" "$PANEL_DIR"
        SWAPPED=0
        if [[ -f $PANEL_DIR/system_setup.sh ]]; then
            bash "$PANEL_DIR/system_setup.sh" || log 'system_setup.sh прежней версии завершился с ошибкой'
        else
            # Версии до v0.9.8: возвращаем их sudoers и хелперы как были.
            [[ -n $WORK_DIR && -f $WORK_DIR/sudoers.panel ]] && cp -p "$WORK_DIR/sudoers.panel" /etc/sudoers.d/panel
            [[ -f $PANEL_DIR/update_cores.sh ]] && install -m 755 "$PANEL_DIR/update_cores.sh" /usr/local/sbin/vpn-update-cores
            [[ -f $PANEL_DIR/nginx_sync.sh ]] && install -m 755 "$PANEL_DIR/nginx_sync.sh" /usr/local/sbin/vpn-nginx-sync
            chown -R root:www-data "$PANEL_DIR"
        fi
        php_fpm_reload
        rebuild_configs || log 'Пересборка конфигов прежней версии завершилась с ошибкой'
    fi
}

# Прошлый запуск оборвался посреди замены каталогов: собираем прежнюю версию обратно.
recover_interrupted() {
    [[ -d $OLD_DIR ]] || return 0
    log 'Найдено прерванное обновление, возвращаю прежнюю версию'
    if [[ ! -d $PANEL_DIR ]]; then
        [[ -d $OLD_DIR/db ]] || mv "$STAGE_DIR/db" "$OLD_DIR/db"
        mv "$OLD_DIR" "$PANEL_DIR"
    elif [[ -d $PANEL_DIR/db && ! -d $OLD_DIR/db ]]; then
        SWAPPED=1
        rollback
    else
        set_state error "Каталоги $PANEL_DIR и $OLD_DIR требуют ручной проверки"
        exit 1
    fi
}

fail() {
    log "ОШИБКА: $*"
    rollback
    set_state error "$*"
    exit 1
}

do_apply() {
    local current latest src file
    recover_interrupted
    current=$(current_version)
    latest=$(latest_tag) || { set_state error 'Не удалось узнать последнюю версию на GitHub'; exit 1; }
    if ! is_newer "$latest" "$current"; then
        set_state uptodate 'Установлена последняя версия' "$latest"
        log "Обновление не требуется: установлена $current"
        return 0
    fi
    [[ -f $PANEL_DIR/db/panel.db ]] || { set_state error 'Панель не установлена'; exit 1; }

    log "Обновление панели: $current -> $latest"
    set_state running "Обновление до $latest" "$latest"
    trap on_exit EXIT
    WORK_DIR=$(mktemp -d /var/tmp/vpn-panel-update.XXXXXX)
    mkdir "$WORK_DIR/src"
    curl -fsSL --proto '=https' --proto-redir '=https' --retry 3 --connect-timeout 15 --max-time 300 \
        "https://codeload.github.com/$REPO/tar.gz/refs/tags/$latest" -o "$WORK_DIR/release.tar.gz" \
        || fail "Не удалось скачать $latest"
    tar -xzf "$WORK_DIR/release.tar.gz" -C "$WORK_DIR/src" --strip-components=1 --no-same-owner \
        || fail 'Архив релиза повреждён'
    src=$WORK_DIR/src/panel_src
    [[ -f $src/index.php && -f $src/system_setup.sh && -f $src/panel_update.sh ]] || fail 'В архиве нет файлов панели'
    [[ "$(tr -d '[:space:]' < "$src/VERSION" 2>/dev/null)" == "$latest" ]] || fail "Версия в архиве не совпадает с тегом $latest"

    # Синтаксис проверяем до замены, чтобы не уронить работающую панель.
    while IFS= read -r -d '' file; do
        /usr/bin/php -l "$file" >/dev/null || fail "Синтаксическая ошибка в ${file#"$src"/}"
    done < <(find "$src" -name '*.php' -print0)
    while IFS= read -r -d '' file; do
        bash -n "$file" || fail "Синтаксическая ошибка в ${file#"$src"/}"
    done < <(find "$src" -name '*.sh' -print0)

    [[ ! -d $STAGE_DIR/db && ! -d $OLD_DIR/db ]] || fail 'Остались каталоги прошлого обновления с базой, нужна ручная проверка'
    rm -rf -- "$STAGE_DIR" "$OLD_DIR"
    cp -a "$src" "$STAGE_DIR"
    rm -rf -- "$STAGE_DIR/db"
    chown -R root:www-data "$STAGE_DIR"
    find "$STAGE_DIR" -type d -exec chmod 750 {} +
    find "$STAGE_DIR" -type f -exec chmod 640 {} +

    # Три переименования в пределах одного диска: панель недоступна доли секунды.
    mv "$PANEL_DIR" "$OLD_DIR"
    mv "$OLD_DIR/db" "$STAGE_DIR/db"
    mv "$STAGE_DIR" "$PANEL_DIR"
    SWAPPED=1

    [[ -f /etc/sudoers.d/panel ]] && cp -p /etc/sudoers.d/panel "$WORK_DIR/sudoers.panel"
    bash "$PANEL_DIR/system_setup.sh" "$current" || fail 'Не удалось применить системные настройки новой версии'
    php_fpm_reload
    rebuild_configs || fail 'Новая версия не смогла собрать конфиги служб'

    SWAPPED=0
    rm -rf -- "$OLD_DIR"
    set_state ok "Панель обновлена: $current -> $latest" "$latest"
    log "Готово: установлена $latest"
}

ACTION=${1:-}
case "$ACTION" in
    check)
        take_lock
        do_check
        ;;
    apply|auto)
        take_lock
        exec > >(tee -a "$LOG_FILE") 2>&1
        if [[ $ACTION == auto && -e $AUTO_OFF ]]; then
            do_check >/dev/null || true
        else
            do_apply
        fi
        ;;
    start)
        if ! flock -n "$LOCK_FILE" true 2>/dev/null; then
            echo 'Обновление уже выполняется' >&2
            exit 75
        fi
        set_state running 'Обновление запущено'
        systemctl start --no-block vpn-panel-update.service
        ;;
    enable-auto)
        rm -f "$AUTO_OFF"
        set_state - ''
        ;;
    disable-auto)
        touch "$AUTO_OFF"
        set_state - ''
        ;;
    *)
        echo "Usage: $0 {check|apply|auto|start|enable-auto|disable-auto}" >&2
        exit 2
        ;;
esac
