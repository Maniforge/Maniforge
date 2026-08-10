# ADR-0006: Слоистая структура Go-сервисов

- **Статус:** accepted
- **Дата:** 2026-06-08

## Контекст

Без явных слоёв handler/repository смешиваются HTTP, SQL и бизнес-правила.

## Решение

`cmd/` → `app.go` → `handler/` → `service/` → `repository/` (+ `security/` при необходимости).

Общая инфраструктура: `internal/platform/`, `internal/config/`, `internal/db/`.

## Последствия

- Каждый новый Go-модуль повторяет структуру RBAC / Tenant Licensing.
- Тесты бизнес-логики таргетируют `service` и `repository`.

## Альтернативы (отклонённые)

- Anemic handler с SQL — «лапша», сложно портировать с PHP use-cases.
