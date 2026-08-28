# Shared helpers for server env scripts. Expected cwd: deploy/
# Sets ENV if unset. Does not print secret values.

ENV="${ENV:-.env.platform}"

_env_get() {
  grep -E "^$1=" "$ENV" 2>/dev/null | head -1 | cut -d= -f2- || true
}

# Replace or append KEY=value. Value must not contain newlines.
_env_upsert() {
  local key="$1" val="$2"
  local tmp
  tmp="$(mktemp)"
  if grep -qE "^${key}=" "$ENV" 2>/dev/null; then
    awk -v k="$key" -v v="$val" '
      BEGIN { done = 0 }
      index($0, k "=") == 1 { print k "=" v; done = 1; next }
      { print }
      END { if (!done) print k "=" v }
    ' "$ENV" > "$tmp"
    mv "$tmp" "$ENV"
  else
    printf '%s=%s\n' "$key" "$val" >> "$ENV"
  fi
}

# Public origin for browsers/scripts: APP_URL (scheme+host) + : + MANIFORGE_GATEWAY_PORT.
# Does not write the result back into APP_URL (godotenv would not expand ${PORT} either).
public_origin() {
  local app_url host gport
  app_url="$(_env_get APP_URL)"
  app_url="${app_url%/}"
  gport="$(_env_get MANIFORGE_GATEWAY_PORT)"
  gport="${gport:-18090}"
  if [ -z "$app_url" ]; then
    host="$(_env_get MANIFORGE_PUBLIC_HOST)"
    host="${host:-127.0.0.1}"
    app_url="http://${host}"
  fi
  case "$app_url" in
    *:[0-9]*) printf '%s\n' "$app_url" ;;
    *)        printf '%s:%s\n' "$app_url" "$gport" ;;
  esac
}

# Keep APP_URL as scheme+host. Strip a trailing :MANIFORGE_GATEWAY_PORT if someone pasted it in.
normalize_app_url() {
  local app_url gport host
  app_url="$(_env_get APP_URL)"
  app_url="${app_url%/}"
  gport="$(_env_get MANIFORGE_GATEWAY_PORT)"
  gport="${gport:-18090}"
  if [ -z "$app_url" ]; then
    host="$(_env_get MANIFORGE_PUBLIC_HOST)"
    host="${host:-127.0.0.1}"
    app_url="http://${host}"
  fi
  case "$app_url" in
    *:"$gport") app_url="${app_url%:$gport}" ;;
  esac
  _env_upsert APP_URL "$app_url"
}

# Do not persist a wall of journey URLs. Callers use public_origin or loopback :18090.
generate_public_urls() {
  normalize_app_url
}

# Drop direct-service, journey, and duplicate loopback HTTP copies
# (prefer APP_URL + MANIFORGE_GATEWAY_PORT; internal hops derive from *_ADDR in Go).
drop_direct_public_url_wall() {
  local tmp
  tmp="$(mktemp)"
  grep -vE '^(MANIFORGE_RBAC_URL|MANIFORGE_TL_URL|MANIFORGE_VERSIONING_URL|NEW_USER_BASE_URL|NEW_USER_TL_URL|NEW_USER_VER_URL|MANIFEST_JOURNEY_RBAC_URL|MANIFEST_JOURNEY_ME_URL|MANIFORGE_MANIFEST_ENGINE_URL|MANIFORGE_REALTIME_URL|MANIFORGE_REALTIME_INTERNAL_URL|TENANT_LICENSING_INTERNAL_URL|RBAC_INTERNAL_URL)=' "$ENV" > "$tmp" || true
  mv "$tmp" "$ENV"
}
