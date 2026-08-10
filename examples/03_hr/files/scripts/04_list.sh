#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; source "$ROOT/scripts/_common.sh"; require_token
for e in hr_vacancy hr_leave_request; do
  echo "== $e =="
  curl -sS "$MANIFEST_URL/api/data/$e" -H "Authorization: Bearer $TOKEN" | jq '{total:(.meta.total // (.records|length)), sample:[.records[]?.data]}'
done
