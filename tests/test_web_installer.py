"""Smoke test the temporary web installer without changing the runner."""

import http.client
import json
import os
import pathlib
import re
import shutil
import socket
import ssl
import subprocess
import sys
import tempfile
import time


SOURCE = pathlib.Path(__file__).resolve().parents[1] / "web_installer.py"
# Запуск установки вызывает /usr/bin/bash и следит за процессом через /proc - это есть только на Linux.
CAN_RUN_INSTALL = sys.platform.startswith("linux") and pathlib.Path("/usr/bin/bash").exists()


def request(port, route, method="GET", body=None, headers=None):
    context = ssl._create_unverified_context()
    connection = http.client.HTTPSConnection("127.0.0.1", port, context=context, timeout=3)
    connection.request(method, route, body=body, headers=headers or {})
    response = connection.getresponse()
    data = response.read()
    connection.close()
    return response.status, data


with tempfile.TemporaryDirectory() as folder:
    root = pathlib.Path(folder)
    (root / "token").write_text("test-token", encoding="ascii")
    (root / "full_install.sh").write_text("#!/bin/bash\necho WEB_INSTALL_STARTED\n", encoding="utf-8")
    subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-sha256", "-nodes",
                    "-days", "1", "-keyout", str(root / "tls.key"), "-out", str(root / "tls.crt"),
                    "-subj", "/CN=localhost"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, VPN_INSTALLER_DIR=str(root), VPN_INSTALLER_PORT=str(port))
    server = subprocess.Popen([sys.executable, str(SOURCE)], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    try:
        for _ in range(40):
            try:
                status, page = request(port, "/?token=test-token")
                break
            except (ConnectionError, OSError):
                time.sleep(0.1)
        assert status == 200
        scripts = re.findall(r"<script>(.*?)</script>", page.decode("utf-8"), re.S)
        assert scripts, "Installer page must contain its script"
        node = shutil.which("node")
        if node:
            script_file = root / "page.js"
            script_file.write_text("\n".join(scripts), encoding="utf-8")
            check = subprocess.run([node, "--check", str(script_file)], capture_output=True, text=True)
            assert check.returncode == 0, "Installer page JavaScript has a syntax error:\n" + check.stderr
        else:
            print("node not found: skipping installer page JavaScript syntax check")
        assert not (root / "install.log").exists(), "Opening the page must not install anything"
        assert request(port, "/api/status?token=wrong")[0] == 403
        status, data = request(port, "/api/status?token=test-token")
        assert status == 200 and json.loads(data)["state"] == "ready"
        if not CAN_RUN_INSTALL:
            print("not Linux: skipping the install start check (needs /usr/bin/bash and /proc)")
            raise SystemExit(0)
        status, _ = request(port, "/api/start?token=test-token", "POST", "{}", {"Content-Type": "application/json"})
        assert status == 202
        assert request(port, "/api/start?token=test-token", "POST", "{}", {"Content-Type": "application/json"})[0] == 409
        for _ in range(40):
            _, data = request(port, "/api/status?token=test-token")
            if json.loads(data)["state"] == "done":
                break
            time.sleep(0.1)
        result = json.loads(data)
        assert result["state"] == "done" and "WEB_INSTALL_STARTED" in result["log"]
    finally:
        server.terminate()
        server.wait(timeout=5)
