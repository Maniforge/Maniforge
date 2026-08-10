-- Enterprise hardening: login lockout + HTTP rate limiting (паритет с PHP migrations 004, 012).

CREATE TABLE IF NOT EXISTS maniforge_login_attempts (
    id BIGSERIAL PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    login VARCHAR(120) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    failed_count INT NOT NULL DEFAULT 0,
    last_failed_at TIMESTAMPTZ NULL,
    locked_until TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, subtenant_id, login, ip_hash)
);

CREATE INDEX IF NOT EXISTS idx_login_attempt_locked ON maniforge_login_attempts (locked_until);

CREATE TABLE IF NOT EXISTS maniforge_rate_limits (
    bucket_key CHAR(64) PRIMARY KEY,
    window_started_at TIMESTAMPTZ NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_rate_limits_updated ON maniforge_rate_limits (updated_at);
