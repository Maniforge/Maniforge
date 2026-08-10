#!/usr/bin/env bash
# List access_pass records.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/scripts/_common.sh"
require_token

echo "GET $MANIFEST_URL/api/data/access_pass"
curl -sS "$MANIFEST_URL/api/data/access_pass" \
  -H "Authorization: Bearer $TOKEN" | jq .
