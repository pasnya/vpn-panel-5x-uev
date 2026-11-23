<p align="center">
  <img src="panel_src/assets/logo.png" alt="VPN Panel 5X-UEV Logo" width="160" />
</p>

# 🚀 VPN panel 5X-UEV

[Русский](README.md) · [English](README.en.md) · [Documentation & Guides](https://pasnya.github.io/vpn-panel-5x-uev/)

[![License](https://img.shields.io/github/license/pasnya/vpn-panel-5x-uev?color=blue)](LICENSE)
[![GitHub release](https://img.shields.io/github/v/release/pasnya/vpn-panel-5x-uev?color=purple)](https://github.com/pasnya/vpn-panel-5x-uev/releases)

**VPN panel 5X-UEV** is a lightweight, beginner-friendly self-hosted VPN & proxy control panel for Ubuntu. Install everything from a guided browser installer with automatic `UFW` and `swap` setup, create your first **VLESS + Reality** profile in seconds without a domain, and run **5 protocols on port 443** simultaneously.

Need a cascade / relay setup? Deploy the **OpenVPN Relay** module on a second VPS and pair it with one string: clients connect via standard OpenVPN to the relay, and the relay forwards traffic over an active **Reality** or **xHTTP** tunnel to your main server.

---

## ✨ Key Features

* **Browser-Based Installer**: Step-by-step progress bar, optional domain & DNS pre-check, downloadable `.txt` credentials and one-click `.bat` / `.sh` SSH tunnel launchers.
* **5 Protocols on Port 443**:
  * `VLESS + Reality` (default, works by IP without a domain).
  * `VLESS + xHTTP` (HTTPS transport with domain).
  * `NaiveProxy` (via Caddy `forwardproxy`).
  * `Hysteria 2` (UDP/QUIC with port hopping `20000:50000/udp`).
  * `Mita (Mieru)` (TCP multiplexing).
* **OpenVPN Cascade Relay**: Separate relay server panel that bridges OpenVPN clients to your main server via Reality or xHTTP with automatic failover.
* **Cloudflare WARP Integration**: One-click WARP registration with Smart (OpenAI/Netflix/ipify) or Global routing modes.
* **Automatic SSL & Decoy Sites**: Let's Encrypt certificates via Certbot, DNS pre-flight check, and decoy templates (`blog`, `portfolio`, `landing`).
* **Built-in Server Health Diagnostics**: Check all 7 system services, listening ports (`80/tcp`, `443/tcp`, `443/udp`), disk, and memory from the dashboard.

---

## 💻 Requirements

* **OS**: Ubuntu 20.04 / 22.04 / 24.04 (clean server recommended)
* **Arch**: `x86_64` (AMD64) on 20.04/22.04/24.04; `arm64` on 22.04/24.04
* **Access**: `root` (or `sudo`)
* **Network**: Public IPv4, ports `80` and `443` open

---

## 🛠️ Quick Installation

Run the bootstrap command on a fresh Ubuntu server:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/pasnya/vpn-panel-5x-uev/v0.9.3/install.sh)"
```

Open the printed `https://SERVER_IP:8444/?token=...` link in your browser and click **Start Installation**.

For zero-touch console installation without the browser UI:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/pasnya/vpn-panel-5x-uev/v0.9.3/install.sh)" -- --auto
```

### Relay Server (Optional Second VPS)

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/pasnya/vpn-panel-5x-uev/v0.9.3/proxy_panel/install.sh)"
```

Paste the generated pairing string into the **Relay Server** section of the main panel.

---

## 📱 Recommended Clients

* **Windows / Android**: [Anarise VPN](https://github.com/pasnya/anarise-vpn)
* **iOS**: Shadowrocket, Streisand, Sing-box
* **macOS**: Nekoray / Nekobox, Sing-box
