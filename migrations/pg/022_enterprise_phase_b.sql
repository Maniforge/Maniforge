-- Enterprise phase B: TOTP MFA + SIEM outbox.

CREATE TABLE IF NOT EXISTS maniforge_mfa_factors (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    factor_type VARCHAR(32) NOT NULL DEFAULT 'totp',
    label VARCHAR(120) NOT NULL DEFAULT 'Authenticator',
    secret_enc TEXT NOT NULL,
    verified_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    revoked_at TIMESTAMPTZ NULL,
    UNIQUE (user_id, tenant_id, subtenant_id, factor_type)
);

CREATE INDEX IF NOT EXISTS idx_mfa_factors_user ON maniforge_mfa_factors (user_id, tenant_id, subtenant_id);

CREATE TABLE IF NOT EXISTS maniforge_mfa_recovery_codes (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES maniforge_users(id) ON DELETE CASCADE,
    tenant_id VARCHAR(100) NOT NULL,
    subtenant_id VARCHAR(100) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_mfa_recovery_user ON maniforge_mfa_recovery_codes (user_id, tenant_id, subtenant_id);

CREATE TABLE IF NOT EXISTS maniforge_siem_outbox (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    tenant_id VARCHAR(100) NOT NULL DEFAULT '',
    subtenant_id VARCHAR(100) NOT NULL DEFAULT '',
    severity VARCHAR(32) NOT NULL DEFAULT 'info',
    payload_json JSONB NOT NULL DEFAULT '{}',
    integrity_hash CHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    delivered_at TIMESTAMPTZ NULL,
    delivery_attempts INT NOT NULL DEFAULT 0,
    last_error TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_siem_outbox_pending ON maniforge_siem_outbox (delivered_at, id)
    WHERE delivered_at IS NULL;

INSERT INTO maniforge_permissions (code, description)
VALUES ('me.mfa.manage', 'Управление TOTP MFA (enroll, disable)')
ON CONFLICT (code) DO NOTHING;

INSERT INTO maniforge_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM maniforge_roles r
CROSS JOIN maniforge_permissions p
WHERE r.code IN ('tenant_admin', 'subtenant_admin', 'super_admin', 'user')
  AND p.code = 'me.mfa.manage'
  AND NOT EXISTS (
      SELECT 1 FROM maniforge_role_permissions rp
      WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
