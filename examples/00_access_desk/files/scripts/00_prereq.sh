#!/usr/bin/env bash
# Check Maniforge RBAC + Manifest Engine are up.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"

echo "== Access Desk prereq =="
echo "RBAC_URL=$RBAC_URL"
echo "MANIFEST_URL=$MANIFEST_URL"

code_rbac="$(curl -s -o /tmp/mf_rbac_health.json -w '%{http_code}' "$RBAC_URL/health" || true)"
code_me="$(curl -s -o /tmp/mf_me_health.json -w '%{http_code}' "$MANIFEST_URL/health" || true)"

echo "RBAC health HTTP $code_rbac: $(cat /tmp/mf_rbac_health.json 2>/dev/null || true)"
echo "Manifest health HTTP $code_me: $(cat /tmp/mf_me_health.json 2>/dev/null || true)"

if [[ "$code_rbac" != "200" || "$code_me" != "200" ]]; then
  echo "FAIL: start platform from repo root: make pg-up && make run-rbac && make run-manifest" >&2
  exit 1
fi
echo "OK"
