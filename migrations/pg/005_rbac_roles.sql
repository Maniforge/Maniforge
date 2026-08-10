-- RBAC: системные роли, permissions и назначения в scope tenant + subtenant (workspace).

ALTER TABLE maniforge_projects
    ADD COLUMN IF NOT EXISTS is_default BOOLEAN NOT NULL DEFAULT FALSE; -- проект по умолчанию в workspace

CREATE TABLE IF NOT EXISTS maniforge_roles (
    id BIGSERIAL PRIMARY KEY,                    -- суррогатный PK
    code VARCHAR(80) NOT NULL UNIQUE,            -- машинный код роли (tenant_admin, user, …)
    name VARCHAR(120) NOT NULL,                  -- отображаемое имя
    is_system BOOLEAN NOT NULL DEFAULT FALSE,    -- TRUE = встроенная роль, нельзя удалить
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW() -- время создания
);

CREATE TABLE IF NOT EXISTS maniforge_permissions (
    id BIGSERIAL PRIMARY KEY,              -- суррогатный PK
    code VARCHAR(180) NOT NULL UNIQUE,     -- машинный код (users.read, projects.write, …)
    description VARCHAR(255) NULL          -- человекочитаемое описание
);

CREATE TABLE IF NOT EXISTS maniforge_role_permissions (
    role_id BIGINT NOT NULL REFERENCES maniforge_roles(id) ON DELETE CASCADE,       -- FK роль
    permission_id BIGINT NOT NULL REFERENCES maniforge_permissions(id) ON DELETE CASCADE, -- FK permission
    PRIMARY KEY (role_id, permission_id)
);

-- Назначение роли пользователю в конкретном workspace; expires_at — временный доступ.
CREATE TABLE IF NOT EXISTS maniforge_user_roles (
    id BIGSERIAL PRIMARY KEY,                                              -- суррогатный PK
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE, -- пользователь
    role_id BIGINT NOT NULL REFERENCES maniforge_roles(id) ON DELETE CASCADE,   -- назначенная роль
    tenant_id VARCHAR(100) NOT NULL,                                       -- код tenant scope
    subtenant_id VARCHAR(100) NOT NULL,                                    -- код workspace scope
    assigned_by BIGINT NULL,                                               -- user_id назначившего (NULL = система)
    assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                          -- момент назначения
    expires_at TIMESTAMPTZ NULL                                            -- NULL = бессрочно
);

CREATE INDEX IF NOT EXISTS idx_user_roles_scope ON maniforge_user_roles (user_id, tenant_id, subtenant_id);

INSERT INTO maniforge_roles (code, name, is_system) VALUES
    ('super_admin', 'Super Admin', TRUE),
    ('tenant_admin', 'Tenant Admin', TRUE),
    ('subtenant_admin', 'Subtenant Admin', TRUE),
    ('security_auditor', 'Security Auditor', TRUE),
    ('support_operator', 'Support Operator', TRUE),
    ('user', 'User', TRUE)
ON CONFLICT (code) DO NOTHING;
