#!/bin/bash
# One apply command: desired-state from MANIFORGE_MODULES → compose | systemd | native.
# Never prints secrets.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
DEPLOY="${ROOT}/deploy"
ENV_FILE="${DEPLOY}/.env.platform"
COMPOSE_FILE="${DEPLOY}/compose.platform.yml"

if [ ! -f "$ENV_FILE" ]; then
  cp "${DEPLOY}/.env.platform.example" "$ENV_FILE"
  echo "created $ENV_FILE"
fi

modules_bin() {
  if [ -x "${ROOT}/bin/maniforge-modules" ]; then
    echo "${ROOT}/bin/maniforge-modules"
    return
  fi
  if [ -x "${ROOT}/bin/maniforge-modules.exe" ]; then
    echo "${ROOT}/bin/maniforge-modules.exe"
    return
  fi
  echo ""
}

BIN="$(modules_bin)"
if [ -z "$BIN" ]; then
  echo "build bin/maniforge-modules first (make up / make build)" >&2
  exit 1
fi

eval "$("$BIN" resolve --root "$ROOT" --env "$ENV_FILE")"

docker_ok() {
  docker info >/dev/null 2>&1
}

if docker_ok; then
  echo "==> docker compose (profiles: ${MANIFORGE_COMPOSE_PROFILES:-none})"
  "$BIN" caddy --root "$ROOT" --env "$ENV_FILE" --mode compose --listen :8080 -o "${DEPLOY}/Caddyfile.active"
  PROFILE_ARGS=()
  if [ -n "${MANIFORGE_COMPOSE_PROFILES:-}" ]; then
    IFS=',' read -ra _ps <<< "$MANIFORGE_COMPOSE_PROFILES"
    for p in "${_ps[@]}"; do
      p="$(echo "$p" | tr -d '[:space:]')"
      [ -n "$p" ] && PROFILE_ARGS+=(--profile "$p")
    done
  fi
  if [ "${#PROFILE_ARGS[@]}" -gt 0 ]; then
    docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "${PROFILE_ARGS[@]}" up -d --build --remove-orphans
  else
    docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --build --remove-orphans
  fi
  echo "==> health"
  ENV="$ENV_FILE" MANIFORGE_ROOT="$ROOT"
  # shellcheck source=lib/gateway-health.sh
  . "${SCRIPT_DIR}/lib/gateway-health.sh"
  gateway_health_check
  exit 0
fi

if command -v systemctl >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ] && [ -d /etc/systemd/system ]; then
  echo "==> systemd (production)"
  exec bash "${SCRIPT_DIR}/server-up.sh"
fi

echo "==> native host (no docker daemon)"
"$BIN" up-native --root "$ROOT" --env "$ENV_FILE" --listen :18090
echo "native up: packages ${MANIFORGE_RESOLVED_PACKAGES}"
ENV="$ENV_FILE" MANIFORGE_ROOT="$ROOT"
# shellcheck source=lib/gateway-health.sh
. "${SCRIPT_DIR}/lib/gateway-health.sh"
sleep 1
gateway_health_check || true
