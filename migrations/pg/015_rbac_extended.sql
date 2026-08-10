-- RBAC extended: action tokens, scope variables, permissions seed (порт PHP 017, 020, 021, 006–009).

ALTER TABLE maniforge_sessions
    ADD COLUMN IF NOT EXISTS csrf_token_hash CHAR(64) NULL;

CREATE TABLE IF NOT EXISTS maniforge_action_tokens (
    id CHAR(32) PRIMARY KEY,
    session_id CHAR(32) NOT NULL REFERENCES maniforge_sessions(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    purpose VARCHAR(32) NOT NULL DEFAULT 'admin_sensitive',
    expires_at TIMESTAMPTZ NOT NULL,
    revoked_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_action_tokens_hash_active
    ON maniforge_action_tokens (token_hash, revoked_at, expires_at);
CREATE INDEX IF NOT EXISTS idx_action_tokens_session_active
    ON maniforge_action_tokens (session_id, revoked_at, expires_at);

CREATE TABLE IF NOT EXISTS maniforge_scope_variables (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT NULL REFERENCES maniforge_projects(id) ON DELETE CASCADE,
    var_key VARCHAR(180) NOT NULL,
    var_value TEXT NOT NULL,
    value_type VARCHAR(30) NOT NULL DEFAULT 'string',
    scope_level VARCHAR(30) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL,
    UNIQUE (tenant_id, subtenant_id, project_id, var_key)
);

CREATE INDEX IF NOT EXISTS idx_scope_var_tenant ON maniforge_scope_variables (tenant_id, scope_level);

CREATE TABLE IF NOT EXISTS maniforge_user_project_memberships (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE,
    project_id BIGINT NOT NULL REFERENCES maniforge_projects(id) ON DELETE CASCADE,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, project_id)
);

CREATE INDEX IF NOT EXISTS idx_membership_scope
    ON maniforge_user_project_memberships (tenant_id, subtenant_id, project_id);

INSERT INTO maniforge_permissions (code, description) VALUES
    ('admin.users.read', 'Read users in admin area'),
    ('admin.sessions.read', 'Read sessions in admin area'),
    ('admin.sessions.revoke', 'Revoke sessions in admin area'),
    ('admin.sessions.bulk', 'Batch revoke sessions in admin area'),
    ('admin.audit.read', 'Read audit log'),
    ('admin.security_events.read', 'Read security events'),
    ('admin.roles.read', 'Read roles in admin area'),
    ('admin.permissions.read', 'Read permissions in admin area'),
    ('admin.user_roles.read', 'Read user role assignments'),
    ('admin.user_roles.assign', 'Assign role to user'),
    ('admin.user_roles.revoke', 'Revoke role from user'),
    ('admin.user_roles.bulk', 'Batch role mutations'),
    ('admin.user_access.read', 'Read effective roles and permissions for user'),
    ('admin.users.status.bulk', 'Batch change user status in admin area'),
    ('admin.policies.read', 'Read policy rules in admin area'),
    ('admin.policies.update', 'Update policy rules in admin area'),
    ('projects.read', 'List and read projects in session scope'),
    ('projects.create', 'Create projects in session scope'),
    ('projects.update', 'Update projects in session scope'),
    ('scope_variables.read', 'Read scope variables visible in session'),
    ('scope_variables.create', 'Create scope variables in session scope'),
    ('scope_variables.update', 'Update scope variables in session scope')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.users.read', 'admin.sessions.read', 'admin.sessions.revoke', 'admin.sessions.bulk',
    'admin.audit.read', 'admin.security_events.read', 'admin.roles.read', 'admin.permissions.read',
    'admin.user_roles.read', 'admin.user_roles.assign', 'admin.user_roles.revoke', 'admin.user_roles.bulk',
    'admin.user_access.read', 'admin.users.status.bulk', 'admin.policies.read', 'admin.policies.update',
    'projects.read', 'projects.create', 'projects.update',
    'scope_variables.read', 'scope_variables.create', 'scope_variables.update',
    'versioning.read', 'versioning.registry.read'
)
WHERE r.code IN ('super_admin', 'tenant_admin')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.users.read', 'admin.sessions.read', 'admin.sessions.bulk', 'admin.audit.read',
    'admin.security_events.read', 'admin.roles.read', 'admin.permissions.read', 'admin.user_roles.read',
    'admin.user_access.read', 'projects.read', 'projects.create', 'projects.update',
    'scope_variables.read', 'scope_variables.create', 'scope_variables.update', 'versioning.read'
)
WHERE r.code = 'subtenant_admin'
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('projects.read', 'scope_variables.read', 'versioning.read')
WHERE r.code IN ('user', 'support_operator')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_scope_variables', 'Глобальные переменные', 'Upsert переменных tenant/subtenant/project scope'),
    ('maniforge_registration_invites', 'Invite-ссылки', 'Создание invite для регистрации')
ON CONFLICT (entity_table) DO NOTHING;
