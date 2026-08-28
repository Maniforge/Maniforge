#!/bin/bash
# Customer install entry point for Maniforge Production Box.
# Delegates to install-production.sh (backward-compatible internal implementation).
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "${SCRIPT_DIR}/install-production.sh" "$@"
