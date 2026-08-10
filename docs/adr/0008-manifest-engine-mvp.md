# ADR-0008: Manifest Engine MVP на Go

- **Статус:** accepted
- **Дата:** 2026-06-08

## Контекст

Платформа обещает описание сущности → API без кода. В PHP и Go не было runtime Manifest Engine.

## Решение

- Отдельный сервис `cmd/manifest-engine` (`:8095`), prefix `/api/data`.
- Таблицы `maniforge_manifests`, `maniforge_manifest_records` (JSONB).
- Scope: `tenant_id` + `project_id` из RBAC-сессии.
- API: manifest CRUD, data CRUD, `PUT .../{fieldPath}`, минимальная OpenAPI-генерация.
- Auth: тот же Bearer session, что RBAC (`SessionAuth`).

## Последствия

- Field-level RBAC (`read_roles`/`write_roles`) — следующая фаза.
- Supply chain модули остаются coded; позже — presets manifests.

## Альтернативы (отклонённые)

- Встроить в RBAC app — смешение auth и data plane.
- Отдельная БД на manifest — избыточно для MVP.
