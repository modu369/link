#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Please run as root." >&2
  exit 1
fi

systemctl disable --now cf-agent.service || true
systemctl disable --now cf-controller.service || true
rm -f /etc/systemd/system/cf-agent.service
rm -f /etc/systemd/system/cf-controller.service
systemctl daemon-reload

rm -f /usr/local/bin/cf-agent
rm -f /usr/local/bin/cf-controller
rm -rf /opt/cf-relay
rm -rf /etc/cf-relay
rm -rf /var/lib/cf-relay

echo "Cloudflare Relay removed."
