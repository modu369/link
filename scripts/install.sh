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
/usr/bin/go build -o /usr/local/bin/cf-relay ./cmd/relay

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

cat <<'UNIT' > /etc/systemd/system/cf-relay.service
[Unit]
Description=Cloudflare Relay
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/cf-relay -config /etc/cf-relay/config.json
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now cf-relay.service

cat <<'DONE'
Cloudflare Relay installed.
- Admin panel: http://<server_ip>:8080/admin
- Configure whitelist domains and upstreams in the admin panel.
- Set admin password in the admin panel.
DONE
