-- Audit log (RBAC + модули) и soft delete для manifest records.
-- Порт maniforge/rbac/migrations/003 + 012 (MySQL) для PostgreSQL / Go-контура.

CREATE TABLE IF NOT EXISTS maniforge_audit_log (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    actor_user_id BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    payload_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    correlation_id CHAR(32) NULL,
    integrity_hash CHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_scope_created
    ON maniforge_audit_log (tenant_id, subtenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_actor
    ON maniforge_audit_log (actor_user_id);
CREATE INDEX IF NOT EXISTS idx_audit_correlation
    ON maniforge_audit_log (correlation_id);

CREATE TABLE IF NOT EXISTS maniforge_security_events (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    user_id BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    severity VARCHAR(30) NOT NULL DEFAULT 'info',
    payload_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    correlation_id CHAR(32) NULL,
    integrity_hash CHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sec_events_scope_created
    ON maniforge_security_events (tenant_id, subtenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sec_events_user
    ON maniforge_security_events (user_id);
CREATE INDEX IF NOT EXISTS idx_sec_events_correlation
    ON maniforge_security_events (correlation_id);

-- Soft delete: deleted_at вместо физического DELETE (manifest records).
ALTER TABLE maniforge_manifest_records
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS deleted_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_manifest_records_active
    ON maniforge_manifest_records (tenant_id, project_id, manifest_id)
    WHERE deleted_at IS NULL;
