#!/bin/bash
# Gateway health helpers. Source after setting ENV to the live .env.platform path.
# Does not print secret values.

gateway_base_url() {
  # shellcheck disable=SC1091
  . "$(dirname "${BASH_SOURCE[0]}")/../server-public-urls.sh"
  public_origin
}

gateway_health_check() {
  local base fail=0
  base="$(gateway_base_url)"
  base="${base%/}"

  check() {
    local path="$1" label="$2"
    if ! curl -sf "${base}${path}" >/dev/null; then
      echo "health fail: ${label} (${base}${path})" >&2
      fail=1
    fi
  }

  check "/rbac/health" "rbac"
  check "/tenant-licensing/health" "tenant-licensing"
  check "/health" "manifest-engine"
  check "/versioning/health" "versioning"

  # Realtime is not routed on /health (manifest owns that path on the gateway).
  local rt_addr
  rt_addr="$(grep -E '^MANIFORGE_REALTIME_ADDR=' "$ENV" 2>/dev/null | head -1 | cut -d= -f2-)"
  rt_addr="${rt_addr:-127.0.0.1:8097}"
  if ! curl -sf "http://${rt_addr}/health" >/dev/null; then
    echo "health fail: realtime (http://${rt_addr}/health)" >&2
    fail=1
  fi

  if [ "$fail" -ne 0 ]; then
    return 1
  fi
  echo "gateway health ok (${base})"
}
