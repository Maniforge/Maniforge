-- Профиль пользователя: изменяемые данные без отзыва сессий.
-- Критичные поля (phone, email, password, mfa, status, login) — только в maniforge_users.

CREATE TABLE IF NOT EXISTS maniforge_user_profile (
    user_id BIGINT PRIMARY KEY REFERENCES maniforge_users(id) ON DELETE CASCADE, -- FK = PK, 1:1 с users
    display_name VARCHAR(120) NULL,    -- отображаемое имя в UI
    avatar_url VARCHAR(1024) NULL,     -- URL аватара
    bio VARCHAR(1024) NULL,            -- краткое описание
    locale VARCHAR(16) NULL,           -- локаль (ru-RU, en-US, …)
    timezone VARCHAR(64) NULL,         -- IANA timezone (Europe/Moscow, …)
    updated_at TIMESTAMPTZ NULL        -- время последнего изменения профиля
);

CREATE INDEX IF NOT EXISTS idx_user_profile_updated ON maniforge_user_profile (updated_at);
