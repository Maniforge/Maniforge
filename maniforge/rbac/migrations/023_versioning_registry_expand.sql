INSERT IGNORE INTO maniforge_ver_registry (entity_table, entity_label, description) VALUES
    ('maniforge_roles', 'Роли RBAC', 'Создание, переименование и удаление custom-ролей'),
    ('maniforge_role_permissions', 'Permissions роли', 'Замена набора permissions у роли'),
    ('maniforge_user_roles', 'Назначения ролей', 'Assign/revoke ролей пользователям и batch-операции'),
    ('maniforge_policy_rules', 'Политики admin API', 'IP-allowlist, окно времени UTC, require_step_up'),
    ('maniforge_sessions', 'Сессии', 'Revoke сессий администратором'),
    ('maniforge_user_project_memberships', 'Членство в проектах', 'Назначение пользователя на проект'),
    ('maniforge_registration_invites', 'Invite-ссылки', 'Создание invite для регистрации');
