#!/bin/sh
set -e

if [ "${MANIFORGE_RUN_PREFLIGHT:-false}" = "true" ]; then
  echo "Running preflight..."
  maniforge-preflight
fi

exec "$@"
