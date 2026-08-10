INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('me.personal_data.read', 'Export own personal data package'),
    ('me.consent.read', 'Read own consent records'),
    ('me.consent.manage', 'Grant or revoke own consents'),
    ('me.personal_data.request', 'Create and read own PD subject requests'),
    ('admin.pd.operator.read', 'Read tenant PD operator profile'),
    ('admin.pd.operator.write', 'Update tenant PD operator profile'),
    ('admin.pd.purposes.read', 'Read processing purposes'),
    ('admin.pd.purposes.write', 'Manage processing purposes'),
    ('admin.pd.requests.read', 'Read subject PD requests in tenant'),
    ('admin.pd.requests.handle', 'Resolve subject PD requests');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'me.personal_data.read',
    'me.consent.read',
    'me.consent.manage',
    'me.personal_data.request'
)
WHERE r.code IN ('tenant_admin', 'subtenant_admin', 'user', 'moderator', 'support_operator', 'security_auditor');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.pd.operator.read',
    'admin.pd.operator.write',
    'admin.pd.purposes.read',
    'admin.pd.purposes.write',
    'admin.pd.requests.read',
    'admin.pd.requests.handle',
    'me.personal_data.read',
    'me.consent.read',
    'me.consent.manage',
    'me.personal_data.request'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.pd.operator.read',
    'admin.pd.purposes.read',
    'admin.pd.requests.read',
    'me.personal_data.read',
    'me.consent.read',
    'me.personal_data.request'
)
WHERE r.code = 'security_auditor';
