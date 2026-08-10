-- Managed tenant grants (ось A): principal tenant → managed client tenant.
-- Порт PHP: maniforge/rbac/migrations/015_agency_delegation.sql

CREATE TABLE IF NOT EXISTS maniforge_tl_tenant_grants (
    id BIGSERIAL PRIMARY KEY,
    principal_tenant_code VARCHAR(100) NOT NULL,
    managed_tenant_code VARCHAR(100) NOT NULL,
    grant_level VARCHAR(30) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    metadata_json JSONB NULL,
    created_by VARCHAR(120) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    revoked_at TIMESTAMPTZ NULL,
    UNIQUE (principal_tenant_code, managed_tenant_code),
    CONSTRAINT fk_tl_grant_principal FOREIGN KEY (principal_tenant_code)
        REFERENCES maniforge_tl_tenants(code) ON DELETE RESTRICT,
    CONSTRAINT fk_tl_grant_managed FOREIGN KEY (managed_tenant_code)
        REFERENCES maniforge_tl_tenants(code) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_tl_grants_principal_status
    ON maniforge_tl_tenant_grants (principal_tenant_code, status);
CREATE INDEX IF NOT EXISTS idx_tl_grants_managed
    ON maniforge_tl_tenant_grants (managed_tenant_code);
