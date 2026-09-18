INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('warehouses.audit.read', 'Read warehouse stock audit trail in session scope');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code = 'warehouses.audit.read'
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin', 'moderator', 'support_operator');
