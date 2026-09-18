-- WMS: групповая упаковка, паллеты (SSCC/QR), КИЗ (маркировка)

CREATE TABLE IF NOT EXISTS maniforge_wms_pack_units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT UNSIGNED NULL,
    scope_visibility VARCHAR(20) NOT NULL DEFAULT 'project',
    shared_subtenant_ids_json JSON NULL,
    shared_grant_tenant_ids_json JSON NULL,
    unit_type VARCHAR(32) NOT NULL,
    code VARCHAR(64) NOT NULL,
    sscc VARCHAR(20) NULL,
    qr_payload TEXT NULL,
    qr_lookup VARCHAR(255) NOT NULL,
    stock_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    qty_capacity DECIMAL(18, 6) NULL,
    uom VARCHAR(32) NOT NULL DEFAULT 'pcs',
    sealed_at TIMESTAMP NULL DEFAULT NULL,
    sealed_by INT UNSIGNED NULL,
    metadata_json JSON NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_wms_pack_code (tenant_id, subtenant_id, project_id, code),
    UNIQUE KEY uk_wms_pack_sscc (tenant_id, sscc),
    UNIQUE KEY uk_wms_pack_qr_lookup (tenant_id, qr_lookup),
    INDEX idx_wms_pack_scope (tenant_id, subtenant_id, project_id, unit_type, status),
    CONSTRAINT fk_wms_pack_project FOREIGN KEY (project_id) REFERENCES maniforge_projects (id) ON DELETE RESTRICT,
    CONSTRAINT fk_wms_pack_stock FOREIGN KEY (stock_id) REFERENCES maniforge_wh_stocks (id) ON DELETE SET NULL,
    CONSTRAINT fk_wms_pack_product FOREIGN KEY (product_id) REFERENCES maniforge_products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_wms_marking_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    code_full VARCHAR(255) NOT NULL,
    code_type VARCHAR(32) NOT NULL DEFAULT 'kiz',
    gtin VARCHAR(14) NULL,
    serial_number VARCHAR(50) NULL,
    crypto_tail VARCHAR(44) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'available',
    pack_unit_id BIGINT UNSIGNED NULL,
    parent_marking_id BIGINT UNSIGNED NULL,
    stock_id BIGINT UNSIGNED NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_wms_marking_code (tenant_id, code_full),
    INDEX idx_wms_marking_product (tenant_id, product_id, status),
    INDEX idx_wms_marking_pack (pack_unit_id),
    INDEX idx_wms_marking_gtin_serial (tenant_id, gtin, serial_number),
    CONSTRAINT fk_wms_marking_product FOREIGN KEY (product_id) REFERENCES maniforge_products (id) ON DELETE RESTRICT,
    CONSTRAINT fk_wms_marking_pack FOREIGN KEY (pack_unit_id) REFERENCES maniforge_wms_pack_units (id) ON DELETE SET NULL,
    CONSTRAINT fk_wms_marking_parent FOREIGN KEY (parent_marking_id) REFERENCES maniforge_wms_marking_codes (id) ON DELETE SET NULL,
    CONSTRAINT fk_wms_marking_stock FOREIGN KEY (stock_id) REFERENCES maniforge_wh_stocks (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_wms_pack_contents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_pack_unit_id BIGINT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL DEFAULT 1,
    child_pack_unit_id BIGINT UNSIGNED NULL,
    marking_code_id BIGINT UNSIGNED NULL,
    qty DECIMAL(18, 6) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wms_pack_contents_parent (parent_pack_unit_id),
    CONSTRAINT fk_wms_contents_parent FOREIGN KEY (parent_pack_unit_id) REFERENCES maniforge_wms_pack_units (id) ON DELETE RESTRICT,
    CONSTRAINT fk_wms_contents_child FOREIGN KEY (child_pack_unit_id) REFERENCES maniforge_wms_pack_units (id) ON DELETE RESTRICT,
    CONSTRAINT fk_wms_contents_marking FOREIGN KEY (marking_code_id) REFERENCES maniforge_wms_marking_codes (id) ON DELETE RESTRICT,
    CONSTRAINT chk_wms_contents_target CHECK (
        (child_pack_unit_id IS NOT NULL AND marking_code_id IS NULL)
        OR (child_pack_unit_id IS NULL AND marking_code_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE maniforge_inv_movement_lines
    ADD COLUMN pack_unit_id BIGINT UNSIGNED NULL AFTER stock_id,
    ADD COLUMN marking_code_id BIGINT UNSIGNED NULL AFTER pack_unit_id,
    ADD COLUMN batch_code VARCHAR(64) NULL AFTER marking_code_id,
    ADD COLUMN lot_code VARCHAR(64) NULL AFTER batch_code,
    ADD INDEX idx_inv_lines_pack (pack_unit_id),
    ADD INDEX idx_inv_lines_marking (marking_code_id),
    ADD CONSTRAINT fk_inv_lines_pack FOREIGN KEY (pack_unit_id) REFERENCES maniforge_wms_pack_units (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_inv_lines_marking FOREIGN KEY (marking_code_id) REFERENCES maniforge_wms_marking_codes (id) ON DELETE SET NULL;

INSERT IGNORE INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_wms_pack_units', 'WMS упаковки', 'Паллеты, групповые упаковки, SSCC/QR'),
    ('maniforge_wms_marking_codes', 'WMS маркировка', 'КИЗ и коды идентификации');
