# ADR-0002: Ось данных tenant + project

- **Статус:** accepted
- **Дата:** 2026-06-08

## Контекст

Проверка licensing и хранение доменных сущностей ошибочно опирались на пару tenant + subtenant.

## Решение

- Первичная ось доменных данных: **`tenant_id` + `project_id`**.
- `subtenant_id` — технический workspace (маршрут сессии, видимость), не операционный контур лицензии.
- Access-state: `GET .../tenants/{tenant}/projects/{project}/access-state`.

## Последствия

- `maniforge_projects` участвует в runtime licensing.
- Legacy path `/subtenants/{sub}/access-state` deprecated → project `main`.

## Альтернативы (отклонённые)

- Subtenant как project — смешивает ось B с контуром работ.
