#!/usr/bin/env python3
"""Temporary HTTPS page that starts the downloaded installer on explicit request."""

import hmac
import json
import os
import re
import socket
import ssl
import subprocess
import threading
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit


ROOT = Path(os.environ.get("VPN_INSTALLER_DIR", "/opt/vpn-panel-installer"))
PORT = int(os.environ.get("VPN_INSTALLER_PORT", "8444"))
TOKEN = (ROOT / "token").read_text(encoding="ascii").strip()
STATE_FILE = ROOT / "state.json"
LOG_FILE = ROOT / "install.log"
SUMMARY_FILE = ROOT / "summary.json"
LOCK = threading.Lock()
DOMAIN_RE = re.compile(r"^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")
STEP_RE = re.compile(r"\[STEP\s+(\d+)/(\d+)\]\s*(.+)")


def read_state():
    try:
        state = json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except (FileNotFoundError, ValueError):
        return {"state": "ready"}
    if state.get("state") == "running":
        # A service restart terminates its child installer process.
        pid = state.get("pid")
        if not isinstance(pid, int) or not Path(f"/proc/{pid}").exists():
            return {"state": "failed", "message": "Установка прервана. Проверьте журнал."}
    return state


def write_state(value):
    temporary = STATE_FILE.with_suffix(".new")
    temporary.write_text(json.dumps(value, ensure_ascii=False), encoding="utf-8")
    os.chmod(temporary, 0o600)
    temporary.replace(STATE_FILE)


def parse_progress(log_text, current_state):
    if current_state == "done":
        return 100, "Установка полностью завершена!"
    matches = STEP_RE.findall(log_text or "")
    if not matches:
        if current_state == "running":
            return 5, "Подготовка к установке…"
        return 0, ""
    step_num, total_steps, label = matches[-1]
    cur = max(1, int(step_num))
    tot = max(cur, int(total_steps))
    pct = min(98, int((cur - 0.5) / tot * 100))
    return pct, f"Шаг {cur} из {tot}: {label.strip()}"


def read_summary():
    try:
        return json.loads(SUMMARY_FILE.read_text(encoding="utf-8"))
    except (FileNotFoundError, ValueError):
        return None


def get_public_ip():
    try:
        with urllib.request.urlopen("https://api.ipify.org", timeout=3) as resp:
            ip = resp.read().decode("ascii", "ignore").strip()
            if re.match(r"^\d+\.\d+\.\d+\.\d+$", ip):
                return ip
    except Exception:
        pass
    return None


def retire():
    # Keep the final log available briefly, then close the root web service.
    subprocess.run(["/usr/bin/systemctl", "disable", "vpn-panel-installer.service"],
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
    marker = ROOT / "ufw_opened"
    if marker.exists():
        subprocess.run(["/usr/sbin/ufw", "delete", "allow", f"{PORT}/tcp"],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
        marker.unlink(missing_ok=True)
    (ROOT / "token").unlink(missing_ok=True)
    SUMMARY_FILE.unlink(missing_ok=True)
    os._exit(0)


def finish(process, output):
    result = process.wait()
    output.close()
    with LOCK:
        write_state({"state": "done" if result == 0 else "failed", "exit_code": result})
    threading.Timer(3600, retire).start()


# raw-строка: обратные слэши (\n, \r\n) должны попасть в JavaScript как есть, иначе скрипт страницы не разберётся.
PAGE = r"""<!doctype html>
<html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Установка VPN panel 5X-UEV</title>
<link rel="icon" href="data:,">
<style>
*{box-sizing:border-box}body{margin:0;background:#0f1724;color:#eef5ff;font:16px/1.55 system-ui,-apple-system,sans-serif}
main{max-width:780px;margin:4vh auto;padding:20px}section{background:#1b283b;border:1px solid #34445d;border-radius:18px;padding:28px;box-shadow:0 20px 45px rgba(0,0,0,.35)}
h1{font-size:28px;margin:0 0 10px}h2{font-size:19px;margin:18px 0 10px}p{color:#bfccde;margin:8px 0}
label{display:block;font-size:14px;color:#d6e2f5;margin:12px 0 4px;font-weight:600}
input,select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid #3b4f6b;background:#0e1726;color:#eef5ff;font:inherit}
button{background:#58c6e6;color:#09202c;border:0;border-radius:10px;padding:12px 18px;font:inherit;font-weight:700;cursor:pointer;transition:opacity .15s}
button:hover{opacity:.92}button:disabled{opacity:.5;cursor:default}
.btn-sec{background:#273852;color:#eef5ff;border:1px solid #3c5375;padding:9px 14px;font-size:14px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
.progress-wrap{background:#0a111b;border-radius:999px;height:14px;overflow:hidden;margin:14px 0 8px;border:1px solid #2b3c54;display:none}
.progress-bar{height:100%;width:0;background:linear-gradient(90deg,#58c6e6,#6ee7b7);transition:width .4s ease}
.card{background:#121d2d;border:1px solid #2f435e;border-radius:14px;padding:18px;margin-top:18px}
.kv{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);flex-wrap:wrap}
.kv:last-child{border-bottom:0}
code{background:#0a111b;padding:4px 8px;border-radius:6px;font-family:ui-monospace,monospace;font-size:13px;word-break:break-all;color:#9ee8ff}
pre{background:#0a111b;border-radius:10px;padding:14px;overflow:auto;max-height:46vh;white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px}
details{margin-top:16px}summary{cursor:pointer;color:#93c5fd;font-weight:600;user-select:none}
.hint{font-size:13px;color:#9fb1c9}#error{color:#ff9e9e;font-weight:600}#dnsResult{font-size:13px;margin-top:8px}
.ok{color:#6ee7b7}.warn{color:#fcd34d}
</style>
<main><section>
<h1>🚀 Установка VPN panel 5X-UEV</h1>
<p>Файлы уже загружены на сервер. Можно начать сразу — VPN заработает по IP без домена (VLESS + Reality).</p>

<details id="domainOpts">
  <summary>🌐 У меня уже есть домен (необязательно)</summary>
  <div class="card" style="margin-top:10px">
    <p class="hint">Если привязать домен сейчас, панель сразу поставит выпуск SSL-сертификата и включит Hysteria 2, NaiveProxy и xHTTP. Перед этим создайте A-записи домена и поддомена на IP вашего сервера.</p>
    <label for="domain">Основной домен</label>
    <input id="domain" placeholder="например, vpn.example.com" autocomplete="off">
    <label for="naiveSub">Поддомен для NaiveProxy</label>
    <input id="naiveSub" placeholder="например, n.vpn.example.com" autocomplete="off">
    <label for="decoy">Сайт-заглушка для маскировки</label>
    <select id="decoy">
      <option value="blog">Блог / Статьи</option>
      <option value="portfolio">Портфолио</option>
      <option value="landing">Лендинг</option>
    </select>
    <div class="row">
      <button type="button" class="btn-sec" id="checkDnsBtn">Проверить DNS перед установкой</button>
      <span id="dnsResult"></span>
    </div>
  </div>
</details>

<div class="row" style="margin-top:18px">
  <button id="start" disabled>Начать установку</button>
  <span id="state" style="color:#bfccde">Проверяю состояние…</span>
</div>

<div class="progress-wrap" id="progressWrap"><div class="progress-bar" id="progressBar"></div></div>
<p class="hint" id="stepLabel"></p>
<p id="error"></p>

<div id="resultBox" class="card" style="display:none">
  <h2 style="margin-top:0">✅ Всё готово! Сохраните данные для входа</h2>
  <div class="kv"><span>Логин:</span><code id="resLogin">admin</code></div>
  <div class="kv"><span>Пароль:</span><div class="row" style="margin:0"><code id="resPass">—</code><button type="button" class="btn-sec" id="copyPassBtn">Копировать</button></div></div>
  <div class="kv"><span>Команда SSH-туннеля:</span><div class="row" style="margin:0"><code id="resSsh">—</code><button type="button" class="btn-sec" id="copySshBtn">Копировать</button></div></div>
  <div class="kv"><span>Адрес панели (через туннель):</span><div class="row" style="margin:0"><code id="resUrl">—</code><button type="button" class="btn-sec" id="copyUrlBtn">Копировать</button></div></div>
  <div class="kv" id="resDomainRow" style="display:none"><span>Адрес по домену (после выпуска SSL):</span><code id="resDomainUrl">—</code></div>
  <div class="row" style="margin-top:16px">
    <button type="button" id="dlTxtBtn">📄 Скачать памятку (.txt)</button>
    <button type="button" class="btn-sec" id="dlBatBtn">🪟 Скрипт входа для Windows (.bat)</button>
    <button type="button" class="btn-sec" id="dlShBtn">🍎 Скрипт входа macOS/Linux (.sh)</button>
  </div>
  <p class="hint" style="margin-top:12px">Веб-инсталлятор автоматически закроется и закроет порт 8444 через 1 час. Если вы скачали памятку, вкладку можно закрывать.</p>
</div>

<details id="logbox" style="display:none">
  <summary>📋 Подробный журнал установки</summary>
  <pre id="log"></pre>
</details>
</section></main>
<script>
const token=new URLSearchParams(location.search).get('token')||'';
const start=document.getElementById('start'),state=document.getElementById('state'),error=document.getElementById('error');
const domainInput=document.getElementById('domain'),naiveInput=document.getElementById('naiveSub'),decoyInput=document.getElementById('decoy');
let lastSummary=null;

domainInput.addEventListener('input',()=>{
  const d=domainInput.value.trim().toLowerCase();
  if(d&&!naiveInput.dataset.touched)naiveInput.value='n.'+d;
});
naiveInput.addEventListener('input',()=>{naiveInput.dataset.touched='1';});

function copyText(txt,btn){
  navigator.clipboard.writeText(txt).then(()=>{const old=btn.textContent;btn.textContent='Скопировано!';setTimeout(()=>btn.textContent=old,1500);});
}
function downloadFile(name,content){
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([content],{type:'text/plain;charset=utf-8'}));
  a.download=name;a.click();URL.revokeObjectURL(a.href);
}

document.getElementById('checkDnsBtn').onclick=async()=>{
  const resEl=document.getElementById('dnsResult');
  resEl.textContent='Проверяю DNS…';resEl.className='';
  try{
    const r=await fetch('/api/check-dns?token='+encodeURIComponent(token),{
      method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({domain:domainInput.value.trim(),naive_sub:naiveInput.value.trim()})
    });
    const d=await r.json();
    if(!r.ok)throw new Error(d.error||'Ошибка проверки');
    resEl.textContent=d.message;
    resEl.className=d.ok?'ok':'warn';
  }catch(e){resEl.textContent=e.message;resEl.className='warn';}
};

function renderSummary(s){
  if(!s||lastSummary)return;
  lastSummary=s;
  const sshCmd=`ssh -L ${s.admin_port}:127.0.0.1:${s.admin_port} root@${s.ip}`;
  const panelUrl=`http://127.0.0.1:${s.admin_port}/${s.admin_path}/index.php`;
  document.getElementById('resLogin').textContent=s.login||'admin';
  document.getElementById('resPass').textContent=s.password||'(установлен ранее)';
  document.getElementById('resSsh').textContent=sshCmd;
  document.getElementById('resUrl').textContent=panelUrl;
  if(s.domain){
    document.getElementById('resDomainRow').style.display='flex';
    document.getElementById('resDomainUrl').textContent=`https://${s.domain}/${s.admin_path}/index.php`;
  }
  document.getElementById('copyPassBtn').onclick=e=>copyText(s.password||'',e.target);
  document.getElementById('copySshBtn').onclick=e=>copyText(sshCmd,e.target);
  document.getElementById('copyUrlBtn').onclick=e=>copyText(panelUrl,e.target);
  document.getElementById('dlTxtBtn').onclick=()=>{
    const txt=[
      '=== VPN panel 5X-UEV — Данные для входа ===',
      `IP сервера: ${s.ip}`,
      `Логин: ${s.login}`,
      `Пароль: ${s.password}`,
      `Reality Public Key: ${s.public_key||'-'}`,
      '',
      'Как войти в панель:',
      `1. Откройте терминал / PowerShell и выполните: ${sshCmd}`,
      `2. Откройте в браузере: ${panelUrl}`,
      s.domain?`\nПосле выпуска SSL-сертификата панель также откроется по адресу:\nhttps://${s.domain}/${s.admin_path}/index.php`:''
    ].join('\n');
    downloadFile('vpn-panel-access.txt',txt);
  };
  document.getElementById('dlBatBtn').onclick=()=>{
    const bat=`@echo off\r\necho Opening VPN Panel tunnel...\r\nstart "" "${panelUrl}"\r\n${sshCmd}\r\npause\r\n`;
    downloadFile('open-panel-windows.bat',bat);
  };
  document.getElementById('dlShBtn').onclick=()=>{
    const sh=`#!/usr/bin/env bash\n(sleep 2 && (command -v open >/dev/null && open "${panelUrl}" || xdg-open "${panelUrl}")) &\n${sshCmd}\n`;
    downloadFile('open-panel-mac-linux.sh',sh);
  };
  document.getElementById('resultBox').style.display='block';
}

async function update(){
 try{
  const response=await fetch('/api/status?token='+encodeURIComponent(token),{cache:'no-store'});
  if(!response.ok)throw new Error('Ссылка недействительна. Запустите команду подготовки ещё раз.');
  const data=await response.json();
  start.disabled=data.state!=='ready';
  document.getElementById('domainOpts').style.display=data.state==='ready'?'block':'none';
  document.getElementById('logbox').style.display=data.log?'block':'none';
  document.getElementById('log').textContent=data.log||'';
  if(data.state==='running'||data.state==='done'){
    document.getElementById('progressWrap').style.display='block';
    document.getElementById('progressBar').style.width=(data.progress||0)+'%';
    document.getElementById('stepLabel').textContent=data.step_label||'';
  }
  if(data.state==='ready')state.textContent='Готово к установке.';
  if(data.state==='running')state.textContent='Идёт установка… (не закрывайте вкладку)';
  if(data.state==='done'){state.textContent='Установка завершена!';renderSummary(data.summary);}
  if(data.state==='failed'){state.textContent='Установка остановилась с ошибкой. Откройте журнал ниже.';document.getElementById('logbox').open=true;}
  error.textContent='';
 }catch(e){error.textContent=e.message}
}
start.onclick=async()=>{
 start.disabled=true;
 const payload={};
 const d=domainInput.value.trim().toLowerCase();
 const n=naiveInput.value.trim().toLowerCase();
 if(d){payload.domain=d;payload.naive_sub=n||('n.'+d);payload.decoy=decoyInput.value;}
 try{const r=await fetch('/api/start?token='+encodeURIComponent(token),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  if(!r.ok)throw new Error((await r.json()).error||'Не удалось начать установку');
  await update();
 }catch(e){error.textContent=e.message;await update()}
};
update();setInterval(update,2000);
</script></html>"""


class Handler(BaseHTTPRequestHandler):
    def send_headers(self, code, content_type):
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.end_headers()

    def authorized(self):
        supplied = parse_qs(urlsplit(self.path).query).get("token", [""])[0]
        return hmac.compare_digest(supplied, TOKEN)

    def respond_json(self, code, data):
        self.send_headers(code, "application/json; charset=utf-8")
        self.wfile.write(json.dumps(data, ensure_ascii=False).encode("utf-8"))

    def read_json_body(self):
        if self.headers.get("Content-Type", "").split(";")[0] != "application/json":
            return None, (415, "Неверный формат запроса")
        length = int(self.headers.get("Content-Length", "0"))
        if length > 2048:
            return None, (413, "Слишком большой запрос")
        raw = self.rfile.read(length) if length > 0 else b"{}"
        try:
            data = json.loads(raw.decode("utf-8"))
        except ValueError:
            return None, (400, "Некорректный JSON")
        if not isinstance(data, dict):
            return None, (400, "Ожидается JSON-объект")
        return data, None

    def do_GET(self):
        if not self.authorized():
            return self.respond_json(403, {"error": "Неверная ссылка"})
        route = urlsplit(self.path).path
        if route == "/":
            self.send_headers(200, "text/html; charset=utf-8")
            self.wfile.write(PAGE.encode("utf-8"))
        elif route == "/api/status":
            state = read_state()
            try:
                with LOG_FILE.open("rb") as log:
                    log.seek(0, os.SEEK_END)
                    log.seek(max(0, log.tell() - 250000))
                    state["log"] = log.read().decode("utf-8", "replace")
            except FileNotFoundError:
                state["log"] = ""
            pct, step_label = parse_progress(state["log"], state.get("state"))
            state["progress"] = pct
            state["step_label"] = step_label
            if state.get("state") == "done":
                summary = read_summary()
                if summary:
                    state["summary"] = summary
            self.respond_json(200, state)
        else:
            self.respond_json(404, {"error": "Не найдено"})

    def do_POST(self):
        if not self.authorized():
            return self.respond_json(403, {"error": "Неверная ссылка"})
        route = urlsplit(self.path).path
        body, err = self.read_json_body()
        if err:
            return self.respond_json(err[0], {"error": err[1]})

        if route == "/api/check-dns":
            domain = str(body.get("domain", "")).strip().lower()
            naive_sub = str(body.get("naive_sub", "")).strip().lower()
            if not DOMAIN_RE.match(domain) or not DOMAIN_RE.match(naive_sub) or not naive_sub.endswith("." + domain):
                return self.respond_json(400, {"error": "Укажите корректный домен и поддомен (например, n.site.com)"})
            server_ip = get_public_ip()
            if not server_ip:
                return self.respond_json(200, {"ok": False, "message": "Не удалось определить внешний IP сервера для сверки."})
            missing = []
            for host in (domain, naive_sub):
                try:
                    ips = {info[4][0] for info in socket.getaddrinfo(host, None, socket.AF_INET)}
                except socket.gaierror:
                    ips = set()
                if server_ip not in ips:
                    missing.append(host)
            if not missing:
                return self.respond_json(200, {"ok": True, "message": f"Отлично! DNS для {domain} и {naive_sub} указывает на {server_ip}."})
            return self.respond_json(200, {
                "ok": False,
                "message": f"Пока не найдена A-запись на {server_ip} для: {', '.join(missing)}. Настройте DNS у регистратора."
            })

        if route != "/api/start":
            return self.respond_json(404, {"error": "Не найдено"})

        env = os.environ.copy()
        env["VPN_INSTALLER_DIR"] = str(ROOT)
        domain = str(body.get("domain", "")).strip().lower()
        naive_sub = str(body.get("naive_sub", "")).strip().lower()
        decoy = str(body.get("decoy", "blog")).strip().lower()
        if domain:
            if not naive_sub:
                naive_sub = "n." + domain
            if not DOMAIN_RE.match(domain) or not DOMAIN_RE.match(naive_sub) or not naive_sub.endswith("." + domain):
                return self.respond_json(400, {"error": "Некорректный домен или поддомен для NaiveProxy"})
            if decoy not in ("blog", "portfolio", "landing"):
                decoy = "blog"
            env["INSTALL_DOMAIN"] = domain
            env["INSTALL_NAIVE_SUB"] = naive_sub
            env["INSTALL_DECOY"] = decoy

        with LOCK:
            if read_state()["state"] != "ready" or Path("/var/www/panel/db/panel.db").exists():
                return self.respond_json(409, {"error": "Установка уже начата"})
            output = LOG_FILE.open("wb")
            os.chmod(LOG_FILE, 0o600)
            try:
                process = subprocess.Popen(["/usr/bin/bash", str(ROOT / "full_install.sh")],
                                           cwd=ROOT, env=env, stdout=output, stderr=subprocess.STDOUT,
                                           stdin=subprocess.DEVNULL, start_new_session=False)
            except OSError:
                output.close()
                return self.respond_json(500, {"error": "Не удалось запустить установщик"})
            write_state({"state": "running", "pid": process.pid})
            threading.Thread(target=finish, args=(process, output), daemon=True).start()
        self.respond_json(202, {"state": "running"})


if __name__ == "__main__":
    if read_state()["state"] in ("done", "failed"):
        threading.Timer(3600, retire).start()
    server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(str(ROOT / "tls.crt"), str(ROOT / "tls.key"))
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()
