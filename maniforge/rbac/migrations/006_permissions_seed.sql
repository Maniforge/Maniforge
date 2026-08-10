INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('admin.users.read', 'Read users in admin area'),
    ('admin.sessions.read', 'Read sessions in admin area'),
    ('admin.sessions.revoke', 'Revoke sessions in admin area'),
    ('admin.sessions.bulk', 'Batch revoke sessions in admin area'),
    ('admin.audit.read', 'Read audit log'),
    ('admin.security_events.read', 'Read security events'),
    ('admin.roles.read', 'Read roles in admin area'),
    ('admin.permissions.read', 'Read permissions in admin area'),
    ('admin.user_roles.read', 'Read user role assignments'),
    ('admin.user_roles.assign', 'Assign role to user'),
    ('admin.user_roles.revoke', 'Revoke role from user');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.users.read',
    'admin.sessions.read',
    'admin.sessions.revoke',
    'admin.sessions.bulk',
    'admin.audit.read',
    'admin.security_events.read',
    'admin.roles.read',
    'admin.permissions.read',
    'admin.user_roles.read',
    'admin.user_roles.assign',
    'admin.user_roles.revoke'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.users.read',
    'admin.sessions.read',
    'admin.sessions.bulk',
    'admin.audit.read',
    'admin.security_events.read',
    'admin.roles.read',
    'admin.permissions.read',
    'admin.user_roles.read'
)
WHERE r.code IN ('security_auditor');
