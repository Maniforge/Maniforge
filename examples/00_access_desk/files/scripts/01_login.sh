#!/usr/bin/env bash
# Login to Maniforge RBAC (phone-first); save access_token to .session.json
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
need_jq

if [[ -z "${PHONE:-}" ]]; then
  echo "PHONE is required (Maniforge login is phone-first)." >&2
  echo "Use agency seed (+79000000003) or scripts/00b_register_operator.sh" >&2
  exit 1
fi

BODY=$(jq -n \
  --arg phone "$PHONE" \
  --arg password "$PASSWORD" \
  --arg tenant "$TENANT_ID" \
  --arg sub "$SUBTENANT_ID" \
  '{phone:$phone,password:$password,tenant_id:$tenant,subtenant_id:$sub}')

echo "POST $RBAC_URL/api/v1/auth/login (phone=$PHONE tenant=$TENANT_ID)"
RESP="$(curl -sS -X POST "$RBAC_URL/api/v1/auth/login" \
  -H 'Content-Type: application/json' \
  -d "$BODY")"

TOKEN="$(echo "$RESP" | jq -r '.session.access_token // .access_token // empty')"
if [[ -z "$TOKEN" ]]; then
  echo "$RESP" | jq . 2>/dev/null || echo "$RESP"
  echo "FAIL: no access_token" >&2
  exit 1
fi

echo "$RESP" | jq '{
  access_token: (.session.access_token // .access_token),
  refresh_token: (.session.refresh_token // .refresh_token // null),
  tenant_id: (.session.tenant_id // .tenant_id // null),
  project_id: (.session.project_id // .project_id // null),
  user: (.user // .session.user // null)
}' > "$SESSION_FILE"

echo "OK → $SESSION_FILE"
jq '{tenant_id, project_id, user}' "$SESSION_FILE"
