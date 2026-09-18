-- WMS packaging/marking + product EAN-13 (порт PHP 041/042/044).

ALTER TABLE maniforge_products
    ADD COLUMN IF NOT EXISTS barcode_ean13 CHAR(13) NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uk_products_ean13
    ON maniforge_products (tenant_id, barcode_ean13)
    WHERE barcode_ean13 IS NOT NULL;

CREATE TABLE IF NOT EXISTS maniforge_wms_pack_units (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    project_id BIGINT NULL REFERENCES maniforge_projects(id) ON DELETE RESTRICT,
    scope_visibility VARCHAR(20) NOT NULL DEFAULT 'project',
    shared_subtenant_ids_json JSONB NULL,
    shared_grant_tenant_ids_json JSONB NULL,
    unit_type VARCHAR(32) NOT NULL,
    code VARCHAR(64) NOT NULL,
    sscc VARCHAR(20) NULL,
    qr_payload TEXT NULL,
    qr_lookup VARCHAR(255) NOT NULL,
    stock_id BIGINT NULL REFERENCES maniforge_wh_stocks(id) ON DELETE SET NULL,
    product_id BIGINT NULL REFERENCES maniforge_products(id) ON DELETE SET NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    qty_capacity NUMERIC(18, 6) NULL,
    uom VARCHAR(32) NOT NULL DEFAULT 'pcs',
    sealed_at TIMESTAMPTZ NULL,
    sealed_by BIGINT NULL,
    metadata_json JSONB NULL,
    created_by BIGINT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL,
    UNIQUE (tenant_id, subtenant_id, project_id, code)
);

CREATE UNIQUE INDEX IF NOT EXISTS uk_wms_pack_sscc
    ON maniforge_wms_pack_units (tenant_id, sscc)
    WHERE sscc IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uk_wms_pack_qr_lookup
    ON maniforge_wms_pack_units (tenant_id, qr_lookup);
CREATE INDEX IF NOT EXISTS idx_wms_pack_scope
    ON maniforge_wms_pack_units (tenant_id, subtenant_id, project_id, unit_type, status);

CREATE TABLE IF NOT EXISTS maniforge_wms_marking_codes (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    product_id BIGINT NOT NULL REFERENCES maniforge_products(id) ON DELETE RESTRICT,
    code_full VARCHAR(255) NOT NULL,
    code_type VARCHAR(32) NOT NULL DEFAULT 'kiz',
    gtin VARCHAR(14) NULL,
    serial_number VARCHAR(50) NULL,
    crypto_tail VARCHAR(44) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'available',
    pack_unit_id BIGINT NULL REFERENCES maniforge_wms_pack_units(id) ON DELETE SET NULL,
    parent_marking_id BIGINT NULL REFERENCES maniforge_wms_marking_codes(id) ON DELETE SET NULL,
    stock_id BIGINT NULL REFERENCES maniforge_wh_stocks(id) ON DELETE SET NULL,
    metadata_json JSONB NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NULL,
    UNIQUE (tenant_id, code_full)
);

CREATE INDEX IF NOT EXISTS idx_wms_marking_product
    ON maniforge_wms_marking_codes (tenant_id, product_id, status);
CREATE INDEX IF NOT EXISTS idx_wms_marking_pack
    ON maniforge_wms_marking_codes (pack_unit_id);

CREATE TABLE IF NOT EXISTS maniforge_wms_pack_contents (
    id BIGSERIAL PRIMARY KEY,
    parent_pack_unit_id BIGINT NOT NULL REFERENCES maniforge_wms_pack_units(id) ON DELETE RESTRICT,
    line_no INT NOT NULL DEFAULT 1,
    child_pack_unit_id BIGINT NULL REFERENCES maniforge_wms_pack_units(id) ON DELETE RESTRICT,
    marking_code_id BIGINT NULL REFERENCES maniforge_wms_marking_codes(id) ON DELETE RESTRICT,
    qty NUMERIC(18, 6) NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_wms_contents_target CHECK (
        (child_pack_unit_id IS NOT NULL AND marking_code_id IS NULL)
        OR (child_pack_unit_id IS NULL AND marking_code_id IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_wms_pack_contents_parent ON maniforge_wms_pack_contents (parent_pack_unit_id);

INSERT INTO maniforge_permissions (code, description) VALUES
    ('wms.read', 'Read WMS packaging, marking, scan'),
    ('wms.write', 'Manage WMS packs, markings, seal, scan movements')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('wms.read', 'wms.write', 'inventory.read', 'inventory.write')
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('wms.read', 'inventory.read')
WHERE r.code IN ('user', 'moderator', 'support_operator')
ON CONFLICT DO NOTHING;

INSERT INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_wms_pack_units', 'WMS упаковки', 'Паллеты, групповые упаковки, SSCC/QR'),
    ('maniforge_wms_marking_codes', 'WMS маркировка', 'КИЗ и коды идентификации')
ON CONFLICT (entity_table) DO NOTHING;
