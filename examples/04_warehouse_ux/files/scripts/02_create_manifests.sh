#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token
shopt -s nullglob
for f in "$ROOT"/manifests/manifest.*.json; do
  ensure_manifest "$f"
done
echo OK
