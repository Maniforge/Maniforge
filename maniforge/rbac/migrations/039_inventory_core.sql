-- Inventory: остатки (balance) + журнал движений (movements) в scope tenant/project

CREATE TABLE IF NOT EXISTS maniforge_inv_balances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    stock_id BIGINT UNSIGNED NOT NULL,
    qty DECIMAL(18, 6) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_inv_balance_pair (tenant_id, product_id, stock_id),
    INDEX idx_inv_balance_product (tenant_id, product_id),
    INDEX idx_inv_balance_stock (tenant_id, stock_id),
    CONSTRAINT fk_inv_balance_product FOREIGN KEY (product_id) REFERENCES maniforge_products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_balance_stock FOREIGN KEY (stock_id) REFERENCES maniforge_wh_stocks (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_inv_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT UNSIGNED NULL,
    scope_visibility VARCHAR(20) NOT NULL DEFAULT 'project',
    shared_subtenant_ids_json JSON NULL,
    shared_grant_tenant_ids_json JSON NULL,
    doc_number VARCHAR(64) NOT NULL,
    movement_type VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'posted',
    note TEXT NULL,
    metadata_json JSON NULL,
    created_by INT UNSIGNED NULL,
    posted_by INT UNSIGNED NULL,
    posted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_inv_movements_doc (tenant_id, subtenant_id, project_id, doc_number),
    INDEX idx_inv_movements_scope (tenant_id, subtenant_id, project_id, status, posted_at),
    CONSTRAINT fk_inv_movements_project FOREIGN KEY (project_id) REFERENCES maniforge_projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_inv_movement_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movement_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL DEFAULT 1,
    product_id BIGINT UNSIGNED NOT NULL,
    stock_id BIGINT UNSIGNED NOT NULL,
    qty_delta DECIMAL(18, 6) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_lines_movement (movement_id),
    INDEX idx_inv_lines_product_stock (product_id, stock_id),
    CONSTRAINT fk_inv_lines_movement FOREIGN KEY (movement_id) REFERENCES maniforge_inv_movements (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_lines_product FOREIGN KEY (product_id) REFERENCES maniforge_products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_lines_stock FOREIGN KEY (stock_id) REFERENCES maniforge_wh_stocks (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_inv_movements', 'Складские движения', 'Приход, расход, перемещение, корректировка остатков');
