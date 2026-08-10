#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; source "$ROOT/scripts/_common.sh"; require_token
ensure_manifest "$ROOT/manifest.hr_vacancy.json"
ensure_manifest "$ROOT/manifest.hr_leave_request.json"
echo OK
