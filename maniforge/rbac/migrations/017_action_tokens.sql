CREATE TABLE IF NOT EXISTS maniforge_action_tokens (
    id CHAR(32) PRIMARY KEY,
    session_id CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    purpose VARCHAR(32) NOT NULL DEFAULT 'admin_sensitive',
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action_tokens_hash_active (token_hash, revoked_at, expires_at),
    INDEX idx_action_tokens_session_active (session_id, revoked_at, expires_at),
    CONSTRAINT fk_action_tokens_session FOREIGN KEY (session_id) REFERENCES maniforge_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_action_tokens_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
