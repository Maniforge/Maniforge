# ADR-0007: Граница Tenant Licensing

- **Статус:** accepted
- **Дата:** 2026-06-08

## Контекст

RBAC и бизнес-модули не должны дублировать коммерческий lifecycle tenant.

## Решение

- **Source of truth:** таблицы `maniforge_tl_*`, сервис Tenant Licensing.
- RBAC вызывает только **`licensingclient.AssertAccess`** (HTTP internal или in-process).
- События TL → RBAC `POST /internal/v1/tenant-events` для revoke sessions.

## Последствия

- Статус tenant/license не читается напрямую из RBAC registry для runtime gate.
- `maniforge_tl_subtenants` — workspace registry в TL, не «клиент».

## Альтернативы (отклонённые)

- Licensing внутри RBAC — смешение auth и billing.
