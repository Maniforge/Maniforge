-- Реестр применённых SQL-миграций PostgreSQL.
-- Создаётся первой; cmd/migrate записывает version = имя файла без расширения.

CREATE TABLE IF NOT EXISTS maniforge_migrations (
    id BIGSERIAL PRIMARY KEY,              -- суррогатный PK
    version VARCHAR(64) NOT NULL UNIQUE,   -- имя файла миграции без расширения
    executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW() -- момент успешного применения
);
