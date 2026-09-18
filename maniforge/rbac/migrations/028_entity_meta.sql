-- Сводная таблица идентификаторов: внутренний сервис (i_index/i_id) ↔ внешний ключ (meta + type).
-- Пример: meta=+79001234567, type=phone, i_index=1 (user), i_id=<user_id>
-- Пример: meta=<productId>, type=wildberries, i_index=3 (product), o_index=2

CREATE TABLE IF NOT EXISTS maniforge_entity_meta (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL DEFAULT '',
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    meta VARCHAR(255) NOT NULL,
    type VARCHAR(64) NOT NULL,
    i_index TINYINT UNSIGNED NOT NULL,
    i_id BIGINT UNSIGNED NOT NULL,
    o_index TINYINT UNSIGNED NULL,
    o_ref VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_entity_meta_scope (tenant_id, subtenant_id, type, meta, i_index),
    INDEX idx_entity_meta_internal (i_index, i_id),
    INDEX idx_entity_meta_lookup (type, meta),
    INDEX idx_entity_meta_tenant_type (tenant_id, subtenant_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
