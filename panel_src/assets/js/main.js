// Onboarding Wizard Stepper Logic
let currentWizardStep = 1;
const totalSteps = 4;

document.addEventListener('DOMContentLoaded', () => {
    initWizard();
    initSearchFilter();
});

function initWizard() {
    // Check if wizard was collapsed before
    const isCollapsed = localStorage.getItem('vpn_wizard_collapsed') === 'true';
    const savedStep = localStorage.getItem('vpn_wizard_step');
    
    if (savedStep) {
        currentWizardStep = parseInt(savedStep, 10);
    }
    
    const wCard = document.getElementById('wizardCard');
    const wCollapseBtn = document.getElementById('wizardCollapseBtn');
    const wBody = document.getElementById('wizardBody');
    
    if (isCollapsed) {
        wBody.style.display = 'none';
        wCollapseBtn.textContent = 'Развернуть';
    } else {
        wBody.style.display = 'block';
        wCollapseBtn.textContent = 'Свернуть';
    }
    
    wCollapseBtn.addEventListener('click', () => {
        const collapsed = wBody.style.display === 'none';
        if (collapsed) {
            wBody.style.display = 'block';
            wCollapseBtn.textContent = 'Свернуть';
            localStorage.setItem('vpn_wizard_collapsed', 'false');
            highlightStepElement(currentWizardStep);
        } else {
            wBody.style.display = 'none';
            wCollapseBtn.textContent = 'Развернуть';
            localStorage.setItem('vpn_wizard_collapsed', 'true');
        }
    });

    goToStep(currentWizardStep);
}

function updateStepperUI() {
    // Update step nodes
    for (let i = 1; i <= totalSteps; i++) {
        const node = document.getElementById(`stepNode-${i}`);
        if (!node) continue;
        
        node.className = 'step-node';
        if (i === currentWizardStep) {
            node.classList.add('active');
        } else if (i < currentWizardStep) {
            node.classList.add('completed');
        }
    }
    
    // Update progress bar width
    const progressWidth = ((currentWizardStep - 1) / (totalSteps - 1)) * 100;
    const bar = document.getElementById('stepperProgress');
    if (bar) {
        bar.style.width = `${progressWidth}%`;
    }
    
    // Show current slide
    for (let i = 1; i <= totalSteps; i++) {
        const slide = document.getElementById(`wizardSlide-${i}`);
        if (slide) {
            slide.classList.toggle('active', i === currentWizardStep);
        }
    }
    
    // Disable/enable back/next buttons
    const btnBack = document.getElementById('wizardBack');
    const btnNext = document.getElementById('wizardNext');
    
    if (btnBack) btnBack.disabled = (currentWizardStep === 1);
    if (btnNext) {
        if (currentWizardStep === totalSteps) {
            btnNext.textContent = 'Готово';
        } else {
            btnNext.textContent = 'Далее';
        }
    }
}

function nextStep() {
    if (currentWizardStep < totalSteps) {
        currentWizardStep++;
        localStorage.setItem('vpn_wizard_step', currentWizardStep);
        updateStepperUI();
        highlightStepElement(currentWizardStep);
    } else {
        // Complete wizard
        document.getElementById('wizardBody').style.display = 'none';
        document.getElementById('wizardCollapseBtn').textContent = 'Развернуть';
        localStorage.setItem('vpn_wizard_collapsed', 'true');
        showToast('Помощник скрыт. Вы можете развернуть его в любой момент.');
    }
}

function prevStep() {
    if (currentWizardStep > 1) {
        currentWizardStep--;
        localStorage.setItem('vpn_wizard_step', currentWizardStep);
        updateStepperUI();
        highlightStepElement(currentWizardStep);
    }
}

function goToStep(step) {
    if (step >= 1 && step <= totalSteps) {
        currentWizardStep = step;
        localStorage.setItem('vpn_wizard_step', currentWizardStep);
        updateStepperUI();
        highlightStepElement(step);
    }
}

function highlightStepElement(step) {
    // Remove all previous highlight classes
    document.querySelectorAll('.flash-highlight').forEach(el => el.classList.remove('flash-highlight'));
    
    let target = null;
    switch(step) {
        case 1:
            target = document.getElementById('serverIpInfo');
            break;
        case 2:
            target = document.getElementById('addDomainCard');
            break;
        case 3:
            target = document.getElementById('addClientCard');
            break;
        case 4:
            target = document.getElementById('clientsCard');
            break;
    }
    
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.classList.add('flash-highlight');
        // Add CSS dynamic animation style inline if not in style.css
        if (!document.getElementById('flash-style')) {
            const s = document.createElement('style');
            s.id = 'flash-style';
            s.innerHTML = `
                @keyframes flashGlow {
                    0%, 100% { border-color: var(--border); box-shadow: none; }
                    50% { border-color: var(--accent); box-shadow: 0 0 15px rgba(0, 242, 254, 0.4); }
                }
                .flash-highlight {
                    animation: flashGlow 2s ease-in-out infinite;
                }
            `;
            document.head.appendChild(s);
        }
    }
}

// Client Search Filter
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
    if (!domain && domains.length > 0) domain = domains[0];

    let serverIp = window.location.hostname;
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

    let html = `
        <div class="alert alert-info" style="margin-bottom: 18px;">
            <strong>Рекомендуемые приложения для подключения:</strong><br>
            • <strong>Anarise VPN (Android, Windows):</strong> Рекомендуемый клиент для VLESS, Hysteria 2 и NaiveProxy.<br>
            • <strong>VLESS / Hysteria 2:</strong> v2rayN (Windows), Nekobox (Android/Windows), Shadowrocket (iOS), Sing-box (все ОС).<br>
            • <strong>NaiveProxy:</strong> Nekobox или официальный клиент NaiveProxy.<br>
            • <strong>Mieru:</strong> Специальный клиент Mieru или Clash Meta (для Clash конфига).
        </div>
    `;

    configs.forEach(cfg => {
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
                            <img id="qr-img-${cfg.id}" src="" alt="QR Code" style="width:160px; height:160px; display:block;">
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

    document.getElementById('configsContent').innerHTML = html;
    document.getElementById('configTitle').innerHTML = `<span class="icon">🔑</span> Конфигурации клиента: <strong>${username}</strong>`;
    let panel = document.getElementById('configsPanel');
    panel.classList.add('open');
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleQrCode(id, encodedValue) {
    const qrDiv = document.getElementById(`qr-${id}`);
    const qrImg = document.getElementById(`qr-img-${id}`);
    
    if (qrDiv.style.display === 'none') {
        const decoded = decodeURIComponent(encodedValue);
        // Using qrserver API to render QR Code
        qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(decoded)}`;
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
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
    fetch(`api/update_core.php?action=update&core=${core}`)
        .then(r => r.json())
        .then(() => {
            showToast(`${labels[core]} успешно обновлено!`);
            checkCores();
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Ошибка';
            alert('Ошибка во время обновления ядра.');
        });
}
