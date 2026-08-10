-- Tenant/Licensing Service: tenant lifecycle, workspace (subtenant), планы, лицензии, квоты, события.
-- RBAC проверяет доступ только через licensingclient (access-state), не по TL-таблицам напрямую.
-- Порт PHP: maniforge/rbac/migrations/013_tenant_licensing_service.sql

-- Коммерческий tenant (клиент/реферал — managed tenant + grant, ось A).
CREATE TABLE IF NOT EXISTS maniforge_tl_tenants (
    id BIGSERIAL PRIMARY KEY,                        -- суррогатный PK
    code VARCHAR(100) NOT NULL UNIQUE,               -- уникальный код tenant (slug)
    name VARCHAR(255) NOT NULL,                       -- отображаемое имя
    status VARCHAR(30) NOT NULL DEFAULT 'active',    -- active | suspended | archived
    entity_type VARCHAR(20) NOT NULL DEFAULT 'legal', -- legal | individual | internal
    suspended_at TIMESTAMPTZ NULL,                   -- момент приостановки (NULL если active)
    metadata_json JSONB NULL,                        -- произвольные атрибуты tenant
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),   -- время создания
    updated_at TIMESTAMPTZ NULL                      -- время последнего обновления
);

CREATE INDEX IF NOT EXISTS idx_tl_tenants_status ON maniforge_tl_tenants (status);

-- Workspace внутри tenant (ось B). code совпадает с subtenant_id в RBAC; это не managed client.
CREATE TABLE IF NOT EXISTS maniforge_tl_subtenants (
    id BIGSERIAL PRIMARY KEY,                                          -- суррогатный PK
    tenant_id BIGINT NOT NULL REFERENCES maniforge_tl_tenants(id) ON DELETE CASCADE, -- FK на tenant
    tenant_code VARCHAR(100) NOT NULL,                                 -- денормализация code tenant для запросов без JOIN
    code VARCHAR(100) NOT NULL,                                        -- код workspace (= subtenant_id в RBAC)
    name VARCHAR(255) NOT NULL,                                        -- отображаемое имя workspace
    status VARCHAR(30) NOT NULL DEFAULT 'active',                      -- active | suspended | archived
    metadata_json JSONB NULL,                                          -- произвольные атрибуты workspace
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                       -- время создания
    updated_at TIMESTAMPTZ NULL,                                       -- время последнего обновления
    UNIQUE (tenant_code, code)
);

CREATE INDEX IF NOT EXISTS idx_tl_subtenants_status ON maniforge_tl_subtenants (status);

-- Каталог тарифных планов: features_json и limits_json — entitlements для licensingclient.
CREATE TABLE IF NOT EXISTS maniforge_tl_license_plans (
    id BIGSERIAL PRIMARY KEY,                      -- суррогатный PK
    code VARCHAR(100) NOT NULL UNIQUE,             -- код плана (starter, pro, …)
    name VARCHAR(255) NOT NULL,                    -- отображаемое имя плана
    status VARCHAR(30) NOT NULL DEFAULT 'active',  -- active | deprecated
    features_json JSONB NOT NULL DEFAULT '{}',   -- включённые фичи (rbac, admin_api, …)
    limits_json JSONB NOT NULL DEFAULT '{}',       -- лимиты (max_users, max_sessions, …)
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), -- время создания
    updated_at TIMESTAMPTZ NULL                    -- время последнего обновления
);

-- Назначенная лицензия tenant ↔ plan. Не более одной active на tenant — см. 009_tl_one_active_license.sql.
CREATE TABLE IF NOT EXISTS maniforge_tl_tenant_licenses (
    id BIGSERIAL PRIMARY KEY,                                                    -- суррогатный PK
    tenant_id BIGINT NOT NULL REFERENCES maniforge_tl_tenants(id) ON DELETE CASCADE, -- FK на tenant
    tenant_code VARCHAR(100) NOT NULL,                                           -- денормализация code tenant
    plan_code VARCHAR(100) NOT NULL,                                             -- код плана из maniforge_tl_license_plans
    status VARCHAR(30) NOT NULL DEFAULT 'active',                                -- active | revoked | expired
    starts_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                                -- начало действия лицензии
    expires_at TIMESTAMPTZ NULL,                                                 -- окончание (NULL = бессрочно)
    seats_max INT NULL,                                                          -- макс. число пользователей (NULL = без лимита)
    assigned_by VARCHAR(120) NULL,                                               -- кто назначил (login или service id)
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                               -- время создания
    updated_at TIMESTAMPTZ NULL                                                  -- время последнего обновления
);

CREATE INDEX IF NOT EXISTS idx_tl_licenses_tenant_status ON maniforge_tl_tenant_licenses (tenant_code, status, expires_at);

-- Учёт квот по метрикам (tenant ± workspace) за период.
CREATE TABLE IF NOT EXISTS maniforge_tl_quota_usage (
    id BIGSERIAL PRIMARY KEY,              -- суррогатный PK
    tenant_code VARCHAR(100) NOT NULL,     -- код tenant
    subtenant_code VARCHAR(100) NULL,      -- код workspace (NULL = агрегат по tenant)
    metric VARCHAR(100) NOT NULL,          -- имя метрики (users, sessions, …)
    period_key VARCHAR(32) NOT NULL,       -- ключ периода (YYYY-MM, YYYY-MM-DD, …)
    used BIGINT NOT NULL DEFAULT 0,        -- текущее потребление
    limit_snapshot BIGINT NULL,            -- снимок лимита на момент учёта
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), -- время последнего пересчёта
    UNIQUE (tenant_code, subtenant_code, metric, period_key)
);

-- Аудит админ-операций TL-сервиса.
CREATE TABLE IF NOT EXISTS maniforge_tl_audit_log (
    id BIGSERIAL PRIMARY KEY,              -- суррогатный PK
    event_type VARCHAR(120) NOT NULL,      -- тип события (tenant.created, license.assigned, …)
    actor VARCHAR(120) NULL,               -- инициатор (login или service id)
    tenant_code VARCHAR(100) NOT NULL,     -- код затронутого tenant
    subtenant_code VARCHAR(100) NULL,      -- код workspace (если применимо)
    payload_json JSONB NOT NULL DEFAULT '{}', -- детали операции
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW() -- время события
);

-- Outbox lifecycle-событий для диспетчера → POST /internal/v1/tenant-events в RBAC.
CREATE TABLE IF NOT EXISTS maniforge_tl_events (
    id BIGSERIAL PRIMARY KEY,              -- суррогатный PK
    event_type VARCHAR(120) NOT NULL,      -- тип (tenant.suspended, license.revoked, …)
    tenant_code VARCHAR(100) NOT NULL,     -- код tenant
    subtenant_code VARCHAR(100) NULL,      -- код workspace (если применимо)
    payload_json JSONB NOT NULL DEFAULT '{}', -- полезная нагрузка для RBAC
    delivered_at TIMESTAMPTZ NULL,         -- NULL = ожидает доставки
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW() -- время постановки в outbox
);

CREATE INDEX IF NOT EXISTS idx_tl_events_pending ON maniforge_tl_events (delivered_at, created_at);

-- Локальный кэш access-state (tenant + workspace) для licensingclient; TTL по expires_at.
CREATE TABLE IF NOT EXISTS maniforge_tenant_access_cache (
    cache_key VARCHAR(220) PRIMARY KEY,    -- составной ключ (tenant + workspace + project)
    tenant_code VARCHAR(100) NOT NULL,     -- код tenant
    subtenant_code VARCHAR(100) NOT NULL,  -- код workspace
    state_json JSONB NOT NULL DEFAULT '{}', -- закэшированный access-state
    fetched_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), -- время получения от TL-сервиса
    expires_at TIMESTAMPTZ NOT NULL        -- момент инвалидации кэша
);
