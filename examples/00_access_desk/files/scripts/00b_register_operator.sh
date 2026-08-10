#!/usr/bin/env bash
# Register a fresh operator org (phone-first). Prints tenant + credentials hints.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
need_jq

PHONE_REG="${PHONE_REG:-+79$(date +%m%d%H%M%S | tail -c 9)}"
PASSWORD_REG="${PASSWORD_REG:-AccessDesk!12345}"
ORG="${ORG_NAME:-Access Desk Demo}"

BODY=$(jq -n \
  --arg phone "$PHONE_REG" \
  --arg password "$PASSWORD_REG" \
  --arg org "$ORG" \
  '{phone:$phone,password:$password,organization_name:$org,consents:[{purpose_code:"account",policy_version:"1.0"}]}')

echo "POST $RBAC_URL/api/v1/auth/register"
RESP="$(curl -sS -X POST "$RBAC_URL/api/v1/auth/register" \
  -H 'Content-Type: application/json' \
  -d "$BODY")"
echo "$RESP" | jq .

TENANT="$(echo "$RESP" | jq -r '.tenant.tenant_id // empty')"
SUB="$(echo "$RESP" | jq -r '.tenant.subtenant_id // "main"')"
if [[ -z "$TENANT" ]]; then
  echo "FAIL: registration did not return tenant" >&2
  exit 1
fi

cat > "$ROOT/.env" <<EOF
RBAC_URL=$RBAC_URL
MANIFEST_URL=$MANIFEST_URL
TENANT_ID=$TENANT
SUBTENANT_ID=$SUB
PHONE=$PHONE_REG
PASSWORD=$PASSWORD_REG
LOGIN=access-desk-operator
EOF

echo "Wrote $ROOT/.env — next: bash scripts/01_login.sh"
