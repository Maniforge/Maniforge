# ADR-0003: Managed tenant ≠ subtenant

- **Статус:** accepted
- **Дата:** 2026-06-08

## Контекст

В разговорной модели «субтенант = клиент» и «реферальная пирамида» путаются с `subtenant_id` в коде.

## Решение

- **Клиент оператора / реферал** = отдельный **`maniforge_tl_tenants.code`** (managed tenant) + **grant** от principal.
- **`subtenant_id`** в API = workspace внутри одного tenant (филиал, отдел).
- Сетевая пирамида = цепочка tenant + grant, не вложенные subtenant.

## Последствия

- Switch-context переключает **tenant**, не «subtenant-клиента».
- Документация: MANIFORGE_GLOSSARY, MANIFORGE_TENANT_DELEGATION.

## Альтернативы (отклонённые)

- Subtenant как клиент — ломает licensing, FK и delegation.
