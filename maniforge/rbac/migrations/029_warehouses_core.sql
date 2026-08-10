-- Warehouses module (enterprise.local WMS stocks → Maniforge tenant-scoped tree)

CREATE TABLE IF NOT EXISTS maniforge_wh_stock_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    name_en VARCHAR(255) NULL,
    description TEXT NULL,
    allowed_parents_json JSON NULL,
    data_schema_json JSON NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_wh_stock_types_code (code),
    INDEX idx_wh_stock_types_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_wh_stocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    code VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(64) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    data_json JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_wh_stocks_scope_code (tenant_id, subtenant_id, code),
    INDEX idx_wh_stocks_scope_parent (tenant_id, subtenant_id, parent_id, status),
    INDEX idx_wh_stocks_scope_type (tenant_id, subtenant_id, type, status),
    INDEX idx_wh_stocks_parent (parent_id),
    CONSTRAINT fk_wh_stocks_parent FOREIGN KEY (parent_id) REFERENCES maniforge_wh_stocks (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_wh_stock_types (code, name, name_en, description, allowed_parents_json, data_schema_json, sort_order, active) VALUES
('company', 'Компания', 'Company', 'Корневая организация (опционально)', JSON_ARRAY(), JSON_OBJECT('legal_name', 'string', 'inn', 'string'), 0, 1),
('warehouse', 'Склад (здание)', 'Warehouse', 'Корневой физический склад', JSON_ARRAY(), JSON_OBJECT('city', 'string', 'region', 'string', 'address', 'string', 'area_m2', 'number'), 1, 1),
('zone', 'Зона', 'Zone', 'Зона внутри склада', JSON_ARRAY('warehouse', 'company'), JSON_OBJECT('zone_code', 'string', 'purpose', 'string'), 2, 1),
('rack', 'Стеллаж', 'Rack', 'Стеллаж в зоне', JSON_ARRAY('zone'), JSON_OBJECT('aisle', 'string', 'height_cm', 'number'), 3, 1),
('shelf', 'Полка', 'Shelf', 'Полка на стеллаже', JSON_ARRAY('rack'), JSON_OBJECT('level', 'number', 'weight_limit_kg', 'number'), 4, 1),
('cell', 'Ячейка', 'Cell', 'Ячейка хранения', JSON_ARRAY('shelf'), JSON_OBJECT('barcode', 'string', 'max_weight_kg', 'number'), 5, 1),
('location', 'Локация', 'Location', 'Спец. локация (приёмка, карантин)', JSON_ARRAY('warehouse', 'zone'), JSON_OBJECT('purpose', 'string'), 6, 1),
('production', 'Производство', 'Production', 'Производственная площадка', JSON_ARRAY(), JSON_OBJECT('city', 'string'), 10, 1),
('retail_store', 'Розничный магазин', 'Retail store', 'Точка розницы', JSON_ARRAY(), JSON_OBJECT('city', 'string', 'address', 'string'), 11, 1),
('ozon_fbo', 'Ozon FBO', 'Ozon FBO', 'Склад Ozon FBO', JSON_ARRAY(), JSON_OBJECT('external_warehouse_id', 'string'), 20, 1),
('ozon_fbs', 'Ozon FBS', 'Ozon FBS', 'Склад Ozon FBS', JSON_ARRAY(), JSON_OBJECT('external_warehouse_id', 'string'), 21, 1),
('wildberries_fbo', 'Wildberries FBO', 'WB FBO', 'Склад WB FBO', JSON_ARRAY(), JSON_OBJECT('external_warehouse_id', 'string'), 22, 1),
('wildberries_fbs', 'Wildberries FBS', 'WB FBS', 'Склад WB FBS', JSON_ARRAY(), JSON_OBJECT('external_warehouse_id', 'string'), 23, 1);
