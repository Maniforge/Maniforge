# Maniforge — принципы проекта

Сводный реестр правил и принципов платформы.  
**Источник истины по архитектуре:** [MANIFORGE_ARCHITECTURE.md](MANIFORGE_ARCHITECTURE.md) (после согласования v1.0).  
При конфликте специализаций с архитектурой — **побеждает MANIFORGE_ARCHITECTURE**.

---

## 1. Иерархия документов

| Приоритет | Документ | Роль |
|-----------|----------|------|
| 1 | [MANIFORGE_ARCHITECTURE.md](MANIFORGE_ARCHITECTURE.md) | Архитектурные решения, границы сервисов, слои, запреты |
| 2 | [MANIFORGE_PLATFORM_OVERVIEW.md](MANIFORGE_PLATFORM_OVERVIEW.md) | Продукт, модули, бизнес-модель |
| 3 | [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md) | Термины (tenant, workspace, managed tenant, project) |
| 4 | Специализации | Licensing, RBAC, scope, workflows — по ссылкам в ARCHITECTURE §11 |
| — | `docs/adr/` | Принятые архитектурные решения (индекс в ARCHITECTURE §9) |
| — | `.cursor/rules/` | Обязательные правила для AI и разработчиков в IDE |

**Правило изменений:** при расхождении с кодом — исправлять код или ADR, не «тихо» менять поведение.

---

## 2. Цели архитектуры

1. **API-first** — контракт HTTP/JSON важнее конкретного языка runtime.
2. **Go — основной рантайм**; PHP — референс и регрессия до полного паритета.
3. **Мультитенантность в строках** — одна PostgreSQL (Go), изоляция `tenant_id` + `project_id`.
4. **Security-by-default** — deny-by-default RBAC, licensing gate, разделение identity/profile.
5. **Предсказуемые границы** — сервисы не лезут в чужие таблицы без явного контракта.

---

## 3. Философия продукта

- Maniforge — **API-движок** для разработчиков, интеграторов и технических предпринимателей, не low-code «для бизнес-пользователя».
- **Lego-кубики:** tenant, RBAC, модули, манифесты — из них собирается сервис с полным контролем над API и данными.
- **Manifest Engine** — ключевая инновация: описание сущности → REST API + UI без ручного бэкенда.
- Конфигурация и модули вместо разработки бэкенда с нуля.

---

## 4. Жёсткие правила (нельзя нарушать)

Эти правила дублируются в `.cursor/rules/maniforge-architecture.mdc` (`alwaysApply`).

| # | Правило |
|---|---------|
| 1 | **`subtenant_id` = workspace (ось B)**, не клиент. Клиент/реферал = **managed tenant + grant** (ось A). |
| 2 | **Данные модулей:** `tenant_id` + `project_id`. Licensing runtime: tenant + project. |
| 3 | **`maniforge_users`** — identity; любое изменение → `security_version++` + revoke sessions. Профиль — только **`maniforge_user_profile`**. |
| 4 | **Слои Go:** handler → service → repository. Без SQL в handler. |
| 5 | **Licensing:** RBAC проверяет доступ только через `licensingclient`, не напрямую по TL-таблицам. |
| 6 | **Паритет API:** при портировании с PHP — те же JSON и HTTP-коды, что journey-тесты. |

### Дополнительные запреты (слои Go)

- SQL в `handler` (кроме health ping через `db`).
- HTTP-типы Fiber в `repository`.
- Обход `licensingclient` для runtime-доступа tenant/project.
- Дублирование таблиц TL в RBAC (статус tenant — только через TL).
- Platform tokens (`TENANT_LICENSING_*`, `RBAC_INTERNAL_TOKEN`) — **не передавать** в браузер.

---

## 5. Доменная модель: две независимые оси

| Ось | Смысл | Технически |
|-----|--------|------------|
| **A. Делегирование / сеть** | Оператор, реферал, MSP ведут **другую организацию** | Principal tenant → **Managed tenant** + **Grant** |
| **B. Внутренняя структура** | Отделы, площадки, бренды **внутри одной** организации | Tenant → **Workspace** (`subtenant_id`) → **Project** |

**Правила без коллизий:**

- `subtenant_id` в API — **workspace**, никогда не «клиент-организация».
- Клиент оператора — **managed tenant** (отдельный `maniforge_tl_tenants.code`), не subtenant principal.
- Реферальная «пирамида» = цепочка **отдельных tenant**, связанных grant'ами (ось A), не вложенные subtenant.
- Операционный контур данных: **`tenant_id` + `project_id`**; `subtenant_id` — маршрут сессии, workspace, видимость.
- Cross-tenant доступ — только через **grant** (delegation) или **delegation share** на сущностях.

Подробно: [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md), [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md), [MANIFORGE_TENANT_DELEGATION.md](MANIFORGE_TENANT_DELEGATION.md).

---

## 6. Пользователь: identity vs profile

| Таблица | Содержимое | При изменении |
|---------|------------|---------------|
| `maniforge_users` | phone, email, password, mfa, status, login, scope | `security_version++`, **RevokeAllForUser** |
| `maniforge_user_profile` | display_name, avatar, bio, locale, timezone | Сессии **не** рвутся |

API:

- `PATCH /api/v1/me/profile` — мягко (профиль).
- `PATCH /api/v1/me/identity`, `POST /api/v1/me/change-password` — с logout everywhere.

Сессия хранит `security_version_snapshot`; authenticate сравнивает с текущим.

---

## 7. Слои Go-приложения

Каждый сервис: `cmd/<name>` + `internal/<name>/`:

```text
cmd/<service>/main.go          # только wiring: config, db, Listen
internal/<service>/app.go      # Fiber, маршруты, middleware
internal/<service>/handler/    # HTTP: parse → service → httpx
internal/<service>/service/    # бизнес-логика, оркестрация
internal/<service>/repository/ # SQL, без бизнес-правил
internal/<service>/security/   # crypto, PII (если нужно)
```

**Общие пакеты** (`internal/platform/`, `internal/config/`, `internal/db/`):

- без доменной логики;
- переиспользуются всеми сервисами.

Карта файлов: [MANIFORGE_GO_CODEMAP.md](MANIFORGE_GO_CODEMAP.md).

---

## 8. Границы сервисов

| Сервис | Владеет | Контракт с другими |
|--------|---------|-------------------|
| **RBAC** | users, sessions, roles, projects, auth API | → TL: `licensingclient`; ← TL: tenant-events |
| **Tenant Licensing** | `maniforge_tl_*`, runtime access-state, events | source of truth для лицензий |
| **Manifest Engine** | manifests, manifest_records, `/api/data` | licensing gate, audit, field RBAC |
| **Realtime** | WebSocket hub, подписки | Bearer session; broadcast — service token |
| **Доменные модули** | доменные таблицы в scope tenant+project | Bearer session + permissions, не пароль |

**Модули:**

- не проверяют пароль; только **Bearer session** + permissions;
- scope сессии: `tenant_id`, `subtenant_id`, `project_id`;
- cross-tenant — только grant + switch-context или delegation share.

**Credentials:** три уровня — platform tokens, session (access/refresh), action tokens (reauth).  
См. [MANIFORGE_CREDENTIAL_ARCHITECTURE.md](MANIFORGE_CREDENTIAL_ARCHITECTURE.md).

---

## 9. Два runtime-контура

| | Go (основной) | PHP (референс) |
|---|---------------|----------------|
| Код | `cmd/`, `internal/` | `app/Maniforge/`, `maniforge/` |
| БД | PostgreSQL `migrations/pg/` | MySQL `maniforge/rbac/migrations/` |
| Эталон при портировании | Целевой | Поведение + journey-тесты до паритета |
| Удаление PHP | После паритета API и e2e на Go | — |

- PHP-контур **не удаляется** до cutover — journey-тесты задают контракт.
- Расхождение Go с PHP journey — **баг Go-контура**, пока нет ADR об изменении контракта.
- Паритет: JSON-поля и HTTP-коды **1:1** с OpenAPI / PHP journey.

Порядок портирования: [MANIFORGE_GO_MIGRATION.md](MANIFORGE_GO_MIGRATION.md).

---

## 10. Безопасность (сквозные требования)

| Требование | Реализация |
|------------|------------|
| Пароли | Argon2id (новые), verify bcrypt legacy |
| PII | Blind index phone; AES-GCM optional (`RBAC_PII_*`) |
| Сессии | Hash token в БД; rotation refresh; revoke reasons |
| Admin | reauth + action token для чувствительных операций |
| Audit | `maniforge_audit_log`, versioning с redact PII |
| Headers | `SecurityHeaders` middleware на всех Fiber-сервисах |
| RBAC | roles + permissions, policy rules, **deny-by-default** |
| Licensing | runtime gate на tenant + project |

Детали: [MANIFORGE_ENTERPRISE_HARDENING.md](MANIFORGE_ENTERPRISE_HARDENING.md), [152FZ_COMPLIANCE.md](152FZ_COMPLIANCE.md).

---

## 11. Manifest Engine

| Принцип | Смысл |
|---------|--------|
| **API-first** | Все возможности через REST; OpenAPI на каждый манифест |
| **Manifest** | Поля, типы, валидация, права → таблица + CRUD API |
| **Field-level API** | `PUT /api/data/{entity}/{id}/{field}` |
| **Field-level permissions** | `read_roles` / `write_roles` в манифесте |
| **UI-генерация** | Refine из OpenAPI; AI-генератор; scaffold |
| **Модульность** | Готовые модули = пресеты манифестов + UI |

### Control plane / data plane (ADR-0009)

| План | Содержимое |
|------|------------|
| **Control plane** | TL, RBAC identity, `maniforge_field_type_catalog`, пресеты |
| **Data plane** | manifests, manifest_records, доменные данные |

**Origin манифестов:**

| `origin` | Кто создаёт | PATCH/DELETE |
|----------|-------------|--------------|
| `platform` | только платформа (presets, seed) | запрещено клиенту (403) |
| `custom` | конструктор (`POST /manifests`) | разрешено владельцу |

- Клиент **не создаёт** platform-манифесты.
- `POST /manifests` всегда создаёт `origin=custom`.
- Данные `/api/data/{entity}` — для обоих origin.

---

## 12. Доменные модули (supply chain и др.)

**Принцип аудита** (на примере Warehouses):

| Слой | Где живёт |
|------|-----------|
| Кто сделал | `maniforge_users` → `created_by_user` / `actor_user` |
| Что сделал | `maniforge_audit_log` с `diff` |
| Снимок данных | `maniforge_ver_changes` |
| Доступ | permissions модуля + лицензия tenant |

- Модули — узлы Maniforge (tenant scope, RBAC, аудит), не копии внешних монолитов.
- Интеграция с внешними системами — через `entity_meta` и API.

---

## 13. UI-стратегия

- **API-first + schema-driven UI** — OpenAPI, Manifest Engine, RBAC-сессия, Realtime.
- Не «один фреймворк навсегда» — typed API client + generated screens + custom screens.
- PHP-шаблоны (`templates/`) постепенно заменяются React SPA (Refine / AI UIGen).

См. [MANIFORGE_UI_STRATEGY.md](MANIFORGE_UI_STRATEGY.md).

---

## 14. Документирование кода

Каждый `.go` файл **обязан** иметь заголовок перед `package`:

```go
// Файл: <имя>.go
// Назначение: <1–2 предложения>
// Зависимости: <пакеты, таблицы БД, внешние сервисы>
// См. также: <связанные файлы или docs/MANIFORGE_GO_CODEMAP.md>
package foo
```

**Правила:**

1. Новый файл — заголовок сразу при создании.
2. Экспортируемые символы — godoc (единообразно в пакете).
3. Нет «голых» пакетов — описание в codemap.
4. Бизнес-логика — комментарий у неочевидных веток (security_version, revoke, licensing).
5. Новый/переименованный файл → обновить [MANIFORGE_GO_CODEMAP.md](MANIFORGE_GO_CODEMAP.md).
6. Новый термин → [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md).

Правило Cursor: `.cursor/rules/go-file-documentation.mdc`.

---

## 15. Процесс изменения архитектуры

После согласования ARCHITECTURE v1.0 любое **архитектурное** изменение:

1. Добавить **ADR** в `docs/adr/NNNN-краткое-имя.md`.
2. Обновить **MANIFORGE_ARCHITECTURE.md** (§9 ADR index + затронутые §).
3. Обновить глоссарий / специализации при смене терминов.
4. Обновить **MANIFORGE_GO_CODEMAP.md** при новых пакетах.
5. PR не мержить без пункта «Architecture impact: yes/no».

### Принятые ADR (индекс)

| ID | Решение |
|----|---------|
| ADR-0001 | Go — основной runtime; PHP — референс |
| ADR-0002 | Изоляция данных: `tenant_id` + `project_id`; subtenant = workspace |
| ADR-0003 | Managed client = отдельный tenant + grant, не subtenant |
| ADR-0004 | Licensing на tenant; runtime check tenant + project |
| ADR-0005 | `users` vs `user_profile`; identity change → revoke all sessions |
| ADR-0006 | Слои handler → service → repository в Go |
| ADR-0007 | TL — source of truth для лицензий; RBAC через licensingclient |
| ADR-0008 | Manifest Engine MVP |
| ADR-0009 | Control/data plane; manifest origin platform/custom; field type catalog |

Полные тексты: `docs/adr/`.

---

## 16. Чеклист для нового кода (Go)

Перед merge проверить:

- [ ] Файл имеет заголовок (`.cursor/rules/go-file-documentation.mdc`)
- [ ] Нет SQL в handler; бизнес-логика в service
- [ ] Tenant/project scope не смешан с managed-tenant delegation
- [ ] Изменение `maniforge_users` идёт через security path с revoke
- [ ] Профиль — только `maniforge_user_profile`
- [ ] Runtime access — через `licensingclient`, не прямой SELECT TL
- [ ] Добавлен/обновлён `MANIFORGE_GO_CODEMAP.md`
- [ ] Архитектурное изменение → ADR

---

## 17. Связанные документы

| Тема | Файл |
|------|------|
| Архитектура | [MANIFORGE_ARCHITECTURE.md](MANIFORGE_ARCHITECTURE.md) |
| Обзор продукта | [MANIFORGE_PLATFORM_OVERVIEW.md](MANIFORGE_PLATFORM_OVERVIEW.md) |
| Термины | [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md) |
| Scope данных | [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md) |
| Delegation | [MANIFORGE_TENANT_DELEGATION.md](MANIFORGE_TENANT_DELEGATION.md) |
| Licensing | [MANIFORGE_TENANT_LICENSING_SERVICE.md](MANIFORGE_TENANT_LICENSING_SERVICE.md) |
| Credentials | [MANIFORGE_CREDENTIAL_ARCHITECTURE.md](MANIFORGE_CREDENTIAL_ARCHITECTURE.md) |
| Go-миграция | [MANIFORGE_GO_MIGRATION.md](MANIFORGE_GO_MIGRATION.md) |
| Карта Go-файлов | [MANIFORGE_GO_CODEMAP.md](MANIFORGE_GO_CODEMAP.md) |
| ADR | [docs/adr/README.md](adr/README.md) |
