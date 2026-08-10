INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('admin.roles.read', 'Read roles in admin area'),
    ('admin.permissions.read', 'Read permissions in admin area'),
    ('admin.user_roles.read', 'Read user role assignments'),
    ('admin.user_roles.assign', 'Assign role to user'),
    ('admin.user_roles.revoke', 'Revoke role from user'),
    ('admin.user_roles.bulk', 'Batch role mutations'),
    ('admin.user_access.read', 'Read effective roles and permissions for user');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.roles.read',
    'admin.permissions.read',
    'admin.user_roles.read',
    'admin.user_roles.assign',
    'admin.user_roles.revoke',
    'admin.user_roles.bulk',
    'admin.user_access.read'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.roles.read',
    'admin.permissions.read',
    'admin.user_roles.read',
    'admin.user_access.read'
)
WHERE r.code IN ('security_auditor');
