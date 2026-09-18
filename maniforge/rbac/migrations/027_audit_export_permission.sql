INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('admin.audit.export', 'Export audit log for compliance archive');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code = 'admin.audit.export'
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin', 'security_auditor');
