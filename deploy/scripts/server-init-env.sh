#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
cp .env.platform.server.example .env.platform
DB_PASS=$(openssl rand -hex 16)
REP_PASS=$(openssl rand -hex 16)
TOK=$(openssl rand -hex 24)
awk -v db="$DB_PASS" -v rep="$REP_PASS" -v tok="$TOK" '{
  gsub("CHANGE_ME_MANIFORGE_DB_PASS", db)
  gsub("CHANGE_ME_REPLICATOR_PASSWORD", rep)
  gsub("CHANGE_ME_INTERNAL_TOKEN", tok)
  gsub("CHANGE_ME_ADMIN_TOKEN", tok)
  print
}' .env.platform > .env.platform.tmp && mv .env.platform.tmp .env.platform

ENV=.env.platform
# shellcheck source=server-public-urls.sh
. "$(dirname "$0")/server-public-urls.sh"
generate_public_urls

echo "env ok"
