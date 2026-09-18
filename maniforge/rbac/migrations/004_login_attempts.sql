CREATE TABLE IF NOT EXISTS maniforge_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    login VARCHAR(120) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_failed_at TIMESTAMP NULL DEFAULT NULL,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_login_attempt_scope (tenant_id, subtenant_id, login, ip_hash),
    INDEX idx_login_attempt_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
