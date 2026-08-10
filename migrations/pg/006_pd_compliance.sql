-- Персональные данные (152-ФЗ): профиль оператора, цели обработки, согласия пользователей.
-- tenant_id — код коммерческого tenant; consent привязан к user + workspace.

CREATE TABLE IF NOT EXISTS maniforge_pd_operator_profiles (
    tenant_id VARCHAR(100) PRIMARY KEY,                              -- код tenant (= PK)
    operator_name VARCHAR(255) NOT NULL,                             -- наименование оператора ПДн
    operator_inn VARCHAR(12) NULL,                                   -- ИНН оператора
    operator_address TEXT NULL,                                      -- юридический адрес
    dpo_name VARCHAR(255) NULL,                                      -- ФИО ответственного за обработку
    dpo_email VARCHAR(190) NULL,                                     -- email DPO
    dpo_phone VARCHAR(32) NULL,                                      -- телефон DPO
    privacy_policy_url VARCHAR(1024) NULL,                           -- URL политики конфиденциальности
    privacy_policy_version VARCHAR(32) NOT NULL DEFAULT '1.0',       -- версия политики
    data_storage_region VARCHAR(16) NOT NULL DEFAULT 'RU',           -- регион хранения данных
    cross_border_transfer_allowed BOOLEAN NOT NULL DEFAULT FALSE,    -- разрешена ли трансграничная передача
    cross_border_basis TEXT NULL,                                    -- правовое основание трансграничной передачи
    roskomnadzor_notified_at DATE NULL,                              -- дата уведомления Роскомнадзора
    metadata_json JSONB NULL,                                        -- дополнительные реквизиты
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                   -- время создания
    updated_at TIMESTAMPTZ NULL                                      -- время последнего обновления
);

CREATE TABLE IF NOT EXISTS maniforge_pd_processing_purposes (
    id BIGSERIAL PRIMARY KEY,                                        -- суррогатный PK
    tenant_id VARCHAR(100) NOT NULL,                                 -- код tenant
    code VARCHAR(64) NOT NULL,                                       -- код цели (registration, marketing, …)
    title VARCHAR(255) NOT NULL,                                     -- заголовок для UI
    description TEXT NULL,                                           -- развёрнутое описание
    legal_basis VARCHAR(40) NOT NULL DEFAULT 'consent',              -- consent | contract | legal_obligation | …
    retention_days INT NULL,                                         -- срок хранения в днях (NULL = не задан)
    is_mandatory_for_registration BOOLEAN NOT NULL DEFAULT FALSE,    -- обязательна при регистрации
    is_active BOOLEAN NOT NULL DEFAULT TRUE,                         -- доступна для новых согласий
    policy_version VARCHAR(32) NOT NULL DEFAULT '1.0',             -- версия политики цели
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                   -- время создания
    updated_at TIMESTAMPTZ NULL,                                     -- время последнего обновления
    UNIQUE (tenant_id, code)
);

CREATE INDEX IF NOT EXISTS idx_pd_purpose_tenant_active ON maniforge_pd_processing_purposes (tenant_id, is_active);

CREATE TABLE IF NOT EXISTS maniforge_pd_consents (
    id BIGSERIAL PRIMARY KEY,                                              -- суррогатный PK
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE, -- субъект ПДн
    tenant_id VARCHAR(100) NOT NULL,                                       -- код tenant
    subtenant_id VARCHAR(100) NOT NULL,                                    -- код workspace
    purpose_code VARCHAR(64) NOT NULL,                                     -- код цели из maniforge_pd_processing_purposes
    policy_version VARCHAR(32) NOT NULL,                                   -- версия политики на момент согласия
    granted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                         -- момент выдачи согласия
    revoked_at TIMESTAMPTZ NULL,                                           -- момент отзыва (NULL = действует)
    source VARCHAR(64) NOT NULL DEFAULT 'api',                             -- канал (api, registration, admin, …)
    ip_hash VARCHAR(64) NULL,                                              -- SHA-256 IP при выдаче
    user_agent_hash VARCHAR(64) NULL                                       -- SHA-256 User-Agent при выдаче
);

CREATE INDEX IF NOT EXISTS idx_pd_consent_user ON maniforge_pd_consents (user_id, tenant_id, subtenant_id);
CREATE INDEX IF NOT EXISTS idx_pd_consent_purpose ON maniforge_pd_consents (tenant_id, purpose_code);
