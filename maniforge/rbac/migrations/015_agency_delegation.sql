CREATE TABLE IF NOT EXISTS maniforge_tl_tenant_grants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    principal_tenant_code VARCHAR(100) NOT NULL,
    managed_tenant_code VARCHAR(100) NOT NULL,
    grant_level VARCHAR(30) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    metadata_json JSON NULL,
    created_by VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uk_tl_tenant_grants_pair (principal_tenant_code, managed_tenant_code),
    INDEX idx_tl_grants_principal_status (principal_tenant_code, status),
    INDEX idx_tl_grants_managed (managed_tenant_code),
    CONSTRAINT fk_tl_grant_principal FOREIGN KEY (principal_tenant_code) REFERENCES maniforge_tl_tenants(code) ON DELETE RESTRICT,
    CONSTRAINT fk_tl_grant_managed FOREIGN KEY (managed_tenant_code) REFERENCES maniforge_tl_tenants(code) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
