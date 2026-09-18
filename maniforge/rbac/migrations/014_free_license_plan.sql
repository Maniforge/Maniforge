-- Free tier: 1 tenant scope, no subtenants, up to 10 users
INSERT IGNORE INTO maniforge_tl_license_plans (code, name, status, features_json, limits_json)
VALUES (
    'free',
    'Free',
    'active',
    JSON_OBJECT('rbac', true, 'admin_api', true, 'tenant_admin', true),
    JSON_OBJECT(
        'max_tenants', 1,
        'max_subtenants', 0,
        'max_users', 10,
        'max_sessions', 50
    )
);
