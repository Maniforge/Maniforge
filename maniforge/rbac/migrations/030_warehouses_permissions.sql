INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('warehouses.read', 'Read warehouse tree and stock nodes in session scope'),
    ('warehouses.write', 'Create and update warehouse nodes'),
    ('warehouses.delete', 'Archive warehouse nodes'),
    ('warehouses.types.read', 'Read stock type catalog');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'warehouses.read',
    'warehouses.write',
    'warehouses.delete',
    'warehouses.types.read'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'warehouses.read',
    'warehouses.types.read'
)
WHERE r.code IN ('user', 'moderator', 'support_operator');
