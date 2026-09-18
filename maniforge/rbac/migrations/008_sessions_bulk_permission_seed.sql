INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('admin.sessions.bulk', 'Batch revoke sessions in admin area');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('admin.sessions.bulk')
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin', 'security_auditor');
