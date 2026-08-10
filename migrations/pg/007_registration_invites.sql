-- Одноразовые инвайты регистрации: token_hash в URL, subtenant_name/code — целевой workspace.

CREATE TABLE IF NOT EXISTS maniforge_registration_invites (
    id BIGSERIAL PRIMARY KEY,              -- суррогатный PK
    token_hash CHAR(64) NOT NULL UNIQUE,   -- SHA-256 токена из ссылки (сырой токен не хранится)
    tenant_id VARCHAR(100) NOT NULL,       -- код tenant для нового пользователя
    subtenant_name VARCHAR(255) NOT NULL,  -- имя workspace (создаётся если subtenant_code пуст)
    subtenant_code VARCHAR(100) NULL,      -- код workspace (NULL = сгенерировать при consume)
    status VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending | consumed | expired | revoked
    role_code VARCHAR(80) NOT NULL DEFAULT 'user', -- роль при регистрации
    expires_at TIMESTAMPTZ NOT NULL,       -- срок действия инвайта
    consumed_at TIMESTAMPTZ NULL,          -- момент использования (NULL = не использован)
    created_by BIGINT NULL,                -- user_id создавшего инвайт
    metadata_json JSONB NULL,              -- доп. параметры (redirect, locale, …)
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW() -- время создания
);

CREATE INDEX IF NOT EXISTS idx_registration_invites_tenant ON maniforge_registration_invites (tenant_id, status, expires_at);
