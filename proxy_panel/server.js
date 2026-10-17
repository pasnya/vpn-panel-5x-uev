'use strict';

const https = require('https');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const net = require('net');
const { execFileSync } = require('child_process');

const root = process.env.VPN_RELAY_DIR || '/opt/vpn-relay';
const settings = require.main === module ? JSON.parse(fs.readFileSync(path.join(root, 'settings.json'), 'utf8')) : null;
const sessions = new Map();
const failedLogins = new Map();
const hostnamePattern = /^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i;
const clientPattern = /^[a-zA-Z0-9_-]{1,48}$/;

function equal(a, b) {
    const left = Buffer.from(String(a));
    const right = Buffer.from(String(b));
    return left.length === right.length && crypto.timingSafeEqual(left, right);
}

function json(res, code, value) {
    const body = JSON.stringify(value);
    res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
    res.end(body);
}

function command(file, args, timeout = 15000, cwd) {
    return execFileSync(file, args, { encoding: 'utf8', timeout, cwd, stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

function activeService(name) {
    try { return command('/usr/bin/systemctl', ['is-active', name], 3000) === 'active'; }
    catch { return false; }
}

function auth(req) {
    const header = req.headers.authorization || '';
    if (!header.startsWith('Bearer ')) return false;
    const token = header.slice(7);
    if (equal(token, settings.apiToken)) return true;
    const expiry = sessions.get(token);
    if (!expiry) return false;
    if (expiry < Date.now()) { sessions.delete(token); return false; }
    return true;
}

async function body(req) {
    let raw = '';
    for await (const chunk of req) {
        raw += chunk;
        if (raw.length > 1048576) throw new Error('Запрос слишком большой');
    }
    try { return JSON.parse(raw); }
    catch { throw new Error('Неверный JSON'); }
}

function validHost(value) {
    return typeof value === 'string' && (net.isIP(value) === 4 || hostnamePattern.test(value));
}

function xrayConfig(data, name, port) {
    const reality = name === 'reality';
    const user = { id: data.uuid, encryption: 'none' };
    if (reality) user.flow = 'xtls-rprx-vision';
    return {
        log: { loglevel: 'warning' },
        inbounds: [{ listen: '127.0.0.1', port, protocol: 'socks', settings: { udp: true } }],
        outbounds: [{ tag: 'to-main', protocol: 'vless', settings: { vnext: [{ address: data.serverIp, port: 443, users: [user] }] },
            streamSettings: reality
                ? { network: 'tcp', security: 'reality', realitySettings: { fingerprint: 'chrome', serverName: data.realitySni, publicKey: data.publicKey, shortId: data.realitySid, spiderX: '/' } }
                : { network: 'xhttp', security: 'tls', tlsSettings: { serverName: data.domain, fingerprint: 'chrome', alpn: ['h2', 'http/1.1'] }, xhttpSettings: { host: data.domain, path: '/' + data.xhttpPath + '/' } }
        }]
    };
}

function validatePairing(data) {
    if (!data || !validHost(data.serverIp) || !/^[a-f\d-]{36}$/i.test(data.uuid || '') || !/^[A-Za-z0-9_-]{40,50}$/.test(data.publicKey || '')) throw new Error('Неверные параметры основного сервера');
    if (!validHost(data.realitySni) || !/^[a-f\d]{0,16}$/i.test(data.realitySid || '')) throw new Error('Неверные параметры Reality');
    if (data.domain && (!validHost(data.domain) || !/^[a-f\d]{8,64}$/i.test(data.xhttpPath || ''))) throw new Error('Неверные параметры xHTTP');
}

function configure(data) {
    validatePairing(data);
    const configsDir = path.join(root, 'configs');
    fs.mkdirSync(configsDir, { recursive: true, mode: 0o700 });
    const names = data.domain ? ['reality', 'xhttp'] : ['reality'];
    const ports = { reality: 40004, xhttp: 40005 };
    for (const name of names) {
        const target = path.join(configsDir, name + '.json');
        const temporary = target + '.new';
        fs.writeFileSync(temporary, JSON.stringify(xrayConfig(data, name, ports[name]), null, 2), { mode: 0o600 });
        try { command('/usr/local/bin/xray', ['run', '-test', '-config', temporary]); }
        catch (error) { fs.unlinkSync(temporary); throw new Error('Конфигурация ' + name + ' не прошла проверку: ' + String(error.stderr || error.message).slice(0, 250)); }
        fs.renameSync(temporary, target);
        command('/usr/bin/systemctl', ['enable', '--now', 'vpn-relay-xray@' + name + '.service']);
        command('/usr/bin/systemctl', ['restart', 'vpn-relay-xray@' + name + '.service']);
    }
    if (!data.domain) {
        try { command('/usr/bin/systemctl', ['disable', '--now', 'vpn-relay-xray@xhttp.service']); } catch {}
    }
    fs.writeFileSync(path.join(root, 'upstreams.json'), JSON.stringify(names), { mode: 0o600 });
    fs.writeFileSync(path.join(root, 'heartbeat'), String(Date.now()), { mode: 0o600 });
    try { command(path.join(root, 'balancer.sh'), [], 45000); } catch {}
    if (!status().active) throw new Error('Каналы настроены, но основной сервер пока недоступен');
    return names;
}

function status() {
    let active = null;
    try { active = fs.readFileSync(path.join(root, 'active_proxy'), 'utf8').trim() || null; } catch {}
    let upstreams = [];
    try { upstreams = JSON.parse(fs.readFileSync(path.join(root, 'upstreams.json'), 'utf8')); } catch {}
    let controlFresh = false;
    try { controlFresh = Date.now() - fs.statSync(path.join(root, 'heartbeat')).mtimeMs < 180000; } catch {}
    return { paired: upstreams.length > 0, active, controlFresh, openvpn: activeService('openvpn@server.service'),
        channels: Object.fromEntries(upstreams.map(name => [name, activeService('vpn-relay-xray@' + name + '.service')])) };
}

function clientState(name, enabled, expiresAt) {
    if (!clientPattern.test(name) || typeof enabled !== 'boolean' || (expiresAt !== null && (!Number.isInteger(expiresAt) || expiresAt < 0))) throw new Error('Неверное состояние профиля');
    const file = path.join(root, 'clients.json');
    let clients = {};
    try { clients = JSON.parse(fs.readFileSync(file, 'utf8')); } catch {}
    const wasEnabled = clients[name]?.enabled === true;
    clients[name] = { enabled, expiresAt };
    const temporary = file + '.new';
    fs.writeFileSync(temporary, JSON.stringify(clients), { mode: 0o600 });
    fs.renameSync(temporary, file);
    if (!enabled && wasEnabled && activeService('openvpn@server.service')) command('/usr/bin/systemctl', ['restart', 'openvpn@server.service']);
}

function syncClients(items) {
    if (!Array.isArray(items) || items.length > 10000) throw new Error('Неверный список профилей');
    const file = path.join(root, 'clients.json');
    let previous = {};
    try { previous = JSON.parse(fs.readFileSync(file, 'utf8')); } catch {}
    const next = {};
    for (const item of items) {
        if (!clientPattern.test(item.name) || typeof item.enabled !== 'boolean' ||
            (item.expiresAt !== null && (!Number.isInteger(item.expiresAt) || item.expiresAt < 0))) throw new Error('Неверный профиль в списке');
        next[item.name] = { enabled: item.enabled, expiresAt: item.expiresAt };
    }
    const revoked = Object.keys(previous).some(name => previous[name].enabled === true && next[name]?.enabled !== true);
    const temporary = file + '.new';
    fs.writeFileSync(temporary, JSON.stringify(next), { mode: 0o600 });
    fs.renameSync(temporary, file);
    fs.writeFileSync(path.join(root, 'heartbeat'), String(Date.now()), { mode: 0o600 });
    if (revoked && activeService('openvpn@server.service')) command('/usr/bin/systemctl', ['restart', 'openvpn@server.service']);
}

function generateProfile(name, expiresAt) {
    if (!clientPattern.test(name)) throw new Error('Имя: латинские буквы, цифры, _ или -');
    if (name.toLowerCase() === 'server') throw new Error('Это имя зарезервировано OpenVPN');
    if (expiresAt !== null && (!Number.isInteger(expiresAt) || expiresAt < 0)) throw new Error('Неверный срок действия');
    const easy = '/etc/openvpn/easy-rsa';
    const cert = path.join(easy, 'pki/issued', name + '.crt');
    const key = path.join(easy, 'pki/private', name + '.key');
    if (!fs.existsSync(cert) || !fs.existsSync(key)) command(path.join(easy, 'easyrsa'), ['--batch', 'build-client-full', name, 'nopass'], 120000, easy);
    clientState(name, true, expiresAt);
    const contents = [
        'client', 'dev tun', 'proto udp', 'remote ' + settings.publicIp + ' ' + settings.openvpnPort,
        'resolv-retry infinite', 'nobind', 'persist-key', 'persist-tun', 'remote-cert-tls server',
        'auth-nocache', 'verb 3', 'data-ciphers AES-256-GCM:AES-128-GCM:CHACHA20-POLY1305',
        '<ca>', fs.readFileSync(path.join(easy, 'pki/ca.crt'), 'utf8').trim(), '</ca>',
        '<cert>', fs.readFileSync(cert, 'utf8').trim(), '</cert>',
        '<key>', fs.readFileSync(key, 'utf8').trim(), '</key>',
        '<tls-crypt>', fs.readFileSync(path.join(easy, 'pki/ta.key'), 'utf8').trim(), '</tls-crypt>'
    ];
    return contents.join('\n') + '\n';
}

const page = `<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Прокладка VPN</title><style>body{font:16px system-ui;background:#101825;color:#f0f4fa;max-width:650px;margin:8vh auto;padding:20px}section{background:#1b2638;border-radius:16px;padding:24px;margin:16px 0}input,button{font:inherit;padding:12px;border-radius:8px;border:0}input{background:#fff;width:calc(100% - 24px)}button{background:#48b6d9;cursor:pointer;margin-top:12px}small{color:#aeb9cb}pre{white-space:pre-wrap}</style><h1>Прокладка VPN</h1><section id="login"><p>Введите пароль администратора из установщика.</p><input id="password" type="password" autocomplete="current-password"><button onclick="signIn()">Войти</button></section><section id="dashboard" hidden><p id="state">Проверка…</p><small>Клиенты подключаются к OpenVPN на этом сервере. Дальше трафик идёт к основному VPN-серверу через работающий канал.</small><p><button onclick="refresh()">Обновить состояние</button></p></section><pre id="error"></pre><script>let session='';async function signIn(){const r=await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:document.getElementById('password').value})});const d=await r.json();if(!r.ok){document.getElementById('error').textContent=d.error;return}session=d.token;document.getElementById('login').hidden=true;document.getElementById('dashboard').hidden=false;refresh()}async function refresh(){const r=await fetch('/api/status',{headers:{Authorization:'Bearer '+session}});const d=await r.json();document.getElementById('state').textContent='OpenVPN: '+(d.openvpn?'работает':'не работает')+' · Канал: '+(d.active||'не подключён')+' · Связка: '+(d.paired?'готова':'ожидается')}</script></html>`;

async function handle(req, res) {
    try {
        const url = new URL(req.url, 'https://localhost');
        if (req.method === 'GET' && url.pathname === '/') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'X-Content-Type-Options': 'nosniff' }); return res.end(page); }
        if (req.method === 'POST' && url.pathname === '/api/login') {
            const remote = req.socket.remoteAddress || '';
            const saved = failedLogins.get(remote);
            const attempt = saved && saved.until > Date.now() ? saved : { count: 0, until: 0 };
            if (attempt.count >= 5 && attempt.until > Date.now()) return json(res, 429, { error: 'Подождите перед повторной попыткой' });
            const data = await body(req);
            if (!equal(crypto.createHash('sha256').update(String(data.password || '')).digest('hex'), settings.passwordHash)) {
                failedLogins.set(remote, { count: attempt.count + 1, until: Date.now() + 60000 });
                return json(res, 401, { error: 'Неверный пароль' });
            }
            failedLogins.delete(remote);
            const token = crypto.randomBytes(32).toString('hex');
            sessions.set(token, Date.now() + 30 * 60 * 1000);
            return json(res, 200, { token });
        }
        if (!auth(req)) return json(res, 401, { error: 'Требуется авторизация' });
        if (req.method === 'GET' && url.pathname === '/api/status') return json(res, 200, status());
        if (req.method === 'POST' && url.pathname === '/api/configure') return json(res, 200, { success: true, channels: configure(await body(req)), status: status() });
        if (req.method === 'POST' && url.pathname === '/api/client') {
            const current = status();
            if (!current.active || !current.controlFresh) return json(res, 409, { error: 'Нет работающей связи с основным сервером' });
            const data = await body(req);
            return json(res, 200, { name: data.name, ovpn: generateProfile(data.name, data.expiresAt ?? null) });
        }
        if (req.method === 'POST' && url.pathname === '/api/sync') {
            const data = await body(req);
            syncClients(data.clients);
            return json(res, 200, { success: true });
        }
        return json(res, 404, { error: 'Не найдено' });
    } catch (error) {
        return json(res, 400, { error: String(error.message || error).slice(0, 300) });
    }
}

module.exports = { xrayConfig, validatePairing };
if (require.main === module) {
    const server = https.createServer({ key: fs.readFileSync(path.join(root, 'tls.key')), cert: fs.readFileSync(path.join(root, 'tls.crt')) }, handle);
    server.listen(settings.panelPort, '0.0.0.0');
}
