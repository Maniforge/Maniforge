#!/bin/bash
# Primary init: replication user + slot (runs once on first primary start)
set -euo pipefail

: "${REPLICATOR_PASSWORD:?REPLICATOR_PASSWORD is required}"

psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-EOSQL
    DO \$\$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'replicator') THEN
            CREATE ROLE replicator WITH REPLICATION LOGIN PASSWORD '${REPLICATOR_PASSWORD}';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM pg_replication_slots WHERE slot_name = 'replica_slot') THEN
            PERFORM pg_create_physical_replication_slot('replica_slot');
        END IF;
    END
    \$\$;
EOSQL

echo "host replication replicator 0.0.0.0/0 scram-sha-256" >> "${PGDATA}/pg_hba.conf"
echo "host all all 0.0.0.0/0 scram-sha-256" >> "${PGDATA}/pg_hba.conf"
