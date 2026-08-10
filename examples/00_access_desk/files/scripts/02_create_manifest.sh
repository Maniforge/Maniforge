#!/usr/bin/env bash
# Create custom manifest access_pass (idempotent-ish: prints API response).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token

MANIFEST_JSON="$ROOT/manifest.access_pass.json"
echo "POST $MANIFEST_URL/api/v1/manifests"
RESP="$(curl -sS -X POST "$MANIFEST_URL/api/v1/manifests" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d @"$MANIFEST_JSON")"

echo "$RESP" | jq . 2>/dev/null || echo "$RESP"

# If already exists, GET confirms
CODE="$(echo "$RESP" | jq -r '.code // .manifest.code // empty' 2>/dev/null || true)"
if [[ -z "$CODE" ]]; then
  echo "GET existing manifest…"
  curl -sS "$MANIFEST_URL/api/v1/manifests/access_pass" \
    -H "Authorization: Bearer $TOKEN" | jq .
fi
