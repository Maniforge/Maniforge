-- Bootstrap seed для dev/первого запуска: starter plan, default tenant/workspace, main project.
-- Идемпотентно (ON CONFLICT DO NOTHING); безопасно повторять при migrate.

INSERT INTO maniforge_tl_license_plans (code, name, features_json, limits_json)
VALUES (
    'starter',
    'Starter',
    '{"rbac": true, "admin_api": true}'::jsonb,
    '{"max_users": 100, "max_sessions": 500}'::jsonb
)
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_tl_tenants (code, name, status)
VALUES ('default', 'Default tenant', 'active')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_tl_subtenants (tenant_id, tenant_code, code, name, status)
SELECT id, code, 'default', 'Default subtenant', 'active'
FROM maniforge_tl_tenants
WHERE code = 'default'
ON CONFLICT (tenant_code, code) DO NOTHING;

INSERT INTO maniforge_tl_tenant_licenses (tenant_id, tenant_code, plan_code, status)
SELECT t.id, t.code, 'starter', 'active'
FROM maniforge_tl_tenants t
WHERE t.code = 'default'
  AND NOT EXISTS (
      SELECT 1 FROM maniforge_tl_tenant_licenses l
      WHERE l.tenant_code = t.code AND l.status = 'active'
  );

INSERT INTO maniforge_projects (tenant_id, subtenant_id, code, name, status)
VALUES ('default', 'default', 'main', 'Main project', 'active')
ON CONFLICT (tenant_id, subtenant_id, code) DO NOTHING;
