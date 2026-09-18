-- Self-service ПДн (152-ФЗ): me.personal_data.* / me.consent.* для субъекта.
-- Порт maniforge/rbac/migrations/025_pd_permissions_seed.sql (MySQL).

INSERT INTO maniforge_permissions (code, description) VALUES
    ('me.personal_data.read', 'Export own personal data package'),
    ('me.consent.read', 'Read own consent records'),
    ('me.consent.manage', 'Grant or revoke own consents'),
    ('me.personal_data.request', 'Create and read own PD subject requests')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'me.personal_data.read',
    'me.consent.read',
    'me.consent.manage',
    'me.personal_data.request'
)
WHERE r.code IN (
    'super_admin', 'tenant_admin', 'subtenant_admin',
    'user', 'moderator', 'support_operator', 'security_auditor'
)
ON CONFLICT DO NOTHING;
