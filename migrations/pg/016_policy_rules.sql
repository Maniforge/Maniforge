-- Admin policy rules (IP allowlist, UTC hour window, step-up).
-- Порт maniforge/rbac/migrations/010_policy_rules_and_permissions.sql (MySQL).

CREATE TABLE IF NOT EXISTS maniforge_policy_rules (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    allowed_ips_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    allowed_hour_start_utc SMALLINT NOT NULL DEFAULT 0,
    allowed_hour_end_utc SMALLINT NOT NULL DEFAULT 23,
    require_step_up BOOLEAN NOT NULL DEFAULT TRUE,
    updated_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, subtenant_id)
);

CREATE INDEX IF NOT EXISTS idx_policy_rules_scope
    ON maniforge_policy_rules (tenant_id, subtenant_id);
