-- Products module: номенклатура в scope tenant + project (+ visibility / delegation share)

CREATE TABLE IF NOT EXISTS maniforge_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT UNSIGNED NULL,
    scope_visibility VARCHAR(20) NOT NULL DEFAULT 'project',
    shared_subtenant_ids_json JSON NULL,
    shared_grant_tenant_ids_json JSON NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    unit VARCHAR(32) NOT NULL DEFAULT 'pcs',
    description TEXT NULL,
    attributes_json JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_products_scope_code (tenant_id, subtenant_id, project_id, code),
    INDEX idx_products_scope_status (tenant_id, subtenant_id, project_id, status),
    CONSTRAINT fk_products_project FOREIGN KEY (project_id) REFERENCES maniforge_projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_products', 'Товары (номенклатура)', 'Создание и обновление SKU в scope tenant/project');
