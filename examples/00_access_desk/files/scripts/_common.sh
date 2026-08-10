#!/usr/bin/env bash
# Shared env + helpers for Access Desk scripts.
set -euo pipefail

FILES_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SESSION_FILE="${SESSION_FILE:-$FILES_ROOT/.session.json}"

if [[ -f "$FILES_ROOT/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$FILES_ROOT/.env"
  set +a
elif [[ -f "$FILES_ROOT/env.example" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$FILES_ROOT/env.example"
  set +a
fi

: "${RBAC_URL:=http://127.0.0.1:8093/rbac}"
: "${MANIFEST_URL:=http://127.0.0.1:8095}"
: "${TENANT_ID:=agency-demo}"
: "${SUBTENANT_ID:=main}"
: "${PHONE:=+79000000003}"
: "${PASSWORD:=DemoAdmin!12345}"
: "${LOGIN:=agency-admin}"

need_jq() {
  command -v jq >/dev/null 2>&1 || {
    echo "jq is required" >&2
    exit 1
  }
}

require_token() {
  need_jq
  if [[ ! -f "$SESSION_FILE" ]]; then
    echo "No session. Run scripts/01_login.sh first." >&2
    exit 1
  fi
  TOKEN="$(jq -r '.access_token // empty' "$SESSION_FILE")"
  if [[ -z "$TOKEN" || "$TOKEN" == "null" ]]; then
    echo "Empty access_token in $SESSION_FILE" >&2
    exit 1
  fi
  export TOKEN
}
