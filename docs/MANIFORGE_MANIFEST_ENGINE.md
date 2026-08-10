# Maniforge Manifest Engine (Go MVP)

Динамические сущности: **manifest** (схема) → **records** (JSONB) → REST + field-level API.

Архитектура: [MANIFORGE_ARCHITECTURE.md](MANIFORGE_ARCHITECTURE.md), обзор: [MANIFORGE_PLATFORM_OVERVIEW.md](MANIFORGE_PLATFORM_OVERVIEW.md) §4.

## Запуск

```bash
make migrate
make run-rbac      # терминал 1 — сессии
make run-manifest  # терминал 2 — :8095
```

Health: `GET http://127.0.0.1:8095/health`

## Control plane vs data plane

См. [ADR-0009](adr/0009-control-data-plane-manifest-origin.md).

| План | Сейчас | Содержимое |
|------|--------|------------|
| **Control** | общая PostgreSQL | TL metadata, RBAC, `maniforge_field_type_catalog`, пресеты (код) |
| **Data** | та же БД (`tenant_id`) | `maniforge_manifests`, `maniforge_manifest_records` → позже **БД tenant** |

`internal/dataplane.Resolver` — маршрут по `metadata_json.data_plane_mode` (`shared` | `dedicated`).

### Origin манифестов (внутренние vs пользовательские)

| `origin` | Смысл | API |
|----------|--------|-----|
| `platform` | Базовые (presets: product, stock) — **только платформа** | `POST /manifests/presets/{code}` |
| `custom` | Пользовательские из конструктора — **только клиент** | `POST /manifests` |

Клиент **не может** создать/изменить/удалить `platform`-манифест (коды preset зарезервированы). Фильтр: `GET /manifests?origin=platform|custom`. Палитра полей: `GET /api/v1/catalog/field-types`.

### Live-обновления (WebSocket, только custom)

См. [MANIFORGE_REALTIME.md](MANIFORGE_REALTIME.md): каналы `entity.custom`, `data.<code>`, события manifest/record при `origin=custom`.

## Scope

Все операции в контуре **RBAC-сессии**: `tenant_id` + `project_id` (обязателен в сессии).

Таблицы: `maniforge_manifests`, `maniforge_manifest_records` (`migrations/pg/010_manifest_engine.sql`).

## API

Авторизация: `Authorization: Bearer <access_token>` (та же сессия, что RBAC).

### Manifest CRUD

| Method | Path | Описание |
|--------|------|----------|
| POST | `/api/v1/manifests` | Создать manifest |
| GET | `/api/v1/manifests` | Список |
| GET | `/api/v1/manifests/{code}` | Получить |
| PATCH | `/api/v1/manifests/{code}` | Обновить fields (+ version++) |
| GET | `/api/v1/manifests/{code}/openapi` | OpenAPI 3 (минимальная генерация) |

Префикс `/manifest-engine` дублирует те же пути.

**Пример создания:**

```json
POST /api/v1/manifests
{
  "code": "product",
  "name": "Product",
  "fields": [
    {"name": "title", "type": "string", "required": true, "max_length": 255},
    {"name": "price", "type": "number", "required": true, "min": 0},
    {"name": "variants", "type": "array", "items": {"type": "object"}}
  ]
}
```

### Data API

| Method | Path | Описание |
|--------|------|----------|
| GET | `/api/data/{entity}` | Список записей (`?limit=&offset=`) |
| POST | `/api/data/{entity}` | Создать |
| GET | `/api/data/{entity}/{id}` | Прочитать |
| PATCH | `/api/data/{entity}/{id}` | Частичное обновление |
| DELETE | `/api/data/{entity}/{id}` | Удалить запись |
| PUT | `/api/data/{entity}/{id}/{fieldPath}` | Field-level (`{"value": ...}`) |
| DELETE | `/api/data/{entity}/{id}/{fieldPath}` | Сброс поля в `null` |

**Field-level пример:**

```http
PUT /api/data/product/1/variants/0/price
{"value": 199.99}

DELETE /api/data/product/1/body
```

Ответ DELETE поля: `{"ok":true,"record":{...,"data":{"body":null,...}},"field":"body","value":null}`.
Обязательное поле (`required: true`) сбросить нельзя — `422`.

## Типы полей MVP

`string`, `number`, `boolean`, `array`, `object`

`read_roles` / `write_roles` в схеме — зарезервированы; enforcement в следующей фазе.

## Тестирование

```bash
# Unit-тесты пакета manifestengine
go test ./internal/manifestengine/...

# Smoke journey (16 шагов)
make run-tl run-rbac run-manifest   # 3 терминала
make manifest-journey

# Максимальное интеграционное покрытие (~25 кейсов: auth, CRUD, RBAC, filter, audit, versioning)
make manifest-test

# Всё сразу: unit + journey + manifest-test
make manifest-test-all
```

`manifest-test` проверяет: 401 без токена, presets, validation, nested field-path, filter/pagination, delete record, audit+versioning в БД, archive 404, OpenAPI YAML, prefix routes.

## Smoke flow

```bash
# 1. Login (RBAC)
TOKEN=$(curl -s -X POST http://127.0.0.1:8093/rbac/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"phone":"+7...","password":"...","tenant_id":"default","subtenant_id":"default"}' \
  | jq -r '.session.access_token')

# 2. Create manifest
curl -s -X POST http://127.0.0.1:8095/api/v1/manifests \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"code":"note","name":"Note","fields":[{"name":"title","type":"string","required":true}]}'

# 3. Create record
curl -s -X POST http://127.0.0.1:8095/api/data/note \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"title":"Hello manifest"}'
```

## План доработки

Полный roadmap: [MANIFORGE_MANIFEST_ENGINE_PLAN.md](MANIFORGE_MANIFEST_ENGINE_PLAN.md).

**Фаза 1–2 (реализовано):**
- Licensing gate на все операции
- Audit `maniforge_manifest_audit_log`
- `DELETE /api/v1/manifests/{code}` (archive)
- Field RBAC: `read_roles` / `write_roles`, admin roles для manifest CRUD
- `make manifest-journey`

**Фаза 3 (реализовано):**
- После create/update/delete record → `maniforge_ver_changes` (`internal/versioning`)
- Реестр: `maniforge_manifest_records` в `maniforge_ver_registry`
- Journey проверяет >=3 записей versioning (insert + 2× update)

**Фаза 4 (реализовано):**
- `GET /api/data/{entity}?filter={"field":"value"}` — equality через `data_json @>`
- Строки с `%` — `ILIKE`
- Ответ: `meta: {total, limit, offset, count}`
- `GET /api/v1/manifests/{code}/openapi.yaml` — `application/yaml`

**Фаза 5 (реализовано):**
- `make manifest-refine-gen` → `templates/refine-manifest/generated/{code}/` (Refine v4 + dataProvider)
- Браузерный UI: `/refine-manifest` (CRUD без npm)
- CORS для local в Manifest Engine (`APP_ENV=local`)

**Фаза 6 (реализовано):**
- Presets: `product` (SKU), `stock` (warehouse node)
- `GET /api/v1/manifests/presets`, `POST /api/v1/manifests/presets/{code}`
- Миграция `013_manifest_presets.sql` для default tenant
- `make manifest-presets-seed` — установка platform presets в project scope
- `make manifest-client-test-seed` — тестовый клиент: register → POST `/manifests` (origin=custom) → `/api/data/invoice`

**Supply chain presets** — эталон для портирования PHP `/products` и `/warehouses` на manifest API.
