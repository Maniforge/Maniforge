CREATE TABLE IF NOT EXISTS maniforge_registration_invites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_name VARCHAR(255) NOT NULL,
    subtenant_code VARCHAR(100) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    role_code VARCHAR(80) NOT NULL DEFAULT 'user',
    expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP NULL DEFAULT NULL,
    created_by BIGINT UNSIGNED NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_registration_invite_token (token_hash),
    INDEX idx_registration_invites_tenant (tenant_id, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
