# ADR-0004: Licensing на tenant, runtime check tenant + project

- **Статус:** accepted
- **Дата:** 2026-06-08

## Контекст

Лицензия покупается на организацию (tenant), но пользователь работает в контуре project.

## Решение

- Коммерция: `maniforge_tl_tenant_licenses` по `tenant_code`.
- Runtime gate: `tenant_active` + `project_active` + `license_active`.
- RBAC передаёт project code из сессии (`project_id` → code).

## Последствия

- `licensingclient.AssertAccess(tenant, project, workspace)`.
- Регистрация/login по умолчанию проверяют project `main`.

## Альтернативы (отклонённые)

- Лицензия на subtenant — не отражает коммерческую модель.
