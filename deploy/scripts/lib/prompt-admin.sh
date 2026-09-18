# Demo admin for zero-config install. Override via ADMIN_* / MANIFORGE_ADMIN_* or
# --admin-login / --admin-password / --admin-org. Never prints the password.
# Expects ENV = .env.platform path and _env_get / _env_upsert.

maniforge_demo_admin_login() { printf '%s' '+79991234567'; }
maniforge_demo_admin_org() { printf '%s' 'Demo'; }
maniforge_demo_admin_password() {
  printf '%s' "${MANIFORGE_DEMO_ADMIN_PASSWORD:-DemoAdmin!12345}"
}

maniforge_fill_admin() {
  local login pass org used_demo=0
  login="${ADMIN_LOGIN:-${MANIFORGE_ADMIN_LOGIN:-$(_env_get MANIFORGE_ADMIN_LOGIN)}}"
  pass="${ADMIN_PASSWORD:-${MANIFORGE_ADMIN_PASSWORD:-$(_env_get MANIFORGE_ADMIN_PASSWORD)}}"
  org="${ADMIN_ORG:-${MANIFORGE_ADMIN_ORG:-$(_env_get MANIFORGE_ADMIN_ORG)}}"

  if [ -z "$login" ] || [[ "$login" == CHANGE_ME* ]]; then
    login="$(maniforge_demo_admin_login)"
    used_demo=1
  fi
  if [ -z "$pass" ] || [[ "$pass" == CHANGE_ME* ]]; then
    pass="$(maniforge_demo_admin_password)"
    used_demo=1
  fi
  if [ -z "$org" ]; then
    org="$(maniforge_demo_admin_org)"
  fi

  _env_upsert MANIFORGE_ADMIN_LOGIN "$login"
  _env_upsert MANIFORGE_ADMIN_PASSWORD "$pass"
  _env_upsert MANIFORGE_ADMIN_ORG "$org"

  if [ "$used_demo" = "1" ]; then
    echo "==> demo admin defaults (login ${login}, org ${org}; password not printed)"
  fi
  return 0
}
