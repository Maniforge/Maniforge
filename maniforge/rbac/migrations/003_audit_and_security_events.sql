CREATE TABLE IF NOT EXISTS maniforge_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    payload_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_scope_created (tenant_id, subtenant_id, created_at),
    INDEX idx_audit_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_security_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    severity VARCHAR(30) NOT NULL,
    payload_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sec_events_scope_created (tenant_id, subtenant_id, created_at),
    INDEX idx_sec_events_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
