-- Products + Inventory — порт maniforge/rbac/migrations/037–040, 043, 045, 046.

CREATE TABLE IF NOT EXISTS maniforge_products (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT NULL REFERENCES maniforge_projects(id) ON DELETE RESTRICT,
    scope_visibility VARCHAR(20) NOT NULL DEFAULT 'project',
    shared_subtenant_ids_json JSONB NULL,
    shared_grant_tenant_ids_json JSONB NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    unit VARCHAR(32) NOT NULL DEFAULT 'pcs',
    description TEXT NULL,
    attributes_json JSONB NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    updated_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL,
    UNIQUE (tenant_id, subtenant_id, project_id, code)
);

CREATE INDEX IF NOT EXISTS idx_products_scope_status
    ON maniforge_products (tenant_id, subtenant_id, project_id, status);

CREATE TABLE IF NOT EXISTS maniforge_inv_balances (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    product_id BIGINT NOT NULL REFERENCES maniforge_products(id) ON DELETE RESTRICT,
    stock_id BIGINT NOT NULL REFERENCES maniforge_wh_stocks(id) ON DELETE RESTRICT,
    qty NUMERIC(18, 6) NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, product_id, stock_id)
);

CREATE INDEX IF NOT EXISTS idx_inv_balance_product ON maniforge_inv_balances (tenant_id, product_id);
CREATE INDEX IF NOT EXISTS idx_inv_balance_stock ON maniforge_inv_balances (tenant_id, stock_id);

CREATE TABLE IF NOT EXISTS maniforge_inv_movements (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT NULL REFERENCES maniforge_projects(id) ON DELETE RESTRICT,
    scope_visibility VARCHAR(20) NOT NULL DEFAULT 'project',
    shared_subtenant_ids_json JSONB NULL,
    shared_grant_tenant_ids_json JSONB NULL,
    doc_number VARCHAR(64) NOT NULL,
    movement_type VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'posted',
    note TEXT NULL,
    metadata_json JSONB NULL,
    created_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    posted_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    posted_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL,
    UNIQUE (tenant_id, subtenant_id, project_id, doc_number)
);

CREATE INDEX IF NOT EXISTS idx_inv_movements_scope
    ON maniforge_inv_movements (tenant_id, subtenant_id, project_id, status, posted_at);

CREATE TABLE IF NOT EXISTS maniforge_inv_movement_lines (
    id BIGSERIAL PRIMARY KEY,
    movement_id BIGINT NOT NULL REFERENCES maniforge_inv_movements(id) ON DELETE RESTRICT,
    line_no INT NOT NULL DEFAULT 1,
    product_id BIGINT NOT NULL REFERENCES maniforge_products(id) ON DELETE RESTRICT,
    stock_id BIGINT NOT NULL REFERENCES maniforge_wh_stocks(id) ON DELETE RESTRICT,
    qty_delta NUMERIC(18, 6) NOT NULL,
    pack_unit_id BIGINT NULL,
    marking_code_id BIGINT NULL,
    batch_code VARCHAR(64) NULL,
    lot_code VARCHAR(64) NULL,
    lot_id BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_inv_lines_movement ON maniforge_inv_movement_lines (movement_id);
CREATE INDEX IF NOT EXISTS idx_inv_lines_product_stock ON maniforge_inv_movement_lines (product_id, stock_id);

CREATE TABLE IF NOT EXISTS maniforge_inv_reserves (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    product_id BIGINT NOT NULL REFERENCES maniforge_products(id) ON DELETE RESTRICT,
    stock_id BIGINT NOT NULL REFERENCES maniforge_wh_stocks(id) ON DELETE RESTRICT,
    qty NUMERIC(18, 6) NOT NULL,
    ref_code VARCHAR(64) NOT NULL,
    note VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    released_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    released_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS idx_inv_reserves_pair
    ON maniforge_inv_reserves (tenant_id, product_id, stock_id, status);
CREATE INDEX IF NOT EXISTS idx_inv_reserves_ref
    ON maniforge_inv_reserves (tenant_id, ref_code, status);

CREATE TABLE IF NOT EXISTS maniforge_inv_orders (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    order_number VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    stock_id BIGINT NOT NULL REFERENCES maniforge_wh_stocks(id) ON DELETE RESTRICT,
    note TEXT NULL,
    metadata_json JSONB NULL,
    created_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    confirmed_at TIMESTAMPTZ NULL,
    fulfilled_at TIMESTAMPTZ NULL,
    cancelled_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL,
    UNIQUE (tenant_id, order_number)
);

CREATE INDEX IF NOT EXISTS idx_inv_orders_status
    ON maniforge_inv_orders (tenant_id, status, created_at);

CREATE TABLE IF NOT EXISTS maniforge_inv_order_lines (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES maniforge_inv_orders(id) ON DELETE CASCADE,
    line_no INT NOT NULL DEFAULT 1,
    product_id BIGINT NOT NULL REFERENCES maniforge_products(id) ON DELETE RESTRICT,
    qty_ordered NUMERIC(18, 6) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (order_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_inv_order_lines_product ON maniforge_inv_order_lines (product_id);

CREATE TABLE IF NOT EXISTS maniforge_inv_lots (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    product_id BIGINT NOT NULL REFERENCES maniforge_products(id) ON DELETE RESTRICT,
    batch_code VARCHAR(64) NOT NULL DEFAULT '',
    lot_code VARCHAR(64) NOT NULL DEFAULT '',
    manufactured_at DATE NULL,
    expires_at DATE NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    note VARCHAR(255) NULL,
    created_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL,
    UNIQUE (tenant_id, product_id, batch_code, lot_code)
);

CREATE INDEX IF NOT EXISTS idx_inv_lots_product ON maniforge_inv_lots (tenant_id, product_id, status);

ALTER TABLE maniforge_inv_movement_lines
    ADD CONSTRAINT fk_inv_lines_lot FOREIGN KEY (lot_id) REFERENCES maniforge_inv_lots(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_inv_lines_lot ON maniforge_inv_movement_lines (lot_id);

INSERT INTO maniforge_permissions (code, description) VALUES
    ('products.read', 'Read products in session scope'),
    ('products.write', 'Create and update products'),
    ('products.delete', 'Archive products'),
    ('inventory.read', 'Read inventory balances and movements'),
    ('inventory.write', 'Post inventory movements and manage reserves')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'products.read', 'products.write', 'products.delete',
    'inventory.read', 'inventory.write'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('products.read', 'inventory.read')
WHERE r.code IN ('user', 'support_operator')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_products', 'Товары (номенклатура)', 'Создание и обновление SKU в scope tenant/project'),
    ('maniforge_inv_movements', 'Складские движения', 'Приход, расход, перемещение, корректировка остатков'),
    ('maniforge_inv_reserves', 'Резервы остатков', 'Блокировка qty под ref_code до release'),
    ('maniforge_inv_orders', 'Складские заказы', 'Заявки на отгрузку с резервированием'),
    ('maniforge_inv_lots', 'Партии товара', 'Batch/lot регистр для движений')
ON CONFLICT (entity_table) DO NOTHING;
