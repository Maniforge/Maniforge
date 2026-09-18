#!/bin/bash
# One command: clone Maniforge from GitHub, install platform, put Desk on the edge.
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/Maniforge/Maniforge/platform-core/deploy/scripts/install-from-git.sh \
#     | sudo bash -s -- --domain nzgapp.ru --edge-proxy --skip-apt
# Or, from an existing checkout:
#   sudo bash deploy/scripts/install-from-git.sh --domain nzgapp.ru --edge-proxy --skip-apt
set -euo pipefail

ROOT="${MANIFORGE_ROOT:-/opt/maniforge/platform-core}"
URL="${MANIFORGE_GIT_URL:-https://github.com/Maniforge/Maniforge.git}"
BRANCH="${MANIFORGE_GIT_BRANCH:-platform-core}"
STAMP="$(date +%Y%m%d-%H%M%S)"

if [ "$(id -u)" -ne 0 ]; then
  echo "run as root (sudo)" >&2
  exit 1
fi

export PATH="/usr/local/go/bin:${PATH}"

echo "==> stop previous Maniforge units"
systemctl list-units --all --no-legend 'maniforge-*' 2>/dev/null | awk '{print $1}' | while read -r u; do
  [ -n "$u" ] || continue
  systemctl stop "$u" 2>/dev/null || true
done

if [ -f "${ROOT}/deploy/compose.platform.server.yml" ]; then
  echo "==> docker compose down -v (maniforge postgres)"
  if [ -f "${ROOT}/deploy/.env.platform" ]; then
    docker compose -f "${ROOT}/deploy/compose.platform.server.yml" --env-file "${ROOT}/deploy/.env.platform" down -v --remove-orphans || true
  else
    docker compose -f "${ROOT}/deploy/compose.platform.server.yml" down -v --remove-orphans || true
  fi
fi

TMP="$(mktemp -d /tmp/maniforge-src.XXXXXX)"
echo "==> clone ${BRANCH} from GitHub -> ${TMP}"
git clone --branch "$BRANCH" "$URL" "$TMP"

if [ -e "$ROOT" ]; then
  echo "==> backup ${ROOT} -> ${ROOT}.bak-${STAMP}"
  mv "$ROOT" "${ROOT}.bak-${STAMP}"
fi
mv "$TMP" "$ROOT"

find "${ROOT}/deploy" -type f -name "*.sh" -exec sed -i 's/\r$//' {} + 2>/dev/null || true

echo "==> install-maniforge $*"
exec bash "${ROOT}/deploy/scripts/install-maniforge.sh" "$@"
