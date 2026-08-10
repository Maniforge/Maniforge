-- Subject requests (152-ФЗ) и permissions для PD admin + audit export.
-- Порт maniforge/rbac/migrations/024 + 025 + 027 (MySQL).

CREATE TABLE IF NOT EXISTS maniforge_pd_subject_requests (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    request_type VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    payload_json JSONB NULL,
    handler_user_id BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    handler_note TEXT NULL,
    due_at TIMESTAMPTZ NULL,
    completed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_pd_request_scope
    ON maniforge_pd_subject_requests (tenant_id, subtenant_id, status);
CREATE INDEX IF NOT EXISTS idx_pd_request_user
    ON maniforge_pd_subject_requests (user_id, created_at DESC);

INSERT INTO maniforge_permissions (code, description) VALUES
    ('admin.pd.operator.read', 'Read tenant PD operator profile'),
    ('admin.pd.operator.write', 'Update tenant PD operator profile'),
    ('admin.pd.purposes.read', 'Read processing purposes'),
    ('admin.pd.purposes.write', 'Manage processing purposes'),
    ('admin.pd.requests.read', 'Read subject PD requests in tenant'),
    ('admin.pd.requests.handle', 'Resolve subject PD requests'),
    ('admin.audit.export', 'Export audit log for compliance archive')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.pd.operator.read', 'admin.pd.operator.write',
    'admin.pd.purposes.read', 'admin.pd.purposes.write',
    'admin.pd.requests.read', 'admin.pd.requests.handle',
    'admin.audit.export'
)
WHERE r.code IN ('super_admin', 'tenant_admin')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.pd.operator.read', 'admin.pd.purposes.read', 'admin.pd.requests.read'
)
WHERE r.code IN ('subtenant_admin', 'security_auditor')
ON CONFLICT DO NOTHING;
