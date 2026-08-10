#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token
echo "== org_unit =="
curl -sS "$MANIFEST_URL/api/data/org_unit" -H "Authorization: Bearer $TOKEN" \
  | jq '{total:(.meta.total // (.records|length)), units:[.records[]?.data | {code,name,unit_type,parent_code}]}'
echo "== org_employee =="
curl -sS "$MANIFEST_URL/api/data/org_employee" -H "Authorization: Bearer $TOKEN" \
  | jq '{total:(.meta.total // (.records|length)), people:[.records[]?.data | {full_name,unit_code,position,status}]}'
