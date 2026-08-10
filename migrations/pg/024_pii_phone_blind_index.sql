-- Blind index phone/email (HMAC-SHA256 hex) требует до 64 символов при RBAC_PII_ENCRYPTION_ENABLED.

ALTER TABLE maniforge_users
    ALTER COLUMN phone TYPE VARCHAR(64);
