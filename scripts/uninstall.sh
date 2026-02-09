#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Please run as root." >&2
  exit 1
fi

systemctl disable --now cf-relay.service || true
rm -f /etc/systemd/system/cf-relay.service
systemctl daemon-reload

rm -f /usr/local/bin/cf-relay
rm -rf /opt/cf-relay
rm -rf /etc/cf-relay
rm -rf /var/lib/cf-relay

echo "Cloudflare Relay removed."
