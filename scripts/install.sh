#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Please run as root." >&2
  exit 1
fi

apt-get update
apt-get install -y --no-install-recommends golang ca-certificates

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

install -d /opt/cf-relay
install -d /etc/cf-relay
install -d /var/lib/cf-relay/autocert

cp -r "$ROOT_DIR"/* /opt/cf-relay/

cd /opt/cf-relay
MODE=${MODE:-agent}

if [[ "$MODE" == "controller" ]]; then
  /usr/bin/go build -o /usr/local/bin/cf-controller ./cmd/controller
else
  /usr/bin/go build -o /usr/local/bin/cf-agent ./cmd/agent
fi

if [[ "$MODE" == "controller" ]]; then
  if [[ ! -f /etc/cf-relay/controller.json ]]; then
    cat <<'CONFIG' > /etc/cf-relay/controller.json
{
  "listen_http": ":8080",
  "listen_https": "",
  "admin_listen": ":8080",
  "admin_user": "admin",
  "admin_password_hash": "",
  "whitelist_domains": [],
  "cloudflare_upstreams": [],
  "round_robin": true,
  "enable_http_proxy": true
}
CONFIG
  fi
else
  if [[ ! -f /etc/cf-relay/config.json ]]; then
    cat <<'CONFIG' > /etc/cf-relay/config.json
{
  "listen_http": ":80",
  "listen_https": ":443",
  "admin_listen": ":8080",
  "admin_user": "admin",
  "admin_password_hash": "",
  "whitelist_domains": [],
  "cloudflare_upstreams": [],
  "round_robin": true,
  "enable_http_proxy": true
}
CONFIG
  fi
fi

if [[ "$MODE" == "controller" ]]; then
  cat <<'UNIT' > /etc/systemd/system/cf-controller.service
[Unit]
Description=Cloudflare Relay Controller
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/cf-controller -config /etc/cf-relay/controller.json -listen :8080
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

  systemctl daemon-reload
  systemctl enable --now cf-controller.service

  cat <<'DONE'
Cloudflare Relay controller installed.
- Admin panel: http://<server_ip>:8080/admin
- Register agents and push config from the controller.
DONE
else
  cat <<'UNIT' > /etc/systemd/system/cf-agent.service
[Unit]
Description=Cloudflare Relay Agent
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/cf-agent -config /etc/cf-relay/config.json -agent-listen :9090 -agent-token ${AGENT_TOKEN:-changeme}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

  systemctl daemon-reload
  systemctl enable --now cf-agent.service

  cat <<'DONE'
Cloudflare Relay agent installed.
- Agent control API: http://<server_ip>:9090/agent/config
- Admin panel (local): http://<server_ip>:8080/admin
- Set AGENT_TOKEN env for secure remote control.
DONE
fi
