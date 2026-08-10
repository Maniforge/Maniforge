-- Phone required for new users; email optional (NULL = not set).
ALTER TABLE maniforge_users
    ADD COLUMN phone VARCHAR(32) NULL AFTER email;

UPDATE maniforge_users
SET phone = CONCAT('+7000000', LPAD(id, 5, '0'))
WHERE phone IS NULL OR TRIM(phone) = '';

ALTER TABLE maniforge_users
    MODIFY COLUMN phone VARCHAR(32) NOT NULL,
    MODIFY COLUMN email VARCHAR(190) NULL;

ALTER TABLE maniforge_users
    ADD UNIQUE KEY uk_users_phone_scope (tenant_id, subtenant_id, phone);
