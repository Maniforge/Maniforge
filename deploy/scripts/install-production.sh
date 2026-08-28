#!/bin/bash
# Idempotent production install for Ubuntu 22.04 / 24.04.
# Fast path: Postgres in Docker, Go native systemd, Caddy gateway (HTTPS domain or IP:18090).
# Never prints generated secrets.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

ROOT="${MANIFORGE_ROOT:-/opt/maniforge/platform-core}"
DEPLOY="${ROOT}/deploy"
GO_VERSION="${MANIFORGE_GO_VERSION:-1.25.0}"
DOMAIN="${MANIFORGE_DOMAIN:-}"
NONINTERACTIVE="${MANIFORGE_NONINTERACTIVE:-0}"
SKIP_APT="${MANIFORGE_SKIP_APT:-0}"

usage() {
  cat <<'EOF'
Usage: install-production.sh [options]

Options:
  --root PATH          Install tree (default: /opt/maniforge/platform-core)
  --domain FQDN        Enable HTTPS via Caddyfile.production (requires DNS + :80/:443)
  --non-interactive    No prompts; fail if .env.platform missing
  --skip-apt           Skip apt/docker/go/caddy install (deps already present)
  -h, --help           Show help

Environment:
  MANIFORGE_ROOT, MANIFORGE_DOMAIN, MANIFORGE_NONINTERACTIVE, MANIFORGE_SKIP_APT

Example (clean Ubuntu, source already at /opt/maniforge/platform-core):
  sudo bash deploy/scripts/install-production.sh --domain platform.customer.ru

Example (staging by IP, no TLS):
  sudo bash deploy/scripts/install-production.sh
EOF
}

log() { echo "==> $*"; }

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo "run as root (sudo)" >&2
    exit 1
  fi
}

require_ubuntu() {
  if [ ! -f /etc/os-release ]; then
    echo "unsupported OS (need Ubuntu 22.04/24.04)" >&2
    exit 1
  fi
  # shellcheck disable=SC1091
  . /etc/os-release
  case "${VERSION_ID:-}" in
    22.04|24.04) ;;
    *)
      echo "warning: untested Ubuntu ${VERSION_ID:-unknown}; continuing" >&2
      ;;
  esac
}

parse_args() {
  while [ $# -gt 0 ]; do
    case "$1" in
      --root)
        ROOT="$2"
        DEPLOY="${ROOT}/deploy"
        shift 2
        ;;
      --domain)
        DOMAIN="$2"
        shift 2
        ;;
      --non-interactive)
        NONINTERACTIVE=1
        shift
        ;;
      --skip-apt)
        SKIP_APT=1
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        echo "unknown arg: $1" >&2
        usage >&2
        exit 1
        ;;
    esac
  done
}

install_apt_base() {
  log "apt base packages"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl gnupg lsb-release openssl rsync git
}

install_docker() {
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    log "docker already installed"
    return 0
  fi
  log "docker"
  install -m 0755 -d /etc/apt/keyrings
  if [ ! -f /etc/apt/keyrings/docker.gpg ]; then
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
  fi
  # shellcheck disable=SC1091
  . /etc/os-release
  echo \
    "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
    ${VERSION_CODENAME} stable" >/etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  systemctl enable --now docker
}

install_go() {
  if [ -x /usr/local/go/bin/go ]; then
    local ver
    ver="$(/usr/local/go/bin/go version 2>/dev/null || true)"
    log "go present: ${ver:-unknown}"
    return 0
  fi
  log "go ${GO_VERSION}"
  local arch tarball
  case "$(uname -m)" in
    x86_64) arch=amd64 ;;
    aarch64) arch=arm64 ;;
    *)
      echo "unsupported arch for go install" >&2
      exit 1
      ;;
  esac
  tarball="go${GO_VERSION}.linux-${arch}.tar.gz"
  curl -fsSL "https://go.dev/dl/${tarball}" -o "/tmp/${tarball}"
  rm -rf /usr/local/go
  tar -C /usr/local -xzf "/tmp/${tarball}"
  rm -f "/tmp/${tarball}"
  if ! grep -q '/usr/local/go/bin' /etc/profile.d/maniforge-go.sh 2>/dev/null; then
    echo 'export PATH="/usr/local/go/bin:$PATH"' >/etc/profile.d/maniforge-go.sh
  fi
}

install_caddy() {
  if command -v caddy >/dev/null 2>&1; then
    ln -sf "$(command -v caddy)" /usr/local/bin/caddy 2>/dev/null || true
    log "caddy already installed"
    return 0
  fi
  log "caddy"
  apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' >/etc/apt/sources.list.d/caddy-stable.list
  apt-get update -qq
  apt-get install -y -qq caddy
  ln -sf /usr/bin/caddy /usr/local/bin/caddy
}

ensure_source() {
  if [ ! -f "${ROOT}/Makefile" ] || [ ! -f "${DEPLOY}/compose.platform.server.yml" ]; then
    cat >&2 <<EOF
source tree missing at ${ROOT}
  git clone <repo> ${ROOT}
  # or rsync from build machine:
  rsync -a --exclude .git ./ ${ROOT}/
EOF
    exit 1
  fi
}

prompt_domain() {
  if [ -n "$DOMAIN" ] || [ "$NONINTERACTIVE" = "1" ]; then
    return 0
  fi
  read -r -p "Production domain for HTTPS (empty = IP:18090 staging): " DOMAIN || true
  DOMAIN="${DOMAIN:-}"
}

configure_env() {
  cd "$DEPLOY"
  local env_file=".env.platform"

  if [ ! -f "$env_file" ]; then
    if [ "$NONINTERACTIVE" = "1" ]; then
      echo "missing ${DEPLOY}/${env_file}; run server-init-env.sh or copy example first" >&2
      exit 1
    fi
    log "create ${env_file} from example + random secrets"
    cp .env.platform.server.example "$env_file"
    bash scripts/server-init-env.sh
  fi

  # shellcheck source=server-public-urls.sh
  . "${SCRIPT_DIR}/server-public-urls.sh"
  ENV="$env_file"

  if [ -n "$DOMAIN" ]; then
    log "production profile (domain ${DOMAIN})"
    _env_upsert MANIFORGE_PUBLIC_HOST "$DOMAIN"
    _env_upsert APP_URL "https://${DOMAIN}"
    _env_upsert MANIFORGE_GATEWAY_PORT "443"
    _env_upsert APP_ENV "production"
    _env_upsert APP_DEBUG "false"
    _env_upsert TENANCY_MODE "multi"
    _env_upsert TENANCY_HEADERS_REQUIRED "true"
    _env_upsert TENANT_LICENSING_ENFORCEMENT "strict"
    _env_upsert RBAC_REGISTRATION_ENABLED "false"
    _env_upsert RBAC_PII_ENCRYPTION_ENABLED "true"
    _env_upsert MANIFORGE_CADDYFILE "${DEPLOY}/Caddyfile.active"
  else
    log "staging profile (IP:18090, no TLS)"
    _env_upsert MANIFORGE_GATEWAY_PORT "18090"
    _env_upsert MANIFORGE_CADDYFILE "${DEPLOY}/Caddyfile.server"
  fi

  # Merge secrets from production example placeholders if still CHANGE_ME*
  apply_production_secrets

  drop_direct_public_url_wall
  generate_public_urls
}

apply_production_secrets() {
  local env_file=".env.platform"
  ENV="$env_file"
  # shellcheck source=server-public-urls.sh
  . "${SCRIPT_DIR}/server-public-urls.sh"

  gen_hex() { openssl rand -hex "$1"; }
  gen_b64_32() { openssl rand -base64 32; }

  upsert_if_placeholder() {
    local key="$1" placeholder="$2" val="$3"
    local cur
    cur="$(_env_get "$key")"
    if [ -z "$cur" ] || [ "$cur" = "$placeholder" ] || [[ "$cur" == CHANGE_ME* ]]; then
      _env_upsert "$key" "$val"
    fi
  }

  upsert_if_placeholder MANIFORGE_DB_PASS CHANGE_ME_MANIFORGE_DB_PASS "$(gen_hex 16)"
  upsert_if_placeholder REPLICATOR_PASSWORD CHANGE_ME_REPLICATOR_PASSWORD "$(gen_hex 16)"

  local tok
  tok="$(gen_hex 24)"
  upsert_if_placeholder TENANT_LICENSING_INTERNAL_TOKEN CHANGE_ME_INTERNAL_TOKEN "$tok"
  upsert_if_placeholder TENANT_LICENSING_ADMIN_TOKEN CHANGE_ME_ADMIN_TOKEN "$(gen_hex 24)"
  upsert_if_placeholder RBAC_INTERNAL_TOKEN CHANGE_ME_INTERNAL_TOKEN "$(gen_hex 24)"
  upsert_if_placeholder RBAC_PII_ENCRYPTION_KEY CHANGE_ME_BASE64_32_BYTES "$(gen_b64_32)"
}

render_caddy() {
  cd "$DEPLOY"
  if [ -n "$DOMAIN" ]; then
    log "render Caddyfile.active for ${DOMAIN}"
    sed "s/{domain}/${DOMAIN}/g" Caddyfile.production > Caddyfile.active
  else
    log "use Caddyfile.server (:18090)"
  fi
}

patch_caddy_systemd() {
  local unit="/etc/systemd/system/maniforge-caddy.service"
  local src="${DEPLOY}/systemd/maniforge-caddy.service"
  if [ ! -f "$src" ]; then
    return 0
  fi
  install -m 0644 "$src" "$unit"
  if ! grep -q "EnvironmentFile=${DEPLOY}/.env.platform" "$unit"; then
    sed -i "s|^EnvironmentFile=.*|EnvironmentFile=${DEPLOY}/.env.platform|" "$unit" 2>/dev/null || \
      sed -i "/^\[Service\]/a EnvironmentFile=${DEPLOY}/.env.platform" "$unit"
  fi
  sed -i "s|ExecStart=.*|ExecStart=/bin/bash -c '/usr/local/bin/caddy run --config \"\${MANIFORGE_CADDYFILE:-${DEPLOY}/Caddyfile.server}\" --adapter caddyfile'|" "$unit"
}


fix_deploy_script_perms() {
  find "${DEPLOY}/postgres" -type f -name "*.sh" -exec sed -i "s/\r$//" {} + -exec chmod +x {} + 2>/dev/null || true
  find "${DEPLOY}/scripts" -type f -name "*.sh" -exec sed -i "s/\r$//" {} + 2>/dev/null || true
}

main() {
  parse_args "$@"
  require_root
  require_ubuntu

  if [ "$SKIP_APT" != "1" ]; then
    install_apt_base
    install_docker
    install_go
    install_caddy
  fi

  ensure_source
  fix_deploy_script_perms
  prompt_domain
  configure_env
  render_caddy
  patch_caddy_systemd

  log "build Go binaries"
  MANIFORGE_ROOT="$ROOT" bash "${DEPLOY}/scripts/server-build.sh"

  log "postgres + migrate + systemd + health"
  MANIFORGE_ROOT="$ROOT" bash "${DEPLOY}/scripts/server-up.sh"

  log "verify"
  MANIFORGE_ROOT="$ROOT" bash "${DEPLOY}/scripts/verify-production.sh"

  if [ -x "${ROOT}/bin/maniforge-tl-expire-licenses" ]; then
    log "scheduler timers (optional — requires built scheduler binaries)"
    MANIFORGE_ROOT="$ROOT" bash "${DEPLOY}/scripts/install-scheduler.sh" || \
      echo "warning: install-scheduler skipped or failed (non-fatal on first install)" >&2
  fi

  cat <<EOF

install-production: complete
  tree:   ${ROOT}
  verify: bash ${DEPLOY}/scripts/verify-production.sh

Next (recommended before buyer demo):
  cd ${ROOT} && make preflight
  cd ${ROOT} && make server-journey GATEWAY=http://127.0.0.1:18090
  docs/PRODUCTION_BOX.md — Phase C checklist, backup/upgrade runbook

TLS / production domain (COO action required):
  1. DNS A-record: YOUR_DOMAIN → this server
  2. sudo bash ${DEPLOY}/scripts/install-production.sh --domain YOUR_DOMAIN --skip-apt --non-interactive
  3. curl -sf https://YOUR_DOMAIN/rbac/health
EOF
}

main "$@"
