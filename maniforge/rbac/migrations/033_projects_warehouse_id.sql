-- Default warehouse (maniforge_wh_stocks root node) for tenant/subtenant project

ALTER TABLE maniforge_projects
    ADD COLUMN warehouse_id BIGINT UNSIGNED NULL AFTER metadata_json,
    ADD INDEX idx_projects_warehouse (warehouse_id),
    ADD CONSTRAINT fk_projects_warehouse
        FOREIGN KEY (warehouse_id) REFERENCES maniforge_wh_stocks (id) ON DELETE SET NULL;
