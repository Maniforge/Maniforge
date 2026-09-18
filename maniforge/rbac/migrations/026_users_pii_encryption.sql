ALTER TABLE maniforge_users
    ADD COLUMN email_enc TEXT NULL AFTER email,
    ADD COLUMN phone_enc TEXT NULL AFTER phone,
    ADD COLUMN pii_enc_version TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER phone_enc;
