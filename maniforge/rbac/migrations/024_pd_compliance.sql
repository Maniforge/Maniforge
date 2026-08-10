CREATE TABLE IF NOT EXISTS maniforge_pd_operator_profiles (
    tenant_id VARCHAR(100) NOT NULL PRIMARY KEY,
    operator_name VARCHAR(255) NOT NULL,
    operator_inn VARCHAR(12) NULL,
    operator_address TEXT NULL,
    dpo_name VARCHAR(255) NULL,
    dpo_email VARCHAR(190) NULL,
    dpo_phone VARCHAR(32) NULL,
    privacy_policy_url VARCHAR(1024) NULL,
    privacy_policy_version VARCHAR(32) NOT NULL DEFAULT '1.0',
    data_storage_region VARCHAR(16) NOT NULL DEFAULT 'RU',
    cross_border_transfer_allowed TINYINT(1) NOT NULL DEFAULT 0,
    cross_border_basis TEXT NULL,
    roskomnadzor_notified_at DATE NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_pd_processing_purposes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    code VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    legal_basis VARCHAR(40) NOT NULL DEFAULT 'consent',
    retention_days INT UNSIGNED NULL,
    is_mandatory_for_registration TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    policy_version VARCHAR(32) NOT NULL DEFAULT '1.0',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pd_purpose (tenant_id, code),
    KEY idx_pd_purpose_tenant_active (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_pd_consents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    purpose_code VARCHAR(64) NOT NULL,
    policy_version VARCHAR(32) NOT NULL,
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    source VARCHAR(64) NOT NULL DEFAULT 'api',
    ip_hash VARCHAR(64) NULL,
    user_agent_hash VARCHAR(64) NULL,
    KEY idx_pd_consent_user (user_id, tenant_id, subtenant_id),
    KEY idx_pd_consent_purpose (tenant_id, purpose_code),
    CONSTRAINT fk_pd_consent_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_pd_subject_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    request_type VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    payload_json JSON NULL,
    handler_user_id BIGINT UNSIGNED NULL,
    handler_note TEXT NULL,
    due_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pd_request_scope (tenant_id, subtenant_id, status),
    KEY idx_pd_request_user (user_id, created_at),
    CONSTRAINT fk_pd_request_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
