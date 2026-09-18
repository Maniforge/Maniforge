CREATE TABLE IF NOT EXISTS maniforge_policy_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    allowed_ips_json JSON NOT NULL,
    allowed_hour_start_utc TINYINT UNSIGNED NOT NULL DEFAULT 0,
    allowed_hour_end_utc TINYINT UNSIGNED NOT NULL DEFAULT 23,
    require_step_up TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_policy_scope (tenant_id, subtenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('admin.policies.read', 'Read policy rules in admin area'),
    ('admin.policies.update', 'Update policy rules in admin area');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('admin.policies.read', 'admin.policies.update')
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN ('admin.policies.read')
WHERE r.code IN ('security_auditor');
