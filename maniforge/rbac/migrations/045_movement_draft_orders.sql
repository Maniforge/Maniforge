-- Черновики движений (posted_at nullable) + заказы склада

ALTER TABLE maniforge_inv_movements
    MODIFY posted_at TIMESTAMP NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS maniforge_inv_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    order_number VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    stock_id BIGINT UNSIGNED NOT NULL,
    note TEXT NULL,
    metadata_json JSON NULL,
    created_by INT UNSIGNED NULL,
    confirmed_at TIMESTAMP NULL DEFAULT NULL,
    fulfilled_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_inv_orders_number (tenant_id, order_number),
    INDEX idx_inv_orders_status (tenant_id, status, created_at),
    CONSTRAINT fk_inv_orders_stock FOREIGN KEY (stock_id) REFERENCES maniforge_wh_stocks (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_inv_order_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL DEFAULT 1,
    product_id BIGINT UNSIGNED NOT NULL,
    qty_ordered DECIMAL(18, 6) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_inv_order_line (order_id, line_no),
    INDEX idx_inv_order_lines_product (product_id),
    CONSTRAINT fk_inv_order_lines_order FOREIGN KEY (order_id) REFERENCES maniforge_inv_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_order_lines_product FOREIGN KEY (product_id) REFERENCES maniforge_products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_inv_orders', 'Складские заказы', 'Заявки на отгрузку с резервированием');
