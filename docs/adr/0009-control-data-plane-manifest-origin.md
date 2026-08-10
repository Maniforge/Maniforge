# ADR-0009: Control plane / data plane и origin манифестов

- **Статус:** accepted
- **Дата:** 2026-06-08

## Контекст

При масштабировании пользовательские данные должны жить в **отдельной БД tenant** (data plane). Платформа держит licensing, auth и **каталог типов полей** (control plane). Конструктор сущностей (аналог Frappe DocType) должен различать **внутренние (базовые)** и **пользовательские** схемы — клиент использует оба класса.

## Решение

### 1. Два плана

| План | Содержимое | Сейчас | Будущее |
|------|------------|--------|---------|
| **Control plane** | TL, RBAC identity, `maniforge_field_type_catalog`, пресеты (код) | общая PostgreSQL | общая БД / сервисы |
| **Data plane** | `maniforge_manifests`, `maniforge_manifest_records`, доменные данные | та же БД, фильтр `tenant_id` | **отдельная БД tenant** |

Маршрутизация: `internal/dataplane.Resolver` по `tenant_id` читает `metadata_json` TL (`data_plane_mode`, `data_plane_dsn`). Режим `shared` (default) — текущее поведение; `dedicated` — отдельный DSN.

### 2. Origin манифеста (внутренние vs пользовательские)

Колонка `maniforge_manifests.origin`:

| Значение | Смысл | Как появляется |
|----------|--------|----------------|
| `platform` | Внутренняя / базовая схема (preset supply chain) | `POST /manifests/presets/{code}` |
| `custom` | Пользовательская схема из конструктора | `POST /manifests` |

Оба типа доступны для `/api/data/{entity}`. Фильтр: `GET /manifests?origin=platform|custom`.

### 3. Каталог типов полей

Таблица `maniforge_field_type_catalog` — палитра конструктора (string, number, link, select, …). API: `GET /api/v1/catalog/field-types`. Не смешивается с экземплярами DocType (`maniforge_manifests.fields_json`).

### 4. Правила

- **Клиент не создаёт внутренние манифесты.** `origin=platform` выставляет только платформа (`POST /manifests/presets/{code}`, seed, миграции).
- `POST /manifests` всегда создаёт `origin=custom`; зарезервированные коды (`product`, `stock`) и `metadata.preset|origin` отклоняются.
- **Клиент не изменяет и не архивирует** platform-манифесты (PATCH/DELETE → 403). Данные (`/api/data/{entity}`) — для обоих origin.
- Identity (`maniforge_users`) остаётся в control plane до отдельного ADR.

## Последствия

- MVP на одной БД; код готов к выделению data plane без смены API.
- Конструктор UI: `field-types` + `manifests?origin=custom` + install presets.
- Миграция `016_control_plane_manifest_origin.sql`.

## Альтернативы (отклонённые)

- Только `metadata_json.preset` без колонки `origin` — хуже для индексов и API.
- Сразу отдельная БД на каждый tenant — преждевременно для dev/starter.
