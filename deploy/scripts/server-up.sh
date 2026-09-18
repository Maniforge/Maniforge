#!/bin/bash
# Bring up Postgres (Docker), migrate, restart host Go + Caddy. Never prints secrets.
set -euo pipefail

ROOT="${MANIFORGE_ROOT:-/opt/maniforge/platform-core}"
DEPLOY="${ROOT}/deploy"
ENV_FILE="${DEPLOY}/.env.platform"
COMPOSE_FILE="${DEPLOY}/compose.platform.server.yml"
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

MODULES_BIN="${ROOT}/bin/maniforge-modules"
if [ ! -x "$MODULES_BIN" ]; then
  echo "missing $MODULES_BIN (run make build)" >&2
  exit 1
fi
eval "$("$MODULES_BIN" resolve --root "$ROOT" --env "$ENV_FILE")"
# shellcheck disable=SC2206
UNITS=(${MANIFORGE_SYSTEMD_ENABLE})
# shellcheck disable=SC2206
DISABLE_UNITS=(${MANIFORGE_SYSTEMD_DISABLE})

env_get() {
  grep -E "^$1=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- || true
}

# Host Caddy is :18090. MANIFORGE_GATEWAY_PORT=443 is the public origin behind
# an edge proxy — not this unit. Direct TLS: MANIFORGE_CADDY_TLS=1 + PUBLIC_HOST.
CADDY_LISTEN=":18090"
caddy_tls="$(env_get MANIFORGE_CADDY_TLS)"
caddy_listen_over="$(env_get MANIFORGE_CADDY_LISTEN)"
if [ -n "$caddy_listen_over" ]; then
  CADDY_LISTEN="$caddy_listen_over"
elif [ "${caddy_tls}" = "1" ]; then
  pub_host="$(env_get MANIFORGE_PUBLIC_HOST)"
  if [ -z "$pub_host" ]; then
    echo "MANIFORGE_CADDY_TLS=1 requires MANIFORGE_PUBLIC_HOST" >&2
    exit 1
  fi
  CADDY_LISTEN="$pub_host"
fi
# Always generate the gitignored active file. Never overwrite Caddyfile.server.
caddy_out="${DEPLOY}/Caddyfile.active"
"$MODULES_BIN" caddy --root "$ROOT" --env "$ENV_FILE" --mode host --listen "$CADDY_LISTEN" -o "$caddy_out"
ENV="$ENV_FILE"
# shellcheck source=server-public-urls.sh
. "${DEPLOY}/scripts/server-public-urls.sh"
_env_upsert MANIFORGE_CADDYFILE "$caddy_out"
# Apply/verify hit this process, not the host edge on :443.
health_url="$(env_get MANIFORGE_GATEWAY_HEALTH_URL)"
if [ "$CADDY_LISTEN" = ":18090" ] && [[ "$health_url" != *":18090"* ]]; then
  _env_upsert MANIFORGE_GATEWAY_HEALTH_URL "http://127.0.0.1:18090"
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
if [ "${#DISABLE_UNITS[@]}" -gt 0 ]; then
  systemctl disable --now "${DISABLE_UNITS[@]}" >/dev/null 2>&1 || true
fi

echo "==> migrate"
# EnvironmentFile (not bash source) — values with spaces stay intact; secrets stay in the file.
systemd-run --quiet --wait --pipe --collect \
  --property="EnvironmentFile=${ENV_FILE}" \
  --working-directory="$ROOT" \
  "${ROOT}/bin/maniforge-migrate"

if grep -qE '^MANIFORGE_ADMIN_LOGIN=.+' "$ENV_FILE" && grep -qE '^MANIFORGE_ADMIN_PASSWORD=.+' "$ENV_FILE"; then
  echo "==> demo admin bootstrap (tenantId = SHA-256 of UUID; password not printed)"
  systemd-run --quiet --wait --pipe --collect \
    --property="EnvironmentFile=${ENV_FILE}" \
    --property="Environment=MANIFORGE_ROOT=${ROOT}" \
    --working-directory="$ROOT" \
    "${ROOT}/bin/maniforge-bootstrap"
else
  echo "==> skip demo bootstrap (set MANIFORGE_ADMIN_LOGIN and MANIFORGE_ADMIN_PASSWORD)"
fi

echo "==> restart Go + Caddy"
systemctl reset-failed maniforge-caddy.service >/dev/null 2>&1 || true
systemctl restart "${UNITS[@]}"

echo "==> health (gateway — buyer-facing path)"
ENV="$ENV_FILE"
MANIFORGE_ROOT="$ROOT"
# shellcheck source=lib/gateway-health.sh
. "${DEPLOY}/scripts/lib/gateway-health.sh"
ready=0
for _ in $(seq 1 25); do
  if curl -sf -m 3 "http://127.0.0.1:18090/rbac/health" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
if [ "$ready" -ne 1 ]; then
  echo "caddy not accepting :18090 (see journalctl -u maniforge-caddy)" >&2
  journalctl -u maniforge-caddy -n 20 --no-pager >&2 || true
  exit 1
fi
gateway_health_check

echo "==> replication"
docker exec maniforge-pg-primary psql -U maniforge -d maniforge -tAc \
  "SELECT client_addr, state, sync_state FROM pg_stat_replication;"
