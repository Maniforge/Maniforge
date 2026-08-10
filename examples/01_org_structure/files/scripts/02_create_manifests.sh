#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token
ensure_manifest "$ROOT/manifest.org_unit.json"
ensure_manifest "$ROOT/manifest.org_employee.json"
echo OK
