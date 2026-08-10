#!/usr/bin/env bash
# Issue one access_pass record.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token

FROM="${VALID_FROM:-$(date -u +%Y-%m-%d)}"
UNTIL="${VALID_UNTIL:-$(date -u -d '+7 days' +%Y-%m-%d 2>/dev/null || date -u -v+7d +%Y-%m-%d)}"
GUEST="${GUEST_NAME:-ООО Подряд Строй}"
ZONE="${ZONE:-warehouse}"

BODY=$(jq -n \
  --arg guest "$GUEST" \
  --arg phone "${GUEST_PHONE:-+79001234567}" \
  --arg zone "$ZONE" \
  --arg from "$FROM" \
  --arg until "$UNTIL" \
  --arg by "$LOGIN" \
  --arg note "${NOTE:-Разгрузка ворот B}" \
  '{
    guest_name:$guest,
    guest_phone:$phone,
    zone:$zone,
    valid_from:$from,
    valid_until:$until,
    status:"active",
    issued_by:$by,
    note:$note
  }')

echo "POST $MANIFEST_URL/api/data/access_pass"
RESP="$(curl -sS -X POST "$MANIFEST_URL/api/data/access_pass" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d "$BODY")"

echo "$RESP" | jq . 2>/dev/null || echo "$RESP"

RID="$(echo "$RESP" | jq -r '.record.id // .id // .record_id // .data.id // empty' 2>/dev/null || true)"
if [[ -n "$RID" ]]; then
  echo "$RID" > "$ROOT/.last_record_id"
  echo "RECORD_ID=$RID (saved to .last_record_id)"
fi
