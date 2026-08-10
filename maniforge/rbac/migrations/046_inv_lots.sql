-- Партии / серии (batch + lot) для партионного учёта

CREATE TABLE IF NOT EXISTS maniforge_inv_lots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    batch_code VARCHAR(64) NOT NULL DEFAULT '',
    lot_code VARCHAR(64) NOT NULL DEFAULT '',
    manufactured_at DATE NULL,
    expires_at DATE NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_inv_lots_key (tenant_id, product_id, batch_code, lot_code),
    INDEX idx_inv_lots_product (tenant_id, product_id, status),
    INDEX idx_inv_lots_expires (tenant_id, expires_at),
    CONSTRAINT fk_inv_lots_product FOREIGN KEY (product_id) REFERENCES maniforge_products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE maniforge_inv_movement_lines
    ADD COLUMN lot_id BIGINT UNSIGNED NULL AFTER lot_code,
    ADD INDEX idx_inv_lines_lot (lot_id),
    ADD CONSTRAINT fk_inv_lines_lot FOREIGN KEY (lot_id) REFERENCES maniforge_inv_lots (id) ON DELETE SET NULL;

INSERT IGNORE INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_inv_lots', 'Партии товара', 'Batch/lot регистр для движений');
