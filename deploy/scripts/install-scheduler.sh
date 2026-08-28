#!/bin/bash
# Install systemd timer units for TL scheduler + optional backup/SIEM.
# Idempotent. Requires root and built binaries in ${ROOT}/bin.
set -euo pipefail

ROOT="${MANIFORGE_ROOT:-/opt/maniforge/platform-core}"
DEPLOY="${ROOT}/deploy"
ENV_FILE="${DEPLOY}/.env.platform"

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo "run as root (sudo)" >&2
    exit 1
  fi
}

install_unit() {
  local name="$1"
  install -m 0644 "${DEPLOY}/systemd/${name}" "/etc/systemd/system/${name}"
}

require_root

for bin in maniforge-tl-expire-licenses maniforge-tl-dispatch-events maniforge-siem-forward maniforge-backup-drill; do
  if [ ! -x "${ROOT}/bin/${bin}" ]; then
    echo "missing ${ROOT}/bin/${bin} — run server-build.sh first" >&2
    exit 1
  fi
done

echo "==> install scheduler systemd units"
for u in \
  maniforge-tl-expire.service maniforge-tl-expire.timer \
  maniforge-tl-dispatch.service maniforge-tl-dispatch.timer \
  maniforge-siem-forward.service maniforge-siem-forward.timer \
  maniforge-backup.service maniforge-backup.timer; do
  install_unit "$u"
done

systemctl daemon-reload
systemctl enable --now maniforge-tl-expire.timer maniforge-tl-dispatch.timer maniforge-backup.timer

if [ -f "$ENV_FILE" ] && grep -qE '^RBAC_SIEM_WEBHOOK_ENABLED=true' "$ENV_FILE" 2>/dev/null; then
  systemctl enable --now maniforge-siem-forward.timer
  echo "  siem-forward timer enabled (RBAC_SIEM_WEBHOOK_ENABLED=true)"
else
  systemctl disable --now maniforge-siem-forward.timer 2>/dev/null || true
  echo "  siem-forward timer skipped (set RBAC_SIEM_WEBHOOK_ENABLED=true to enable)"
fi

echo "install-scheduler: OK"
systemctl list-timers 'maniforge-*' --no-pager || true
