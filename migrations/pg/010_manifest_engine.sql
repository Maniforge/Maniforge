-- Manifest Engine: описание сущностей и JSONB-записи в scope tenant + project.
-- См. docs/MANIFORGE_MANIFEST_ENGINE.md

-- Схема сущности (fields_json) в рамках tenant + project.
CREATE TABLE IF NOT EXISTS maniforge_manifests (
    id BIGSERIAL PRIMARY KEY,                                                    -- суррогатный PK
    tenant_id VARCHAR(100) NOT NULL,                                             -- код tenant
    project_id BIGINT NOT NULL REFERENCES maniforge_projects(id) ON DELETE CASCADE, -- FK проекта
    code VARCHAR(100) NOT NULL,                                                  -- уникальный код manifest в project
    name VARCHAR(255) NOT NULL,                                                  -- отображаемое имя
    version INT NOT NULL DEFAULT 1,                                              -- версия схемы fields_json
    status VARCHAR(30) NOT NULL DEFAULT 'active',                                -- active | archived
    fields_json JSONB NOT NULL DEFAULT '[]'::jsonb,                              -- описание полей сущности
    metadata_json JSONB NULL,                                                    -- доп. атрибуты manifest
    created_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,    -- автор создания
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                               -- время создания
    updated_at TIMESTAMPTZ NULL,                                                 -- время последнего обновления
    UNIQUE (tenant_id, project_id, code)
);

CREATE INDEX IF NOT EXISTS idx_manifests_scope ON maniforge_manifests (tenant_id, project_id, status);

-- Экземпляры записей manifest; data_json — GIN-индекс для фильтрации по полям.
CREATE TABLE IF NOT EXISTS maniforge_manifest_records (
    id BIGSERIAL PRIMARY KEY,                                                    -- суррогатный PK
    manifest_id BIGINT NOT NULL REFERENCES maniforge_manifests(id) ON DELETE CASCADE, -- FK схемы
    tenant_id VARCHAR(100) NOT NULL,                                             -- денормализация tenant для scope-запросов
    project_id BIGINT NOT NULL,                                                  -- денормализация project для scope-запросов
    data_json JSONB NOT NULL DEFAULT '{}'::jsonb,                                -- значения полей записи
    created_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,    -- автор создания
    updated_by BIGINT NULL REFERENCES maniforge_users(id) ON DELETE SET NULL,    -- автор последнего изменения
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                               -- время создания
    updated_at TIMESTAMPTZ NULL                                                  -- время последнего обновления
);

CREATE INDEX IF NOT EXISTS idx_manifest_records_manifest ON maniforge_manifest_records (manifest_id);
CREATE INDEX IF NOT EXISTS idx_manifest_records_scope ON maniforge_manifest_records (tenant_id, project_id);
CREATE INDEX IF NOT EXISTS idx_manifest_records_data ON maniforge_manifest_records USING GIN (data_json);
