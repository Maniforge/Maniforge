-- Maniforge RBAC schema (MySQL 8+)
-- Security-first baseline for tenant/subtenant RBAC service

CREATE TABLE IF NOT EXISTS maniforge_tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_subtenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_subtenant (tenant_id, code),
    CONSTRAINT fk_subtenant_tenant FOREIGN KEY (tenant_id) REFERENCES maniforge_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    login VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    mfa_required TINYINT(1) NOT NULL DEFAULT 0,
    security_version INT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    last_password_changed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_users_login_scope (tenant_id, subtenant_id, login),
    UNIQUE KEY uk_users_email_scope (tenant_id, subtenant_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_user_profile (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    display_name VARCHAR(120) NULL,
    avatar_url VARCHAR(1024) NULL,
    bio VARCHAR(1024) NULL,
    locale VARCHAR(16) NULL,
    timezone VARCHAR(64) NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(180) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES maniforge_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES maniforge_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_user_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    assignment_slot VARCHAR(19) GENERATED ALWAYS AS (COALESCE(CAST(expires_at AS CHAR), 'ACTIVE')) STORED,
    INDEX idx_user_roles_scope (user_id, tenant_id, subtenant_id),
    UNIQUE KEY uk_user_role_assignment_slot (user_id, role_id, tenant_id, subtenant_id, assignment_slot),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES maniforge_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS maniforge_sessions (
    id CHAR(32) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    session_secret_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    user_agent_hash CHAR(64) NOT NULL,
    aal VARCHAR(16) NOT NULL,
    mfa_verified_at TIMESTAMP NULL DEFAULT NULL,
    last_activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    revoke_reason VARCHAR(120) NULL,
    security_version_snapshot INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_hash_active (session_secret_hash, revoked_at, expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_refresh_tokens (
    id CHAR(32) PRIMARY KEY,
    session_id CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    revoke_reason VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refresh_user (user_id),
    INDEX idx_refresh_hash_active (token_hash, revoked_at, expires_at),
    CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_refresh_session FOREIGN KEY (session_id) REFERENCES maniforge_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    payload_json JSON NOT NULL,
    correlation_id CHAR(32) NULL,
    integrity_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_scope_created (tenant_id, subtenant_id, created_at),
    INDEX idx_audit_correlation (correlation_id),
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
    correlation_id CHAR(32) NULL,
    integrity_hash CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sec_events_scope_created (tenant_id, subtenant_id, created_at),
    INDEX idx_sec_events_correlation (correlation_id),
    INDEX idx_sec_events_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maniforge_rate_limits (
    bucket_key CHAR(64) PRIMARY KEY,
    window_started_at TIMESTAMP NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rate_limits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS maniforge_password_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_history_user (user_id, created_at),
    CONSTRAINT fk_password_history_user FOREIGN KEY (user_id) REFERENCES maniforge_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO maniforge_roles (id, code, name, is_system) VALUES
    (1, 'super_admin', 'Super Admin', 1),
    (2, 'tenant_admin', 'Tenant Admin', 1),
    (3, 'subtenant_admin', 'Subtenant Admin', 1),
    (4, 'security_auditor', 'Security Auditor', 1),
    (5, 'support_operator', 'Support Operator', 1),
    (6, 'moderator', 'Moderator', 1),
    (7, 'user', 'User', 1);

INSERT IGNORE INTO maniforge_permissions (code, description) VALUES
    ('admin.users.read', 'Read users in admin area'),
    ('admin.users.status.bulk', 'Batch change user status in admin area'),
    ('admin.sessions.read', 'Read sessions in admin area'),
    ('admin.sessions.revoke', 'Revoke sessions in admin area'),
    ('admin.sessions.bulk', 'Batch revoke sessions in admin area'),
    ('admin.audit.read', 'Read audit log'),
    ('admin.security_events.read', 'Read security events'),
    ('admin.roles.read', 'Read roles in admin area'),
    ('admin.permissions.read', 'Read permissions in admin area'),
    ('admin.user_roles.read', 'Read user role assignments'),
    ('admin.user_roles.assign', 'Assign role to user'),
    ('admin.user_roles.revoke', 'Revoke role from user'),
    ('admin.user_roles.bulk', 'Batch role mutations'),
    ('admin.user_access.read', 'Read effective roles and permissions for user'),
    ('admin.policies.read', 'Read policy rules in admin area'),
    ('admin.policies.update', 'Update policy rules in admin area');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.users.read',
    'admin.users.status.bulk',
    'admin.sessions.read',
    'admin.sessions.revoke',
    'admin.sessions.bulk',
    'admin.audit.read',
    'admin.security_events.read',
    'admin.roles.read',
    'admin.permissions.read',
    'admin.user_roles.read',
    'admin.user_roles.assign',
    'admin.user_roles.revoke',
    'admin.user_roles.bulk',
    'admin.user_access.read',
    'admin.policies.read',
    'admin.policies.update'
)
WHERE r.code IN ('super_admin', 'tenant_admin', 'subtenant_admin');

INSERT IGNORE INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
INNER JOIN maniforge_permissions p ON p.code IN (
    'admin.users.read',
    'admin.sessions.read',
    'admin.audit.read',
    'admin.security_events.read',
    'admin.roles.read',
    'admin.permissions.read',
    'admin.user_roles.read',
    'admin.user_access.read',
    'admin.policies.read'
)
WHERE r.code IN ('security_auditor');
