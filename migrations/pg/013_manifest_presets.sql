-- Supply chain manifest presets для default tenant (идемпотентно).
-- См. internal/manifestengine/presets/, docs/MANIFORGE_MANIFEST_ENGINE.md

INSERT INTO maniforge_manifests (
    tenant_id, project_id, code, name, fields_json, metadata_json, updated_at
)
SELECT
    p.tenant_id,
    p.id,
    'product',
    'Product (SKU)',
    '[
        {"name":"code","type":"string","required":true,"max_length":64},
        {"name":"name","type":"string","required":true,"max_length":255},
        {"name":"unit","type":"string","max_length":32},
        {"name":"description","type":"string"},
        {"name":"barcode_ean13","type":"string","max_length":13},
        {"name":"attributes","type":"object"}
    ]'::jsonb,
    '{"preset":"product","module":"supply_chain","php_table":"maniforge_products","php_module":"/products"}'::jsonb,
    NOW()
FROM maniforge_projects p
WHERE p.tenant_id = 'default' AND p.subtenant_id = 'default' AND p.code = 'main'
ON CONFLICT (tenant_id, project_id, code) DO NOTHING;

INSERT INTO maniforge_manifests (
    tenant_id, project_id, code, name, fields_json, metadata_json, updated_at
)
SELECT
    p.tenant_id,
    p.id,
    'stock',
    'Stock node (warehouse)',
    '[
        {"name":"code","type":"string","required":true,"max_length":64},
        {"name":"name","type":"string","required":true,"max_length":255},
        {"name":"type_code","type":"string","max_length":32},
        {"name":"parent_code","type":"string","max_length":64},
        {"name":"description","type":"string"},
        {"name":"status","type":"string","max_length":32}
    ]'::jsonb,
    '{"preset":"stock","module":"supply_chain","php_table":"maniforge_wh_stocks","php_module":"/warehouses"}'::jsonb,
    NOW()
FROM maniforge_projects p
WHERE p.tenant_id = 'default' AND p.subtenant_id = 'default' AND p.code = 'main'
ON CONFLICT (tenant_id, project_id, code) DO NOTHING;
