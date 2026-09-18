#!/bin/bash
# Post-install verification: systemd, gateway health, Postgres replication.
# Never prints secrets.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${MANIFORGE_ROOT:-/opt/maniforge/platform-core}"
DEPLOY="${ROOT}/deploy"
ENV_FILE="${DEPLOY}/.env.platform"
COMPOSE_FILE="${DEPLOY}/compose.platform.server.yml"

if [ ! -f "$ENV_FILE" ]; then
  echo "missing $ENV_FILE" >&2
  exit 1
fi

fail=0

MODULES_BIN="${ROOT}/bin/maniforge-modules"
if [ ! -x "$MODULES_BIN" ]; then
  echo "missing $MODULES_BIN" >&2
  exit 1
fi
eval "$("$MODULES_BIN" resolve --root "$ROOT" --env "$ENV_FILE")"
# shellcheck disable=SC2206
UNITS=(${MANIFORGE_SYSTEMD_ENABLE})

cd "$DEPLOY"

echo "==> systemd"
for u in "${UNITS[@]}"; do
  if ! systemctl is-active --quiet "$u"; then
    echo "systemd not active: $u" >&2
    fail=1
  else
    echo "  ok $u"
  fi
done

echo "==> gateway health"
ENV="$ENV_FILE"
MANIFORGE_ROOT="$ROOT"
# shellcheck source=lib/gateway-health.sh
. "${SCRIPT_DIR}/lib/gateway-health.sh"
if ! gateway_health_check; then
  fail=1
fi

echo "==> postgres primary"
if ! docker exec maniforge-pg-primary pg_isready -U maniforge -d maniforge >/dev/null 2>&1; then
  echo "postgres primary not ready" >&2
  fail=1
else
  echo "  ok primary"
fi

echo "==> replication"
rep="$(docker exec maniforge-pg-primary psql -U maniforge -d maniforge -tAc \
  "SELECT count(*) FROM pg_stat_replication WHERE state = 'streaming';" 2>/dev/null || echo 0)"
if [ "${rep:-0}" -lt 1 ]; then
  echo "replication: no streaming replica" >&2
  fail=1
else
  docker exec maniforge-pg-primary psql -U maniforge -d maniforge -tAc \
    "SELECT client_addr, state, sync_state FROM pg_stat_replication;"
fi

echo "==> docker postgres"
if ! docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" ps --status running 2>/dev/null | grep -q maniforge-pg-primary; then
  echo "compose: postgres-primary not running" >&2
  fail=1
fi

if [ "$fail" -ne 0 ]; then
  echo "verify-maniforge: FAILED" >&2
  exit 1
fi

echo "==> preflight"
if [ -x "${ROOT}/bin/maniforge-preflight" ]; then
  if ! (cd "$ROOT" && systemd-run --quiet --wait --pipe --collect \
    --property="EnvironmentFile=${ENV_FILE}" \
    --working-directory="$ROOT" \
    "${ROOT}/bin/maniforge-preflight"); then
    echo "preflight failed (APP_ENV=production requires strict tokens/PII)" >&2
    fail=1
  else
    echo "  ok preflight"
  fi
else
  echo "  skip preflight (bin/maniforge-preflight missing — run server-build.sh)" >&2
fi

if [ "$fail" -ne 0 ]; then
  echo "verify-maniforge: FAILED" >&2
  exit 1
fi

echo "verify-maniforge: OK"
