-- Versioning: реестр отслеживаемых таблиц и журнал изменений (порт PHP 022_versioning).
-- См. docs/MANIFORGE_MANIFEST_ENGINE_PLAN.md (фаза 3)

CREATE TABLE IF NOT EXISTS maniforge_ver_registry (
    id BIGSERIAL PRIMARY KEY,
    entity_table VARCHAR(120) NOT NULL UNIQUE,
    entity_label VARCHAR(255) NOT NULL,
    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS maniforge_ver_changes (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT NULL REFERENCES maniforge_projects(id) ON DELETE SET NULL,
    entity_table VARCHAR(120) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    entity_label VARCHAR(255) NULL,
    operation VARCHAR(20) NOT NULL,
    actor_user_id BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    correlation_id VARCHAR(64) NULL,
    before_json JSONB NULL,
    after_json JSONB NULL,
    changed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ver_changes_scope_time
    ON maniforge_ver_changes (tenant_id, subtenant_id, changed_at DESC);
CREATE INDEX IF NOT EXISTS idx_ver_changes_entity
    ON maniforge_ver_changes (entity_table, entity_id, changed_at DESC);
CREATE INDEX IF NOT EXISTS idx_ver_changes_project
    ON maniforge_ver_changes (project_id, changed_at DESC);
CREATE INDEX IF NOT EXISTS idx_ver_changes_actor
    ON maniforge_ver_changes (actor_user_id, changed_at DESC);

INSERT INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_projects', 'Проекты', 'Создание и изменение проектов в scope tenant/subtenant'),
    ('maniforge_users', 'Пользователи', 'Изменения профиля и статуса пользователей'),
    ('maniforge_manifest_records', 'Manifest записи', 'CRUD записей Manifest Engine (data_json)')
ON CONFLICT (entity_table) DO NOTHING;

INSERT INTO maniforge_permissions (code, description) VALUES
    ('versioning.read', 'Просмотр истории версий записей в scope сессии'),
    ('versioning.registry.read', 'Просмотр реестра отслеживаемых таблиц')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('versioning.read', 'versioning.registry.read')
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code = 'versioning.read'
WHERE r.code IN ('moderator', 'support_operator')
ON CONFLICT DO NOTHING;
