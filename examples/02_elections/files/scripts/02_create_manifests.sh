#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; source "$ROOT/scripts/_common.sh"; require_token
ensure_manifest "$ROOT/manifest.election_event.json"
ensure_manifest "$ROOT/manifest.polling_station.json"
ensure_manifest "$ROOT/manifest.candidacy.json"
echo OK
