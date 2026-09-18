INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('products.read', 'Read products in session scope'),
    ('products.write', 'Create and update products'),
    ('products.delete', 'Archive products');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'products.read',
    'products.write',
    'products.delete'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code = 'products.read'
WHERE r.code IN ('user', 'moderator', 'support_operator');
