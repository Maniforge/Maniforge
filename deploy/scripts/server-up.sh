#!/bin/bash
# Bring up Postgres (Docker), migrate, restart host Go + Caddy. Never prints secrets.
set -euo pipefail

ROOT="${MANIFORGE_ROOT:-/opt/maniforge/platform-core}"
DEPLOY="${ROOT}/deploy"
ENV_FILE="${DEPLOY}/.env.platform"
COMPOSE_FILE="${DEPLOY}/compose.platform.server.yml"
UNITS=(
  maniforge-rbac.service
  maniforge-tl.service
  maniforge-manifest.service
  maniforge-versioning.service
  maniforge-realtime.service
  maniforge-caddy.service
)
OLD_CONTAINERS=(
  maniforge-platform-rbac
  maniforge-platform-tl
  maniforge-platform-manifest
  maniforge-platform-versioning
  maniforge-platform-realtime
  maniforge-platform-gateway
  maniforge-platform-migrate
)

cd "$DEPLOY"

if [ ! -f "$ENV_FILE" ]; then
  echo "missing $ENV_FILE" >&2
  exit 1
fi

echo "==> stop orphan Go/Caddy containers (keep postgres volumes)"
for c in "${OLD_CONTAINERS[@]}"; do
  docker rm -f "$c" >/dev/null 2>&1 || true
done

echo "==> postgres compose up"
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --remove-orphans --no-build

echo "==> wait for primary"
ok=0
for _ in $(seq 1 40); do
  if docker exec maniforge-pg-primary pg_isready -U maniforge -d maniforge >/dev/null 2>&1; then
    ok=1
    break
  fi
  sleep 1
done
if [ "$ok" -ne 1 ]; then
  echo "postgres primary not ready" >&2
  exit 1
fi

echo "==> install systemd units"
install -m 0644 "${DEPLOY}/systemd/"*.service /etc/systemd/system/
if [ -x /usr/bin/caddy ] && [ ! -e /usr/local/bin/caddy ]; then
  ln -sf /usr/bin/caddy /usr/local/bin/caddy
fi
if [ ! -x /usr/local/bin/caddy ]; then
  echo "caddy not found at /usr/local/bin/caddy" >&2
  exit 1
fi
systemctl daemon-reload
systemctl enable "${UNITS[@]}" >/dev/null

echo "==> migrate"
# EnvironmentFile (not bash source) — values with spaces stay intact; secrets stay in the file.
systemd-run --quiet --wait --pipe --collect \
  --property="EnvironmentFile=${ENV_FILE}" \
  --working-directory="$ROOT" \
  "${ROOT}/bin/maniforge-migrate"

echo "==> restart Go + Caddy"
systemctl restart "${UNITS[@]}"

echo "==> health (gateway — buyer-facing path)"
sleep 1
ENV="$ENV_FILE"
# shellcheck source=lib/gateway-health.sh
. "${DEPLOY}/scripts/lib/gateway-health.sh"
gateway_health_check

echo "==> replication"
docker exec maniforge-pg-primary psql -U maniforge -d maniforge -tAc \
  "SELECT client_addr, state, sync_state FROM pg_stat_replication;"
