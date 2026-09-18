INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('projects.read', 'List and read projects in session scope'),
    ('projects.create', 'Create projects in session scope'),
    ('projects.update', 'Update projects in session scope'),
    ('scope_variables.read', 'Read scope variables visible in session'),
    ('scope_variables.create', 'Create scope variables in session scope'),
    ('scope_variables.update', 'Update scope variables in session scope');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'projects.read',
    'projects.create',
    'projects.update',
    'scope_variables.read',
    'scope_variables.create',
    'scope_variables.update'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'projects.read',
    'scope_variables.read'
)
WHERE r.code IN ('user', 'moderator', 'support_operator');
