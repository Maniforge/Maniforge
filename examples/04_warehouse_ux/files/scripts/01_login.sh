#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; source "$ROOT/scripts/_common.sh"; need_jq
BODY=$(jq -n --arg phone "$PHONE" --arg password "$PASSWORD" --arg tenant "$TENANT_ID" --arg sub "$SUBTENANT_ID" \
  '{phone:$phone,password:$password,tenant_id:$tenant,subtenant_id:$sub}')
RESP=$(curl -sS -X POST "$RBAC_URL/api/v1/auth/login" -H 'Content-Type: application/json' -d "$BODY")
TOKEN=$(echo "$RESP" | jq -r '.session.access_token // .credentials.session.access_token // empty')
[[ -n "$TOKEN" ]] || { echo "$RESP" | jq .; exit 1; }
echo "$RESP" | jq '{access_token:(.session.access_token // .credentials.session.access_token),tenant_id:(.session.tenant_id // null),project_id:(.session.project_id // null),user}' > "$SESSION_FILE"
echo "OK → $SESSION_FILE"
