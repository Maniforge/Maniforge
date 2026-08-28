#!/bin/bash
# Daily PostgreSQL backup (custom format). Requires pg_dump client + live .env.platform.
# Never prints secrets.
set -euo pipefail

ROOT="${MANIFORGE_ROOT:-/opt/maniforge/platform-core}"
DEPLOY="${ROOT}/deploy"
ENV_FILE="${DEPLOY}/.env.platform"
BACKUP_DIR="${MANIFORGE_BACKUP_DIR:-/var/backups/maniforge}"

if [ ! -f "$ENV_FILE" ]; then
  echo "missing $ENV_FILE" >&2
  exit 1
fi

# shellcheck disable=SC1091
. "$ENV_FILE"

mkdir -p "$BACKUP_DIR"
stamp="$(date +%F)"
out="${BACKUP_DIR}/maniforge-${stamp}.dump"

export PGPASSWORD="${MANIFORGE_DB_PASS:-}"

pg_dump -Fc \
  -h "${MANIFORGE_DB_HOST:-127.0.0.1}" \
  -p "${MANIFORGE_DB_PORT:-18096}" \
  -U "${MANIFORGE_DB_USER:-maniforge}" \
  "${MANIFORGE_DB_NAME:-maniforge}" \
  > "$out"

unset PGPASSWORD

# Keep last 14 daily dumps.
find "$BACKUP_DIR" -maxdepth 1 -name 'maniforge-*.dump' -type f -mtime +14 -delete 2>/dev/null || true

echo "backup-postgres: wrote ${out} ($(du -h "$out" | awk '{print $1}'))"
