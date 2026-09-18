-- Резервирование остатков (без заказов/интеграций): hold под ref_code

CREATE TABLE IF NOT EXISTS maniforge_inv_reserves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    stock_id BIGINT UNSIGNED NOT NULL,
    qty DECIMAL(18, 6) NOT NULL,
    ref_code VARCHAR(64) NOT NULL,
    note VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    released_by INT UNSIGNED NULL,
    released_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inv_reserves_pair (tenant_id, product_id, stock_id, status),
    INDEX idx_inv_reserves_ref (tenant_id, ref_code, status),
    CONSTRAINT fk_inv_reserves_product FOREIGN KEY (product_id) REFERENCES maniforge_products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_reserves_stock FOREIGN KEY (stock_id) REFERENCES maniforge_wh_stocks (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_inv_reserves', 'Резервы остатков', 'Блокировка qty под ref_code до release');
