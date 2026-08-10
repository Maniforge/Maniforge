-- RBAC core: identity, проекты, сессии и refresh-токены.
-- tenant_id / subtenant_id — строковые коды из TL-сервиса; subtenant_id = workspace (ось B).
-- Изменение security_version в maniforge_users → отзыв всех сессий пользователя.

-- Identity. Критичные поля только здесь; UI-профиль — maniforge_user_profile (008).
CREATE TABLE IF NOT EXISTS maniforge_users (
    id BIGSERIAL PRIMARY KEY,                              -- суррогатный PK
    tenant_id VARCHAR(100) NOT NULL,                       -- код коммерческого tenant
    subtenant_id VARCHAR(100) NOT NULL,                    -- код workspace (= maniforge_tl_subtenants.code)
    login VARCHAR(120) NOT NULL,                           -- уникальный логин в scope tenant+workspace
    email VARCHAR(190) NULL,                               -- email в открытом виде (legacy/поиск)
    phone VARCHAR(32) NOT NULL,                            -- телефон в открытом виде (legacy/поиск)
    email_enc TEXT NULL,                                   -- зашифрованный email (PII)
    phone_enc TEXT NULL,                                   -- зашифрованный телефон (PII)
    pii_enc_version SMALLINT NOT NULL DEFAULT 0,           -- версия схемы шифрования PII
    password_hash VARCHAR(255) NOT NULL,                 -- bcrypt/argon2 hash пароля
    mfa_required BOOLEAN NOT NULL DEFAULT FALSE,         -- обязательна ли MFA при входе
    security_version INT NOT NULL DEFAULT 1,             -- инкремент → revoke all sessions
    status VARCHAR(30) NOT NULL DEFAULT 'active',          -- active | blocked | deleted
    last_password_changed_at TIMESTAMPTZ NULL,             -- момент последней смены пароля
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),         -- время создания
    updated_at TIMESTAMPTZ NULL,                           -- время последнего обновления
    UNIQUE (tenant_id, subtenant_id, login),
    UNIQUE (tenant_id, subtenant_id, phone)
);

-- Проекты модулей: scope tenant_id + subtenant_id (workspace) + code. Licensing runtime: tenant + project.
CREATE TABLE IF NOT EXISTS maniforge_projects (
    id BIGSERIAL PRIMARY KEY,                        -- суррогатный PK
    tenant_id VARCHAR(100) NOT NULL,                 -- код tenant
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',   -- код workspace (пустая строка = tenant-level)
    code VARCHAR(100) NOT NULL,                      -- уникальный код проекта в scope
    name VARCHAR(255) NOT NULL,                      -- отображаемое имя
    status VARCHAR(30) NOT NULL DEFAULT 'active',      -- active | archived
    metadata_json JSONB NULL,                        -- произвольные атрибуты проекта
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),     -- время создания
    updated_at TIMESTAMPTZ NULL,                     -- время последнего обновления
    UNIQUE (tenant_id, subtenant_id, code)
);

-- Активные сессии. security_version_snapshot — hard-deny при рассинхроне с maniforge_users.
CREATE TABLE IF NOT EXISTS maniforge_sessions (
    id CHAR(32) PRIMARY KEY,                                                   -- UUID без дефисов
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE,  -- владелец сессии
    tenant_id VARCHAR(100) NOT NULL,                                           -- код tenant контекста
    subtenant_id VARCHAR(100) NOT NULL,                                        -- код workspace контекста
    project_id BIGINT NULL REFERENCES maniforge_projects(id) ON DELETE SET NULL, -- активный проект
    session_secret_hash CHAR(64) NOT NULL,                                     -- SHA-256 секрета сессии
    ip_hash CHAR(64) NOT NULL,                                                 -- SHA-256 IP клиента
    user_agent_hash CHAR(64) NOT NULL,                                         -- SHA-256 User-Agent
    aal VARCHAR(16) NOT NULL,                                                  -- assurance level (aal1, aal2, …)
    mfa_verified_at TIMESTAMPTZ NULL,                                          -- момент успешной MFA
    last_activity_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),                       -- последняя активность
    expires_at TIMESTAMPTZ NOT NULL,                                           -- срок истечения сессии
    revoked_at TIMESTAMPTZ NULL,                                               -- момент отзыва (NULL = активна)
    revoke_reason VARCHAR(120) NULL,                                           -- причина отзыва
    security_version_snapshot INT NOT NULL,                                    -- security_version на момент выдачи
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()                              -- время создания
);

CREATE INDEX IF NOT EXISTS idx_sessions_user ON maniforge_sessions (user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_hash_active ON maniforge_sessions (session_secret_hash, revoked_at, expires_at);

-- Refresh-токены, привязанные к сессии; отзыв каскадом при revoke session.
CREATE TABLE IF NOT EXISTS maniforge_refresh_tokens (
    id CHAR(32) PRIMARY KEY,                                                   -- UUID без дефисов
    session_id CHAR(32) NOT NULL REFERENCES maniforge_sessions(id) ON DELETE CASCADE, -- родительская сессия
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE,  -- владелец
    tenant_id VARCHAR(100) NOT NULL,                                           -- код tenant контекста
    subtenant_id VARCHAR(100) NOT NULL,                                        -- код workspace контекста
    project_id BIGINT NULL REFERENCES maniforge_projects(id) ON DELETE SET NULL, -- проект контекста
    token_hash CHAR(64) NOT NULL,                                              -- SHA-256 refresh-токена
    expires_at TIMESTAMPTZ NOT NULL,                                           -- срок истечения
    revoked_at TIMESTAMPTZ NULL,                                               -- момент отзыва
    revoke_reason VARCHAR(120) NULL,                                           -- причина отзыва
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()                              -- время создания
);

CREATE INDEX IF NOT EXISTS idx_refresh_user ON maniforge_refresh_tokens (user_id);
CREATE INDEX IF NOT EXISTS idx_refresh_hash_active ON maniforge_refresh_tokens (token_hash, revoked_at, expires_at);
