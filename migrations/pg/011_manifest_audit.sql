-- Audit trail Manifest Engine (операции manifest и records).

CREATE TABLE IF NOT EXISTS maniforge_manifest_audit_log (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    project_id BIGINT NOT NULL,
    manifest_code VARCHAR(100) NULL,
    record_id BIGINT NULL,
    actor_user_id BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    payload_json JSONB NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_manifest_audit_scope ON maniforge_manifest_audit_log (tenant_id, project_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_manifest_audit_manifest ON maniforge_manifest_audit_log (manifest_code, created_at DESC);
