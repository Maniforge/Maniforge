-- Control plane catalog + manifest origin (platform vs custom).
-- См. docs/adr/0009-control-data-plane-manifest-origin.md

-- Палитра типов полей для конструктора (control plane).
CREATE TABLE IF NOT EXISTS maniforge_field_type_catalog (
    code VARCHAR(40) PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    description TEXT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'basic',
    supports_items BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO maniforge_field_type_catalog (code, label, description, category, supports_items, sort_order) VALUES
    ('string', 'Строка', 'Короткий текст', 'basic', false, 10),
    ('text', 'Текст', 'Длинный текст', 'basic', false, 20),
    ('number', 'Число', 'Целое или дробное', 'basic', false, 30),
    ('boolean', 'Да/Нет', 'Логическое значение', 'basic', false, 40),
    ('date', 'Дата', 'Календарная дата', 'basic', false, 50),
    ('datetime', 'Дата и время', 'Метка времени', 'basic', false, 60),
    ('array', 'Массив', 'Список значений', 'structure', true, 70),
    ('object', 'Объект', 'Вложенная структура', 'structure', false, 80),
    ('select', 'Выбор', 'Enum / справочник', 'basic', false, 90),
    ('link', 'Ссылка', 'Ссылка на другой manifest (DocType)', 'relation', false, 100),
    ('table', 'Таблица', 'Child-таблица (вложенный DocType)', 'structure', true, 110),
    ('currency', 'Валюта', 'Денежная сумма', 'basic', false, 120)
ON CONFLICT (code) DO NOTHING;

-- platform = внутренние (preset); custom = пользовательские.
ALTER TABLE maniforge_manifests
    ADD COLUMN IF NOT EXISTS origin VARCHAR(20) NOT NULL DEFAULT 'custom';

CREATE INDEX IF NOT EXISTS idx_manifests_origin
    ON maniforge_manifests (tenant_id, project_id, origin, status);

UPDATE maniforge_manifests
SET origin = 'platform'
WHERE origin = 'custom'
  AND (
    metadata_json->>'preset' IS NOT NULL
    OR code IN ('product', 'stock')
  );
