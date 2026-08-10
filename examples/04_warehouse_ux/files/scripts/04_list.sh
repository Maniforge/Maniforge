#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token
for e in wh_location wh_sku wh_balance wh_receipt wh_putaway wh_transfer wh_shipment wh_stocktake wh_lot wh_reserve; do
  echo "== $e =="
  curl -sS "$MANIFEST_URL/api/data/$e" -H "Authorization: Bearer $TOKEN" \
    | jq '{total:(.meta.total // (.records|length))}'
done
