-- Политика tenant: обязательная регистрация TOTP перед admin-мутациями.

ALTER TABLE maniforge_policy_rules
    ADD COLUMN IF NOT EXISTS require_mfa_enrollment BOOLEAN NOT NULL DEFAULT FALSE;
