-- Default project main на уровне tenant (subtenant_id = ''), независимо от subtenant-проектов

INSERT INTO maniforge_projects (tenant_id, subtenant_id, code, name, status, is_default, metadata_json)
SELECT t.code, '', 'main', 'Main (tenant)', 'active', 1, JSON_OBJECT('bootstrap', 'migration_035', 'project_scope', 'tenant')
FROM maniforge_tl_tenants t
WHERE t.status = 'active'
  AND NOT EXISTS (
      SELECT 1 FROM maniforge_projects p
      WHERE p.tenant_id = t.code AND p.subtenant_id = '' AND p.code = 'main'
  );

UPDATE maniforge_projects p
INNER JOIN (
    SELECT tenant_id, MIN(id) AS id
    FROM maniforge_projects
    WHERE subtenant_id = '' AND code = 'main'
    GROUP BY tenant_id
) d ON d.id = p.id
SET p.is_default = 1
WHERE p.subtenant_id = '';
