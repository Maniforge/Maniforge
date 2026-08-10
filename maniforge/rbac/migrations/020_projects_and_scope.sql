CREATE TABLE IF NOT EXISTS maniforge_projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    code VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_project_scope (tenant_id, subtenant_id, code),
    INDEX idx_projects_tenant_status (tenant_id, status),
    INDEX idx_projects_subtenant (tenant_id, subtenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_scope_variables (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT UNSIGNED NULL,
    var_key VARCHAR(180) NOT NULL,
    var_value TEXT NOT NULL,
    value_type VARCHAR(30) NOT NULL DEFAULT 'string',
    scope_level VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_scope_var (tenant_id, subtenant_id, project_id, var_key),
    INDEX idx_scope_var_tenant (tenant_id, scope_level),
    CONSTRAINT fk_scope_var_project FOREIGN KEY (project_id) REFERENCES maniforge_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_user_project_memberships (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_project (user_id, project_id),
    INDEX idx_membership_scope (tenant_id, subtenant_id, project_id),
    CONSTRAINT fk_upm_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_upm_project FOREIGN KEY (project_id) REFERENCES maniforge_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE maniforge_sessions
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER subtenant_id,
    ADD INDEX idx_sessions_project (project_id),
    ADD CONSTRAINT fk_sessions_project FOREIGN KEY (project_id) REFERENCES maniforge_projects(id) ON DELETE SET NULL;

ALTER TABLE maniforge_refresh_tokens
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER subtenant_id,
    ADD CONSTRAINT fk_refresh_project FOREIGN KEY (project_id) REFERENCES maniforge_projects(id) ON DELETE SET NULL;

ALTER TABLE maniforge_tl_tenants
    ADD COLUMN entity_type VARCHAR(20) NOT NULL DEFAULT 'legal' AFTER status,
    ADD INDEX idx_tl_tenants_entity_type (entity_type);
