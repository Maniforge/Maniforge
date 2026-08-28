#!/bin/bash
# Merge server example into live .env.platform: keep secrets, refresh comments/ports/URLs.
set -euo pipefail
cd "$(dirname "$0")/.."
ENV=.env.platform
EXAMPLE=.env.platform.server.example

if [ ! -f "$EXAMPLE" ]; then
  echo "missing $EXAMPLE" >&2
  exit 1
fi

if [ ! -f "$ENV" ]; then
  cp "$EXAMPLE" "$ENV"
  echo "created $ENV from example — run server-init-env.sh first"
  exit 1
fi

# shellcheck source=server-public-urls.sh
. "$(dirname "$0")/server-public-urls.sh"

LIVE_BAK="$(mktemp)"
cp "$ENV" "$LIVE_BAK"

get_val() { grep -E "^$1=" "$LIVE_BAK" | head -1 | cut -d= -f2-; }

DB_PASS=$(get_val MANIFORGE_DB_PASS)
REP_PASS=$(get_val REPLICATOR_PASSWORD)
INT_TOK=$(get_val TENANT_LICENSING_INTERNAL_TOKEN)
ADM_TOK=$(get_val TENANT_LICENSING_ADMIN_TOKEN)
RBAC_TOK=$(get_val RBAC_INTERNAL_TOKEN)

cp "$EXAMPLE" "$ENV"

# Restore known secrets into CHANGE_ME placeholders (values may contain / — use awk).
restore_placeholder() {
  local placeholder="$1" val="$2"
  [ -n "$val" ] || return 0
  awk -v p="$placeholder" -v v="$val" '{ gsub(p, v); print }' "$ENV" > "$ENV.tmp" && mv "$ENV.tmp" "$ENV"
}

restore_placeholder "CHANGE_ME_MANIFORGE_DB_PASS" "$DB_PASS"
restore_placeholder "CHANGE_ME_REPLICATOR_PASSWORD" "$REP_PASS"
restore_placeholder "CHANGE_ME_INTERNAL_TOKEN" "$INT_TOK"
restore_placeholder "CHANGE_ME_ADMIN_TOKEN" "$ADM_TOK"
if [ -n "${RBAC_TOK:-}" ] && [ "$RBAC_TOK" != "CHANGE_ME_INTERNAL_TOKEN" ]; then
  _env_upsert RBAC_INTERNAL_TOKEN "$RBAC_TOK"
fi

# Keep extra live keys (PII, SIEM, …) that the example does not define.
SKIP_REATTACH='^(MANIFORGE_RBAC_URL|MANIFORGE_TL_URL|MANIFORGE_VERSIONING_URL|NEW_USER_|MANIFEST_JOURNEY_|MANIFORGE_MANIFEST_ENGINE_URL|MANIFORGE_REALTIME_URL|MANIFORGE_REALTIME_INTERNAL_URL|TENANT_LICENSING_INTERNAL_URL|RBAC_INTERNAL_URL|MANIFORGE_RBAC_PORT|MANIFORGE_TL_PORT|MANIFORGE_MANIFEST_PORT|MANIFORGE_VERSIONING_PORT|MANIFORGE_REALTIME_PORT)='
while IFS= read -r line || [ -n "$line" ]; do
  case "$line" in
    ''|\#*) continue ;;
  esac
  [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] || continue
  key="${line%%=*}"
  if echo "$line" | grep -qE "$SKIP_REATTACH"; then
    continue
  fi
  if ! grep -qE "^${key}=" "$ENV"; then
    printf '%s\n' "$line" >> "$ENV"
  fi
done < "$LIVE_BAK"
rm -f "$LIVE_BAK"

drop_direct_public_url_wall
generate_public_urls

echo "merged comments, host/ports, and gateway URLs into $ENV (secrets kept)"
