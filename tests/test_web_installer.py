"""Smoke test the temporary web installer without changing the runner."""

import http.client
import json
import os
import pathlib
import socket
import ssl
import subprocess
import tempfile
import time


SOURCE = pathlib.Path(__file__).resolve().parents[1] / "web_installer.py"


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
    server = subprocess.Popen(["python3", str(SOURCE)], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    try:
        for _ in range(40):
            try:
                status, _ = request(port, "/?token=test-token")
                break
            except (ConnectionError, OSError):
                time.sleep(0.1)
        assert status == 200
        assert not (root / "install.log").exists(), "Opening the page must not install anything"
        assert request(port, "/api/status?token=wrong")[0] == 403
        status, data = request(port, "/api/status?token=test-token")
        assert status == 200 and json.loads(data)["state"] == "ready"
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
