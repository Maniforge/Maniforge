#!/bin/bash
# Customer verify entry point for Maniforge Production Box.
# Delegates to verify-production.sh (backward-compatible internal implementation).
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "${SCRIPT_DIR}/verify-production.sh" "$@"
