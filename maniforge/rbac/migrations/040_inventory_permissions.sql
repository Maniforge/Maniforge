INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('inventory.read', 'Read inventory balances and movements'),
    ('inventory.write', 'Post inventory movements');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('inventory.read', 'inventory.write')
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code = 'inventory.read'
WHERE r.code IN ('user', 'moderator', 'support_operator');
