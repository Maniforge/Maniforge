#!/usr/bin/env bash
set -euo pipefail
FILES_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SESSION_FILE="${SESSION_FILE:-$FILES_ROOT/.session.json}"

if [[ -f "$FILES_ROOT/.env" ]]; then
  set -a; # shellcheck disable=SC1091
  source "$FILES_ROOT/.env"; set +a
elif [[ -f "$FILES_ROOT/env.example" ]]; then
  set -a; # shellcheck disable=SC1091
  source "$FILES_ROOT/env.example"; set +a
fi

: "${RBAC_URL:=http://127.0.0.1:8093/rbac}"
: "${MANIFEST_URL:=http://127.0.0.1:8095}"
: "${TENANT_ID:=agency-demo}"
: "${SUBTENANT_ID:=main}"
: "${PHONE:=+79000000003}"
: "${PASSWORD:=DemoAdmin!12345}"
: "${LOGIN:=agency-admin}"

need_jq() { command -v jq >/dev/null 2>&1 || { echo "jq required" >&2; exit 1; }; }

require_token() {
  need_jq
  [[ -f "$SESSION_FILE" ]] || { echo "Run 01_login.sh first" >&2; exit 1; }
  TOKEN="$(jq -r '.access_token // empty' "$SESSION_FILE")"
  [[ -n "$TOKEN" && "$TOKEN" != "null" ]] || { echo "Empty token" >&2; exit 1; }
  export TOKEN
}

ensure_manifest() {
  local file="$1"
  local code
  code="$(jq -r '.code' "$file")"
  local resp code_http
  code_http="$(curl -sS -o /tmp/mf_ens.json -w '%{http_code}' -X POST "$MANIFEST_URL/api/v1/manifests" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d @"$file")"
  if [[ "$code_http" == "201" || "$code_http" == "200" ]]; then
    echo "manifest $code: created ($code_http)"
  elif [[ "$code_http" == "409" ]]; then
    echo "manifest $code: already exists"
  else
    echo "manifest $code: HTTP $code_http $(cat /tmp/mf_ens.json)" >&2
    exit 1
  fi
}
