#!/bin/sh
# Standby bootstrap: pg_basebackup from primary, then start postgres in recovery mode.
set -eu

PRIMARY_HOST="${PRIMARY_HOST:-postgres-primary}"
PRIMARY_PORT="${PRIMARY_PORT:-5432}"
REPLICATOR_USER="${REPLICATOR_USER:-replicator}"
REPLICATOR_PASSWORD="${REPLICATOR_PASSWORD:?REPLICATOR_PASSWORD is required}"
REPLICATION_SLOT="${REPLICATION_SLOT:-replica_slot}"

if [ ! -s "${PGDATA}/PG_VERSION" ]; then
  echo "[replica] waiting for primary ${PRIMARY_HOST}:${PRIMARY_PORT}..."
  until pg_isready -h "${PRIMARY_HOST}" -p "${PRIMARY_PORT}" -U "${POSTGRES_USER:-maniforge}" >/dev/null 2>&1; do
    sleep 2
  done

  echo "[replica] running pg_basebackup..."
  rm -rf "${PGDATA:?}"/*
  PGPASSWORD="${REPLICATOR_PASSWORD}" pg_basebackup \
    -h "${PRIMARY_HOST}" -p "${PRIMARY_PORT}" \
    -U "${REPLICATOR_USER}" -D "${PGDATA}" \
    -Fp -Xs -P -R -S "${REPLICATION_SLOT}"

  touch "${PGDATA}/standby.signal"
fi

exec docker-entrypoint.sh postgres
