#!/bin/bash
# Native Go build on the server (no Docker). Fast path for nzgapp.
set -euo pipefail

ROOT="${MANIFORGE_ROOT:-/opt/maniforge/platform-core}"
cd "$ROOT"

export CGO_ENABLED=0
export PATH="/usr/local/go/bin:${PATH}"

if ! command -v go >/dev/null 2>&1; then
  echo "go not found in PATH (need 1.25+). Install official tarball to /usr/local/go" >&2
  exit 1
fi

mkdir -p bin

echo "$(go version)"
echo "building into $ROOT/bin"

go build -trimpath -ldflags="-s -w" -o bin/maniforge-migrate ./cmd/migrate
go build -trimpath -ldflags="-s -w" -o bin/maniforge-preflight ./cmd/preflight
go build -trimpath -ldflags="-s -w" -o bin/maniforge-siem-forward ./cmd/siem-forward
go build -trimpath -ldflags="-s -w" -o bin/maniforge-token-gen ./cmd/token-gen
go build -trimpath -ldflags="-s -w" -o bin/maniforge-backup-drill ./cmd/backup-drill
go build -trimpath -ldflags="-s -w" -o bin/maniforge-tl-expire-licenses ./cmd/tl-expire-licenses
go build -trimpath -ldflags="-s -w" -o bin/maniforge-tl-dispatch-events ./cmd/tl-dispatch-events
go build -trimpath -ldflags="-s -w" -o bin/maniforge-rbac ./cmd/rbac
go build -trimpath -ldflags="-s -w" -o bin/maniforge-tenant-licensing ./cmd/tenant-licensing
go build -trimpath -ldflags="-s -w" -o bin/maniforge-manifest-engine ./cmd/manifest-engine
go build -trimpath -ldflags="-s -w" -o bin/maniforge-versioning ./cmd/versioning
go build -trimpath -ldflags="-s -w" -o bin/maniforge-realtime ./cmd/realtime

echo "build ok"
