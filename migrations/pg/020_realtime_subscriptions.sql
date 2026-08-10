-- Пользовательские WebSocket-подписки (каналы хранятся в API, WS подключается по id).
-- См. docs/MANIFORGE_REALTIME.md

CREATE TABLE IF NOT EXISTS maniforge_realtime_subscriptions (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(64) NOT NULL,
    subtenant_id VARCHAR(64) NOT NULL DEFAULT '',
    project_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    name VARCHAR(120) NOT NULL,
    channels_json JSONB NOT NULL DEFAULT '[]'::jsonb,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_rt_subscriptions_scope
    ON maniforge_realtime_subscriptions (tenant_id, subtenant_id, project_id, user_id, status);
