#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; source "$ROOT/scripts/_common.sh"
c1=$(curl -s -o /dev/null -w '%{http_code}' "$RBAC_URL/health" || true)
c2=$(curl -s -o /dev/null -w '%{http_code}' "$MANIFEST_URL/health" || true)
echo "health rbac=$c1 manifest=$c2"
[[ "$c1" == "200" && "$c2" == "200" ]] || exit 1
echo OK
