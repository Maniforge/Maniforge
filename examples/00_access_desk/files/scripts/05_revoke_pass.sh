#!/usr/bin/env bash
# Revoke access_pass by RECORD_ID (env or .last_record_id).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token

RECORD_ID="${RECORD_ID:-}"
if [[ -z "$RECORD_ID" && -f "$ROOT/.last_record_id" ]]; then
  RECORD_ID="$(tr -d '[:space:]' < "$ROOT/.last_record_id")"
fi
if [[ -z "$RECORD_ID" ]]; then
  echo "Set RECORD_ID=... or run 03_issue_pass.sh first" >&2
  exit 1
fi

echo "PATCH status→revoked id=$RECORD_ID"
# Prefer field-level; fallback to full PATCH body variants.
RESP="$(curl -sS -X PATCH "$MANIFEST_URL/api/data/access_pass/$RECORD_ID/fields/status" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"value":"revoked"}')"

if echo "$RESP" | jq -e '(.ok == false) or (.error != null)' >/dev/null 2>&1; then
  RESP="$(curl -sS -X PATCH "$MANIFEST_URL/api/data/access_pass/$RECORD_ID" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d '{"status":"revoked"}')"
fi

echo "$RESP" | jq . 2>/dev/null || echo "$RESP"
