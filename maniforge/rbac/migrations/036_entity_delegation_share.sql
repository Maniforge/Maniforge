-- Доступ к сущности для peer-tenant по активному grant (principal ↔ managed).
-- Настраивается в админке / PATCH stocks: delegation_share_tenant_ids, share_with_principal, share_with_managed.

ALTER TABLE maniforge_wh_stocks
    ADD COLUMN shared_grant_tenant_ids_json JSON NULL AFTER shared_subtenant_ids_json;
