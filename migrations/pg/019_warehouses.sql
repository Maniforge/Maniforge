-- Warehouses module (WMS stock tree) — порт maniforge/rbac/migrations/029–036, 033.
-- RBAC licensing runtime — через licensingclient; stocks — tenant + project scope.

CREATE TABLE IF NOT EXISTS maniforge_wh_stock_types (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    name_en VARCHAR(255) NULL,
    description TEXT NULL,
    allowed_parents_json JSONB NULL,
    data_schema_json JSONB NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_wh_stock_types_active ON maniforge_wh_stock_types (active, sort_order);

CREATE TABLE IF NOT EXISTS maniforge_wh_stocks (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT NULL REFERENCES maniforge_projects(id) ON DELETE RESTRICT,
    scope_visibility VARCHAR(20) NOT NULL DEFAULT 'project',
    shared_subtenant_ids_json JSONB NULL,
    shared_grant_tenant_ids_json JSONB NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(64) NOT NULL,
    parent_id BIGINT NULL REFERENCES maniforge_wh_stocks(id) ON DELETE RESTRICT,
    data_json JSONB NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    updated_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL,
    UNIQUE (tenant_id, subtenant_id, project_id, code)
);

CREATE INDEX IF NOT EXISTS idx_wh_stocks_scope_parent
    ON maniforge_wh_stocks (tenant_id, subtenant_id, parent_id, status);
CREATE INDEX IF NOT EXISTS idx_wh_stocks_scope_type
    ON maniforge_wh_stocks (tenant_id, subtenant_id, type, status);
CREATE INDEX IF NOT EXISTS idx_wh_stocks_project
    ON maniforge_wh_stocks (tenant_id, subtenant_id, project_id, status);

ALTER TABLE maniforge_projects
    ADD COLUMN IF NOT EXISTS warehouse_id BIGINT NULL REFERENCES maniforge_wh_stocks(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_projects_warehouse ON maniforge_projects (warehouse_id);

INSERT INTO maniforge_wh_stock_types (code, name, name_en, description, allowed_parents_json, data_schema_json, sort_order, active) VALUES
    ('company', 'Компания', 'Company', 'Корневая организация (опционально)', '[]'::jsonb, '{"legal_name":"string","inn":"string"}'::jsonb, 0, TRUE),
    ('warehouse', 'Склад (здание)', 'Warehouse', 'Корневой физический склад', '[]'::jsonb, '{"city":"string","region":"string","address":"string","area_m2":"number"}'::jsonb, 1, TRUE),
    ('zone', 'Зона', 'Zone', 'Зона внутри склада', '["warehouse","company"]'::jsonb, '{"zone_code":"string","purpose":"string"}'::jsonb, 2, TRUE),
    ('rack', 'Стеллаж', 'Rack', 'Стеллаж в зоне', '["zone"]'::jsonb, '{"aisle":"string","height_cm":"number"}'::jsonb, 3, TRUE),
    ('shelf', 'Полка', 'Shelf', 'Полка на стеллаже', '["rack"]'::jsonb, '{"level":"number","weight_limit_kg":"number"}'::jsonb, 4, TRUE),
    ('cell', 'Ячейка', 'Cell', 'Ячейка хранения', '["shelf"]'::jsonb, '{"barcode":"string","max_weight_kg":"number"}'::jsonb, 5, TRUE),
    ('location', 'Локация', 'Location', 'Спец. локация (приёмка, карантин)', '["warehouse","zone"]'::jsonb, '{"purpose":"string"}'::jsonb, 6, TRUE),
    ('production', 'Производство', 'Production', 'Производственная площадка', '[]'::jsonb, '{"city":"string"}'::jsonb, 10, TRUE),
    ('retail_store', 'Розничный магазин', 'Retail store', 'Точка розницы', '[]'::jsonb, '{"city":"string","address":"string"}'::jsonb, 11, TRUE),
    ('ozon_fbo', 'Ozon FBO', 'Ozon FBO', 'Склад Ozon FBO', '[]'::jsonb, '{"external_warehouse_id":"string"}'::jsonb, 20, TRUE),
    ('ozon_fbs', 'Ozon FBS', 'Ozon FBS', 'Склад Ozon FBS', '[]'::jsonb, '{"external_warehouse_id":"string"}'::jsonb, 21, TRUE),
    ('wildberries_fbo', 'Wildberries FBO', 'WB FBO', 'Склад WB FBO', '[]'::jsonb, '{"external_warehouse_id":"string"}'::jsonb, 22, TRUE),
    ('wildberries_fbs', 'Wildberries FBS', 'WB FBS', 'Склад WB FBS', '[]'::jsonb, '{"external_warehouse_id":"string"}'::jsonb, 23, TRUE)
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_permissions (code, description) VALUES
    ('warehouses.read', 'Read warehouse tree and stock nodes in session scope'),
    ('warehouses.write', 'Create and update warehouse nodes'),
    ('warehouses.delete', 'Archive warehouse nodes'),
    ('warehouses.types.read', 'Read stock type catalog'),
    ('warehouses.audit.read', 'Read warehouse stock audit trail in session scope')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'warehouses.read', 'warehouses.write', 'warehouses.delete', 'warehouses.types.read', 'warehouses.audit.read'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('warehouses.read', 'warehouses.types.read')
WHERE r.code IN ('user', 'support_operator')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_wh_stocks', 'Склады (узлы WMS)', 'Создание, перемещение в дереве, архивация складских узлов')
ON CONFLICT (entity_table) DO NOTHING;
