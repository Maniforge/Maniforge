#!/bin/bash
# Gateway health helpers. Source after setting ENV to the live .env.platform path.
# Checks only packages enabled in MANIFORGE_MODULES (via maniforge-modules).
# Does not print secret values.

gateway_base_url() {
  local override
  override="$(grep -E '^MANIFORGE_GATEWAY_HEALTH_URL=' "$ENV" 2>/dev/null | head -1 | cut -d= -f2-)"
  if [ -n "$override" ]; then
    printf '%s\n' "${override%/}"
    return 0
  fi
  # shellcheck disable=SC1091
  . "$(dirname "${BASH_SOURCE[0]}")/../server-public-urls.sh"
  public_origin
}

modules_resolve() {
  local root bin
  root="${MANIFORGE_ROOT:-}"
  if [ -z "$root" ]; then
    root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
  fi
  if [ -x "${root}/bin/maniforge-modules" ]; then
    bin="${root}/bin/maniforge-modules"
  elif [ -x "${root}/bin/maniforge-modules.exe" ]; then
    bin="${root}/bin/maniforge-modules.exe"
  else
    return 1
  fi
  eval "$("$bin" resolve --root "$root" --env "$ENV")"
}

gateway_health_check() {
  local base fail=0
  base="$(gateway_base_url)"
  base="${base%/}"

  check() {
    local path="$1" label="$2"
    if ! curl -sf -m 8 "${base}${path}" >/dev/null; then
      echo "health fail: ${label} (${base}${path})" >&2
      fail=1
    fi
  }

  if modules_resolve; then
    local p
    for p in ${MANIFORGE_HEALTH_PATHS:-}; do
      check "$p" "$p"
    done
    for p in ${MANIFORGE_DIRECT_HEALTH:-}; do
      if ! curl -sf -m 8 "$p" >/dev/null; then
        echo "health fail: direct (${p})" >&2
        fail=1
      fi
    done
  else
    check "/rbac/health" "rbac"
    check "/tenant-licensing/health" "tenant-licensing"
    check "/health" "manifest-engine"
    check "/versioning/health" "versioning"
    check "/warehouses/health" "warehouses"
    check "/products/health" "products"
    check "/inventory/health" "inventory"
    check "/wms/health" "wms"
    local rt_addr
    rt_addr="$(grep -E '^MANIFORGE_REALTIME_ADDR=' "$ENV" 2>/dev/null | head -1 | cut -d= -f2-)"
    rt_addr="${rt_addr:-127.0.0.1:8097}"
    if ! curl -sf -m 8 "http://${rt_addr}/health" >/dev/null; then
      echo "health fail: realtime (http://${rt_addr}/health)" >&2
      fail=1
    fi
  fi

  if [ "$fail" -ne 0 ]; then
    return 1
  fi
  echo "gateway health ok (${base})"
}
