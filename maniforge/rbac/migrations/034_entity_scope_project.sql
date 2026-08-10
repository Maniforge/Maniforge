-- Entity scope: tenant → subtenant → project (default code=main) + visibility (project|subtenant|tenant)

ALTER TABLE maniforge_projects
    ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD INDEX idx_projects_default (tenant_id, subtenant_id, is_default);

ALTER TABLE maniforge_wh_stocks
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER subtenant_id,
    ADD COLUMN scope_visibility VARCHAR(20) NOT NULL DEFAULT 'project' AFTER project_id,
    ADD COLUMN shared_subtenant_ids_json JSON NULL AFTER scope_visibility,
    ADD INDEX idx_wh_stocks_project (tenant_id, subtenant_id, project_id, status),
    ADD CONSTRAINT fk_wh_stocks_project FOREIGN KEY (project_id) REFERENCES maniforge_projects (id) ON DELETE RESTRICT;

-- Проект main для каждого subtenant tenant
INSERT INTO maniforge_projects (tenant_id, subtenant_id, code, name, status, is_default, metadata_json)
SELECT s.tenant_code, s.code, 'main', 'Main', 'active', 1, JSON_OBJECT('bootstrap', 'migration_034')
FROM maniforge_tl_subtenants s
WHERE s.status = 'active'
  AND NOT EXISTS (
      SELECT 1 FROM maniforge_projects p
      WHERE p.tenant_id = s.tenant_code AND p.subtenant_id = s.code AND p.code = 'main'
  );

UPDATE maniforge_projects p
INNER JOIN (
    SELECT tenant_id, subtenant_id, MIN(id) AS id
    FROM maniforge_projects
    WHERE code = 'main' AND subtenant_id <> ''
    GROUP BY tenant_id, subtenant_id
) d ON d.id = p.id
SET p.is_default = 1;

-- Существующие склады → default project subtenant
UPDATE maniforge_wh_stocks s
INNER JOIN maniforge_projects p
    ON p.tenant_id = s.tenant_id
   AND p.subtenant_id = s.subtenant_id
   AND p.is_default = 1
SET s.project_id = p.id,
    s.scope_visibility = 'project'
WHERE s.project_id IS NULL;

ALTER TABLE maniforge_wh_stocks
    DROP INDEX uk_wh_stocks_scope_code;

ALTER TABLE maniforge_wh_stocks
    ADD UNIQUE KEY uk_wh_stocks_scope_code (tenant_id, subtenant_id, project_id, code);
