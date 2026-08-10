CREATE TABLE IF NOT EXISTS maniforge_tl_tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    suspended_at TIMESTAMP NULL DEFAULT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tl_tenants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_tl_subtenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tenant_code VARCHAR(100) NOT NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tl_subtenant_code (tenant_code, code),
    INDEX idx_tl_subtenants_status (status),
    CONSTRAINT fk_tl_subtenant_tenant FOREIGN KEY (tenant_id) REFERENCES maniforge_tl_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_tl_license_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    features_json JSON NOT NULL,
    limits_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tl_license_plans_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_tl_tenant_licenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    tenant_code VARCHAR(100) NOT NULL,
    plan_code VARCHAR(100) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    starts_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    seats_max INT UNSIGNED NULL,
    assigned_by VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tl_licenses_tenant_status (tenant_code, status, expires_at),
    CONSTRAINT fk_tl_license_tenant FOREIGN KEY (tenant_id) REFERENCES maniforge_tl_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_tl_quota_usage (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_code VARCHAR(100) NOT NULL,
    subtenant_code VARCHAR(100) NULL,
    metric VARCHAR(100) NOT NULL,
    period_key VARCHAR(32) NOT NULL,
    used BIGINT UNSIGNED NOT NULL DEFAULT 0,
    limit_snapshot BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tl_quota_usage (tenant_code, subtenant_code, metric, period_key),
    INDEX idx_tl_quota_tenant (tenant_code, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_tl_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    actor VARCHAR(120) NULL,
    tenant_code VARCHAR(100) NOT NULL,
    subtenant_code VARCHAR(100) NULL,
    payload_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tl_audit_scope_created (tenant_code, subtenant_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_tl_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    tenant_code VARCHAR(100) NOT NULL,
    subtenant_code VARCHAR(100) NULL,
    payload_json JSON NOT NULL,
    delivered_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tl_events_pending (delivered_at, created_at),
    INDEX idx_tl_events_scope (tenant_code, subtenant_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_tenant_access_cache (
    cache_key VARCHAR(220) PRIMARY KEY,
    tenant_code VARCHAR(100) NOT NULL,
    subtenant_code VARCHAR(100) NOT NULL,
    state_json JSON NOT NULL,
    fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_tenant_access_cache_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_tl_license_plans (code, name, features_json, limits_json)
VALUES (
    'starter',
    'Starter',
    JSON_OBJECT('rbac', true, 'admin_api', true),
    JSON_OBJECT('max_users', 100, 'max_sessions', 500)
);

INSERT IGNORE INTO maniforge_tl_tenants (code, name, status)
VALUES ('default', 'Default tenant', 'active');

INSERT IGNORE INTO maniforge_tl_subtenants (tenant_id, tenant_code, code, name, status)
SELECT id, code, 'default', 'Default subtenant', 'active'
FROM maniforge_tl_tenants
WHERE code = 'default';

INSERT INTO maniforge_tl_tenant_licenses (tenant_id, tenant_code, plan_code, status)
SELECT t.id, t.code, 'starter', 'active'
FROM maniforge_tl_tenants t
WHERE t.code = 'default'
  AND NOT EXISTS (
      SELECT 1
      FROM maniforge_tl_tenant_licenses l
      WHERE l.tenant_code = t.code AND l.status = 'active'
  );
