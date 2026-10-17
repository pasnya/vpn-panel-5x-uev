document.addEventListener('DOMContentLoaded', () => {
    initSearchFilter();
    fetchWarpStatus();
    if (window.justCreatedClientId) {
        const button = document.querySelector(`[data-client-id="${window.justCreatedClientId}"]`);
        if (button) button.click();
    }
});
function initSearchFilter() {
    const searchInput = document.getElementById('clientSearch');
    if (!searchInput) return;
    
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.toLowerCase().trim();
        const rows = document.querySelectorAll('#clientsTable tbody tr');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const nameEl = row.querySelector('.client-name');
            if (nameEl) {
                const name = nameEl.textContent.toLowerCase();
                if (name.includes(query)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
        });
        
        const emptyEl = document.getElementById('clientsEmptySearch');
        if (visibleCount === 0 && rows.length > 0) {
            if (!emptyEl) {
                const tr = document.createElement('tr');
                tr.id = 'clientsEmptySearch';
                tr.innerHTML = `<td colspan="4" class="empty">Нет клиентов, соответствующих запросу</td>`;
                document.querySelector('#clientsTable tbody').appendChild(tr);
            }
        } else if (emptyEl) {
            emptyEl.remove();
        }
    });
}

// Config details display
function showConfigs(username, uuid, domain_id, domains, publicKey, obfsPassword, realitySni, realitySid, xhttpPath) {
    let domain = domains.find(d => d.id == domain_id);

    let serverIp = window.panelServerIp || window.location.hostname;
    let mainDomain = domain ? domain.domain_name : '';
    let naiveSub = domain ? (domain.naive_sub || mainDomain) : '';
    let hasDomain = !!domain;

    let pathName = xhttpPath || 'xhttp';
    if (!pathName.startsWith('/')) pathName = '/' + pathName;
    if (!pathName.endsWith('/')) pathName = pathName + '/';

    let vlessReality = `vless://${uuid}@${serverIp}:443?security=reality&sni=${realitySni || 'yahoo.com'}&pbk=${publicKey}&fp=chrome&type=tcp&flow=xtls-rprx-vision${realitySid ? '&sid=' + realitySid : ''}#Reality-${username}`;

    let configs = [
        { id: 'link-vless-reality', label: 'VLESS + Reality', value: vlessReality, available: true, note: 'Не требует домен (работает через IP)' },
    ];

    if (hasDomain) {
        configs.push({ id: 'link-subscription', label: 'Единая подписка', value: `https://${mainDomain}/sub/${encodeURIComponent(uuid)}`, available: true });
        let vlessXhttp = `vless://${uuid}@${serverIp}:443?security=tls&sni=${mainDomain}&fp=chrome&type=xhttp&host=${mainDomain}&path=${pathName}&alpn=h2,http/1.1#XHTTP-${username}`;
        let naiveLink = `naive+https://${username}:${uuid}@${naiveSub}:443#Naive-${username}`;
        let hysteriaLink = `hysteria2://${uuid}@${serverIp}:443?obfs-password=${obfsPassword}&security=tls&sni=${mainDomain}&alpn=h3,h2,http/1.1#Hysteria-${username}`;
        let mieruPassword = uuid.replace(/-/g, '').substring(0, 16);
        let mieruLink = `mieru://${username}:${mieruPassword}@${serverIp}:443?transport=TCP&multiplexing=MULTIPLEXING_LOW`;
        
        let mieruClashConfig = [
            'mixed-port: 7890',
            'allow-lan: false',
            'mode: rule',
            'log-level: info',
            '',
            'proxies:',
            '  - name: Mieru-' + username,
            '    type: mieru',
            '    server: ' + serverIp,
            '    port: 443',
            '    transport: TCP',
            '    udp: true',
            '    username: ' + username,
            '    password: ' + mieruPassword,
            '    multiplexing: MULTIPLEXING_LOW',
            '',
            'proxy-groups:',
            '  - name: PROXY',
            '    type: select',
            '    proxies:',
            '      - Mieru-' + username,
            '      - DIRECT',
            '',
            'rules:',
            '  - MATCH,PROXY'
        ].join('\n');

        configs.push(
            { id: 'link-vless-xhttp', label: 'VLESS + xhttp (WebSocket)', value: vlessXhttp, available: true },
            { id: 'link-naive', label: 'NaiveProxy (HTTPS)', value: naiveLink, available: true },
            { id: 'link-hysteria', label: 'Hysteria 2 (UDP/QUIC)', value: hysteriaLink, available: true },
            { id: 'link-mieru-uri', label: 'Mieru (URI)', value: mieruLink, available: true },
            { id: 'link-mieru', label: 'Mieru (Clash Config)', value: mieruClashConfig, available: true, isBlock: true }
        );
    } else {
        configs.push(
            { id: 'link-vless-xhttp', label: 'VLESS + xhttp', value: '', available: false, note: 'Требует привязанный домен' },
            { id: 'link-naive', label: 'NaiveProxy', value: '', available: false, note: 'Требует привязанный домен' },
            { id: 'link-hysteria', label: 'Hysteria 2', value: '', available: false, note: 'Требует привязанный домен' },
            { id: 'link-mieru-uri', label: 'Mieru (URI)', value: '', available: false, note: 'Требует привязанный домен' },
            { id: 'link-mieru', label: 'Mieru (Clash)', value: '', available: false, note: 'Требует привязанный домен', isBlock: true }
        );
    }

    const primary = hasDomain ? configs.find(cfg => cfg.id === 'link-subscription') : configs[0];
    let html = `<div class="alert alert-info" style="margin-bottom:18px">
        <strong>Готово. Добавьте профиль в VPN-приложение.</strong><br>
        ${hasDomain ? 'Скопируйте подписку или откройте QR-код. В подписке собраны доступные подключения.' : 'Скопируйте ссылку Reality или откройте QR-код. Домен для этого не нужен.'}
    </div>
    <div class="cfg-row">
        <div class="cfg-header"><span class="cfg-label">${primary.label}</span>
            <div style="display:flex;gap:8px">
                <button class="btn btn-sm btn-outline" onclick="toggleQrCode('${primary.id}', '${encodeURIComponent(primary.value)}')">QR-код</button>
                <button class="btn btn-sm" onclick="copy('${primary.id}')">Скопировать</button>
            </div>
        </div>
        <div class="cfg-code-container"><code class="cfg-code" id="${primary.id}">${escapeHtml(primary.value)}</code></div>
        <div id="qr-${primary.id}" class="qr-container" style="display:none;text-align:center;padding:12px 0 2px 0">
            <div style="background:#fff;padding:10px;display:inline-block;border-radius:8px"><div id="qr-img-${primary.id}" aria-label="QR-код" style="width:160px;height:160px"></div></div>
        </div>
    </div>
    <details style="margin-top:16px"><summary>Другие протоколы и ручная настройка</summary>
        <div class="alert alert-info" style="margin-top:14px;margin-bottom:14px">
            Windows и Android: <a href="https://github.com/pasnya/anarise-vpn" target="_blank" rel="noopener noreferrer">Anarise VPN</a>.
            iPhone: Shadowrocket, Streisand или Sing-box. macOS: Nekoray, Nekobox или Sing-box.
        </div>`;

    configs.forEach(cfg => {
        if (cfg.id === primary.id) return;
        if (cfg.available) {
            if (cfg.isBlock) {
                html += `
                <div class="cfg-row">
                    <div class="cfg-header">
                        <span class="cfg-label">⚡ ${cfg.label}</span>
                        <div style="display:flex; gap: 8px;">
                            <button class="btn btn-sm btn-outline" onclick="copy('${cfg.id}')">Копировать</button>
                        </div>
                    </div>
                    <pre class="cfg-code-block" id="${cfg.id}">${escapeHtml(cfg.value)}</pre>
                </div>`;
            } else {
                html += `
                <div class="cfg-row">
                    <div class="cfg-header">
                        <span class="cfg-label">⚡ ${cfg.label}</span>
                        <div style="display:flex; gap: 8px;">
                            <button class="btn btn-sm btn-outline" onclick="toggleQrCode('${cfg.id}', '${encodeURIComponent(cfg.value)}')">QR-код</button>
                            <button class="btn btn-sm" onclick="copy('${cfg.id}')">Копировать</button>
                        </div>
                    </div>
                    <div class="cfg-code-container">
                        <code class="cfg-code" id="${cfg.id}">${escapeHtml(cfg.value)}</code>
                    </div>
                    <div id="qr-${cfg.id}" class="qr-container" style="display:none; text-align:center; padding: 12px 0 2px 0;">
                        <div style="background:#fff; padding:10px; display:inline-block; border-radius:8px;">
                            <div id="qr-img-${cfg.id}" aria-label="QR-код" style="width:160px; height:160px;"></div>
                        </div>
                        <div style="font-size:11px; color:var(--text-dim); margin-top:6px;">Отсканируйте камерой телефона или в приложении</div>
                    </div>
                </div>`;
            }
        } else {
            html += `
            <div class="cfg-unavailable">
                <span class="cfg-label">🔒 ${cfg.label}</span>
                <span class="cfg-hint">${cfg.note}</span>
            </div>`;
        }
    });
    html += '</details>';

    document.getElementById('configsContent').innerHTML = html;
    document.getElementById('configTitle').innerHTML = `<span class="icon">🔑</span> Подключение: <strong>${escapeHtml(username)}</strong>`;
    let panel = document.getElementById('configsPanel');
    panel.classList.add('open');
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleQrCode(id, encodedValue) {
    const qrDiv = document.getElementById(`qr-${id}`);
    const qrContainer = document.getElementById(`qr-img-${id}`);
    
    if (qrDiv.style.display === 'none') {
        const decoded = decodeURIComponent(encodedValue);
        if (!qrContainer.hasChildNodes()) new QRCode(qrContainer, { text: decoded, width: 160, height: 160 });
        qrDiv.style.display = 'block';
    } else {
        qrDiv.style.display = 'none';
    }
}

function escapeHtml(str) {
    let div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function copy(elementId) {
    let el = document.getElementById(elementId);
    let text = el.innerText || el.textContent;

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => showToast('Скопировано в буфер обмена!'));
    } else {
        let ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('Скопировано в буфер обмена!');
    }
}

function showToast(msg) {
    let t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg, var(--accent), var(--accent2));color:#fff;padding:10px 24px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;pointer-events:none;opacity:0;transition:opacity .3s, transform .3s;box-shadow: 0 8px 24px rgba(0,0,0,0.4);transform:translate(-50%, 10px)';
    document.body.appendChild(t);
    requestAnimationFrame(() => {
        t.style.opacity = '1';
        t.style.transform = 'translate(-50%, 0)';
    });
    setTimeout(() => {
        t.style.opacity = '0';
        t.style.transform = 'translate(-50%, -10px)';
        setTimeout(() => t.remove(), 300);
    }, 2000);
}

function restartService(name) {
    if (!confirm(`Вы действительно хотите перезапустить службу ${name.toUpperCase()}?`)) return;
    showToast(`Перезапуск ${name}...`);
    fetch('api/restart_service.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
        body: 'service=' + encodeURIComponent(name)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast(`${name.toUpperCase()} успешно перезапущен!`);
            setTimeout(() => location.reload(), 800);
        } else {
            alert('Ошибка: ' + d.message);
        }
    })
    .catch(e => alert('Сетевая ошибка: ' + e));
}

function loadLogs() {
    const service = document.getElementById('logService').value;
    const out = document.getElementById('logsContent');
    out.textContent = 'Загрузка...';
    fetch('api/logs.php?service=' + encodeURIComponent(service))
        .then(r => r.text().then(text => ({ ok: r.ok, text })))
        .then(result => { out.textContent = result.text; })
        .catch(() => { out.textContent = 'Не удалось загрузить журнал'; });
}

function checkCores() {
    let btn = document.getElementById('btn-check-cores');
    btn.disabled = true;
    btn.textContent = 'Проверка...';

    fetch('api/update_core.php?action=check&core=all')
        .then(r => r.json())
        .then(data => {
            ['xray', 'hysteria', 'mita', 'caddy'].forEach(core => {
                let info = data[core];
                if (!info) return;
                let verEl = document.getElementById('ver-' + core);
                let btnEl = document.getElementById('btn-update-' + core);
                if (verEl) {
                    verEl.textContent = info.current;
                    verEl.className = info.current === info.latest ? 'tag tag-ok' : 'tag tag-warn';
                }
                if (btnEl) {
                    if (info.current === info.latest) {
                        btnEl.disabled = true;
                        btnEl.textContent = 'Актуально';
                        btnEl.className = 'btn btn-sm btn-outline';
                    } else {
                        btnEl.disabled = false;
                        btnEl.textContent = 'Обновить до ' + info.latest;
                        btnEl.className = 'btn btn-sm';
                    }
                }
            });
            btn.disabled = false;
            btn.textContent = 'Проверить обновления';
            showToast('Версии ядер успешно проверены!');
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Проверить обновления';
            alert('Ошибка при проверке версий ядер.');
        });
}

function updateCore(core) {
    let labels = { xray: 'Xray Core', hysteria: 'Hysteria 2', mita: 'Mita', caddy: 'Caddy' };
    if (!confirm(`Обновить ${labels[core]}? Соответствующая служба будет временно остановлена и перезапущена.`)) return;
    let btn = document.getElementById('btn-update-' + core);
    btn.disabled = true;
    btn.textContent = 'Обновление...';
    showToast(`Обновление ${labels[core]}...`);
    fetch(`api/update_core.php?action=update&core=${core}`, { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content } })
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.output || 'Ошибка обновления');
            showToast(`${labels[core]} успешно обновлено!`);
            checkCores();
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Ошибка';
            alert('Ошибка во время обновления ядра.');
        });
}

// Cloudflare WARP Management
function fetchWarpStatus() {
    fetch('api/warp.php?action=status')
        .then(r => r.json())
        .then(data => {
            const unregisteredView = document.getElementById('warpUnregisteredView');
            const registeredView = document.getElementById('warpRegisteredView');
            const statusBadge = document.getElementById('warpStatusBadge');
            const statusText = document.getElementById('warpStatusText');
            const btnToggle = document.getElementById('btnWarpToggle');
            const selectMode = document.getElementById('selectWarpMode');
            const deviceIdEl = document.getElementById('warpDeviceId');
            const ipv4El = document.getElementById('warpIPv4');
            const ipv6El = document.getElementById('warpIPv6');
            const licenseEl = document.getElementById('warpLicense');
            const metaItems = document.querySelectorAll('.warp-meta-item');

            if (data.registered) {
                unregisteredView.style.display = 'none';
                registeredView.style.display = 'block';
                
                // Set metadata values
                deviceIdEl.textContent = data.data.device_id || '—';
                ipv4El.textContent = data.data.v4 || '—';
                ipv6El.textContent = data.data.v6 || '—';
                licenseEl.textContent = data.data.license_key || '—';
                metaItems.forEach(el => el.style.display = 'flex');

                // Set mode selection
                if (data.mode) {
                    selectMode.value = data.mode;
                }

                // Set status text and badge
                if (data.status === 'enabled') {
                    statusBadge.textContent = 'Включен';
                    statusBadge.className = 'tag tag-ok';
                    statusText.textContent = 'Подключение активно. Маршрутизация: ' + (data.mode === 'smart' ? 'Умная' : 'Глобальная');
                    btnToggle.textContent = 'Выключить';
                    btnToggle.className = 'btn btn-outline btn-sm';
                    btnToggle.style.borderColor = 'var(--err)';
                    btnToggle.style.color = 'var(--err)';
                } else {
                    statusBadge.textContent = 'Выключен';
                    statusBadge.className = 'tag tag-warn';
                    statusText.textContent = 'Подключение отключено в настройках.';
                    btnToggle.textContent = 'Включить';
                    btnToggle.className = 'btn btn-sm';
                    btnToggle.style.borderColor = '';
                    btnToggle.style.color = '';
                }
            } else {
                unregisteredView.style.display = 'block';
                registeredView.style.display = 'none';
                statusBadge.textContent = 'Не подключен';
                statusBadge.className = 'tag tag-warn';
                metaItems.forEach(el => el.style.display = 'none');
            }
        })
        .catch(e => {
            console.error('Error fetching WARP status:', e);
        });
}

function registerWarp() {
    const btn = document.getElementById('btnWarpRegister');
    const originalText = btn ? btn.textContent : '';
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Подключение...';
    }
    showToast('Регистрация устройства в Cloudflare WARP...');
    
    fetch('api/warp.php?action=register', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content } })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast(d.message);
                fetchWarpStatus();
            } else {
                alert('Ошибка регистрации WARP: ' + d.message);
            }
        })
        .catch(e => alert('Сетевая ошибка: ' + e))
        .finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
}

function toggleWarp() {
    showToast('Изменение состояния WARP...');
    fetch('api/warp.php?action=toggle', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content } })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast(d.message);
                fetchWarpStatus();
            } else {
                alert('Ошибка при изменении состояния WARP: ' + d.message);
            }
        })
        .catch(e => alert('Сетевая ошибка: ' + e));
}

function setWarpMode(mode) {
    showToast('Сохранение режима...');
    fetch('api/warp.php?action=set_mode', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
        body: 'mode=' + encodeURIComponent(mode)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast(d.message);
            fetchWarpStatus();
        } else {
            alert('Ошибка при смене режима WARP: ' + d.message);
        }
    })
    .catch(e => alert('Сетевая ошибка: ' + e));
}

function deleteWarp() {
    if (!confirm('Вы действительно хотите удалить подключение к Cloudflare WARP? Все настройки маршрутизации и WireGuard будут стерты.')) return;
    showToast('Удаление WARP...');
    fetch('api/warp.php?action=delete', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content } })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showToast(d.message);
                fetchWarpStatus();
            } else {
                alert('Ошибка при удалении WARP: ' + d.message);
            }
        })
        .catch(e => alert('Сетевая ошибка: ' + e));
}
