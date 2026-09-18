-- Covering indexes for tenant/subtenant scoped hot paths (no table partitioning).
-- Safe to re-run: duplicate index names are ignored by migrate tooling only if absent;
-- use IF NOT EXISTS pattern via information_schema is not portable; rely on one-shot migration.

ALTER TABLE maniforge_users
    ADD INDEX idx_users_scope_id (tenant_id, subtenant_id, id),
    ADD INDEX idx_users_scope_status (tenant_id, subtenant_id, status),
    ADD INDEX idx_users_login_status (login, status);

ALTER TABLE maniforge_sessions
    ADD INDEX idx_sessions_scope_created (tenant_id, subtenant_id, created_at);

ALTER TABLE maniforge_user_roles
    ADD INDEX idx_user_roles_tenant_role (tenant_id, subtenant_id, role_id);

ALTER TABLE maniforge_refresh_tokens
    ADD INDEX idx_refresh_session_active (session_id, revoked_at, expires_at);
