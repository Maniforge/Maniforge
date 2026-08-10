#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
echo "RBAC=$RBAC_URL Manifest=$MANIFEST_URL"
c1=$(curl -s -o /dev/null -w '%{http_code}' "$RBAC_URL/health" || true)
c2=$(curl -s -o /dev/null -w '%{http_code}' "$MANIFEST_URL/health" || true)
echo "health rbac=$c1 manifest=$c2"
[[ "$c1" == "200" && "$c2" == "200" ]] || { echo "Start make run-rbac && make run-manifest" >&2; exit 1; }
echo OK
