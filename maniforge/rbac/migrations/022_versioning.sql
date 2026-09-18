CREATE TABLE IF NOT EXISTS maniforge_ver_registry (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_table VARCHAR(120) NOT NULL,
    entity_label VARCHAR(255) NOT NULL,
    description VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ver_registry_table (entity_table)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_ver_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT UNSIGNED NULL,
    entity_table VARCHAR(120) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    entity_label VARCHAR(255) NULL,
    operation VARCHAR(20) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    correlation_id VARCHAR(64) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ver_changes_scope_time (tenant_id, subtenant_id, changed_at),
    INDEX idx_ver_changes_entity (entity_table, entity_id, changed_at),
    INDEX idx_ver_changes_project (project_id, changed_at),
    INDEX idx_ver_changes_actor (actor_user_id, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_projects', 'Проекты', 'Создание и изменение проектов в scope tenant/subtenant'),
    ('maniforge_scope_variables', 'Глобальные переменные', 'Upsert переменных tenant/subtenant/project scope'),
    ('maniforge_users', 'Пользователи', 'Изменения профиля и статуса пользователей');

INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('versioning.read', 'Просмотр истории версий записей в scope сессии'),
    ('versioning.registry.read', 'Просмотр реестра отслеживаемых таблиц');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('versioning.read', 'versioning.registry.read')
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code = 'versioning.read'
WHERE r.code IN ('moderator', 'support_operator');
