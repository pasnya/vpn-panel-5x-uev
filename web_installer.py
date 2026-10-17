#!/usr/bin/env python3
"""Temporary HTTPS page that starts the downloaded installer on explicit request."""

import hmac
import json
import os
import ssl
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit


ROOT = Path(os.environ.get("VPN_INSTALLER_DIR", "/opt/vpn-panel-installer"))
PORT = int(os.environ.get("VPN_INSTALLER_PORT", "8444"))
TOKEN = (ROOT / "token").read_text(encoding="ascii").strip()
STATE_FILE = ROOT / "state.json"
LOG_FILE = ROOT / "install.log"
LOCK = threading.Lock()


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


def finish(process, output):
    result = process.wait()
    output.close()
    with LOCK:
        write_state({"state": "done" if result == 0 else "failed", "exit_code": result})


PAGE = """<!doctype html>
<html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Установка VPN panel 5X-UEV</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0f1724;color:#eef5ff;font:16px/1.5 system-ui,sans-serif}
main{max-width:780px;margin:5vh auto;padding:24px}section{background:#1b283b;border:1px solid #34445d;border-radius:18px;padding:28px}
h1{font-size:30px;margin:0 0 12px}p{color:#bfccde}button{background:#58c6e6;color:#09202c;border:0;border-radius:10px;padding:13px 20px;font:inherit;font-weight:700;cursor:pointer}
button:disabled{opacity:.55;cursor:default}pre{background:#0a111b;border-radius:10px;padding:16px;overflow:auto;max-height:52vh;white-space:pre-wrap;overflow-wrap:anywhere}
.hint{font-size:14px}#error{color:#ff9e9e}#logbox{display:none}
</style>
<main><section><h1>Установка VPN panel 5X-UEV</h1>
<p>Файлы установщика уже скачаны на сервер. Нажмите кнопку, чтобы установить панель и необходимые службы. Обычно это занимает несколько минут.</p>
<p id="state">Проверяю состояние…</p><button id="start" disabled>Начать установку</button>
<p class="hint">Не закрывайте эту вкладку во время установки. После завершения сохраните адрес панели и пароль администратора из журнала.</p>
<p id="error"></p><div id="logbox"><h2>Ход установки</h2><pre id="log"></pre></div>
</section></main>
<script>
const token=new URLSearchParams(location.search).get('token')||'';
const start=document.getElementById('start'),state=document.getElementById('state'),error=document.getElementById('error');
async function update(){
 try{
  const response=await fetch('/api/status?token='+encodeURIComponent(token),{cache:'no-store'});
  if(!response.ok)throw new Error('Ссылка недействительна. Запустите команду подготовки ещё раз.');
  const data=await response.json();
  start.disabled=data.state!=='ready';
  document.getElementById('logbox').style.display=data.log?'block':'none';
  document.getElementById('log').textContent=data.log||'';
  if(data.state==='ready')state.textContent='Готово к установке.';
  if(data.state==='running')state.textContent='Идёт установка…';
  if(data.state==='done')state.textContent='Установка завершена. Сохраните данные для входа из журнала ниже.';
  if(data.state==='failed')state.textContent='Установка остановилась с ошибкой. Смотрите последние строки журнала.';
  error.textContent='';
 }catch(e){error.textContent=e.message}
}
start.onclick=async()=>{
 start.disabled=true;
 try{const r=await fetch('/api/start?token='+encodeURIComponent(token),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
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
            self.respond_json(200, state)
        else:
            self.respond_json(404, {"error": "Не найдено"})

    def do_POST(self):
        if not self.authorized():
            return self.respond_json(403, {"error": "Неверная ссылка"})
        if urlsplit(self.path).path != "/api/start":
            return self.respond_json(404, {"error": "Не найдено"})
        if self.headers.get("Content-Type", "").split(";")[0] != "application/json":
            return self.respond_json(415, {"error": "Неверный формат запроса"})
        if int(self.headers.get("Content-Length", "0")) > 1024:
            return self.respond_json(413, {"error": "Слишком большой запрос"})
        with LOCK:
            if read_state()["state"] != "ready" or Path("/var/www/panel/db/panel.db").exists():
                return self.respond_json(409, {"error": "Установка уже начата"})
            output = LOG_FILE.open("wb")
            os.chmod(LOG_FILE, 0o600)
            try:
                process = subprocess.Popen(["/usr/bin/bash", str(ROOT / "full_install.sh")],
                                           cwd=ROOT, stdout=output, stderr=subprocess.STDOUT,
                                           stdin=subprocess.DEVNULL, start_new_session=False)
            except OSError:
                output.close()
                return self.respond_json(500, {"error": "Не удалось запустить установщик"})
            write_state({"state": "running", "pid": process.pid})
            threading.Thread(target=finish, args=(process, output), daemon=True).start()
        self.respond_json(202, {"state": "running"})


if __name__ == "__main__":
    server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(str(ROOT / "tls.crt"), str(ROOT / "tls.key"))
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()
