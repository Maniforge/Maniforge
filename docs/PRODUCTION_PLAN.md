# Maniforge — план до production (рабочий)

**Цель:** довести low-code платформу до уровня, когда на её «лего» можно спокойно собирать продукты (avtosbor, devent — позже).

**Репозиторий:** [github.com/Maniforge/Maniforge](https://github.com/Maniforge/Maniforge) — ветка `platform-core`, релиз [`v0.1.1-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.1-box).

---

## 1. Как платформа устроена (5 минут на вспоминание)

### Ментальная модель

```text
Клиент (браузер / API)
        ↓
   HTTP + cookie/session
        ↓
┌───────────────────────────────────────────┐
│  Платформа (обязательный фундамент)      │
│  RBAC :8093        — кто ты, права       │
│  Tenant Licensing :8094 — план, квоты    │
│  Manifest Engine :8095 — сущности → API  │
│  Versioning :8096  — история изменений   │
│  Realtime :8097    — WS уведомления      │
└───────────────────────────────────────────┘
        ↓ tenant_id + project_id
┌───────────────────────────────────────────┐
│  Доменные модули (кубики supply chain)    │
│  Warehouses, Products, Inventory, WMS     │
│  (каждый — cmd/* + internal/* + journey)  │
└───────────────────────────────────────────┘
        ↓
   PostgreSQL (одна БД, строки tenant scope)
```

### Два контура (не смешивать в голове)

| | **Go** — продакшн-путь | **PHP** — референс |
|---|------------------------|-------------------|
| Код | `cmd/`, `internal/` | `maniforge/`, `app/Maniforge/` |
| БД | PostgreSQL `migrations/pg/` | MySQL (legacy) |
| Зачем PHP | Journey-тесты = контракт «как должно быть» | |

**Правило:** если Go и PHP расходятся — эталон PHP до полного паритета, потом PHP только регрессия.

### Три оси данных (частая путаница)

1. **tenant** — организация (клиент SaaS)
2. **project** — операционный контур внутри tenant (склад, магазин)
3. **subtenant_id** = workspace (отдел), **не** «клиент оператора»

Подробно: [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md)

### Manifest Engine vs модули

| | Manifest Engine | Доменный модуль (warehouses) |
|---|-----------------|------------------------------|
| Что даёт | Описал JSON → получил CRUD API + OpenAPI | Готовая бизнес-логика (остатки, склады) |
| Когда | Свои сущности клиента | WMS, каталог, интеграции |
| Код | Минимум | Отдельный `cmd/warehouses` |

---

## 2. Быстрый старт (если забыл руки)

```bash
git clone --branch platform-core https://github.com/Maniforge/Maniforge.git
cd Maniforge
cp deploy/.env.platform.example deploy/.env.platform
make platform-up
make platform-health
```

**Терминал 1:** `make run-tl`  
**Терминал 2:** `make run-rbac`  
**Терминал 3:** `make run-manifest` (опционально)

Проверка:

```powershell
make health
make rbac-journey          # полный сценарий нового пользователя
make manifest-journey      # Manifest Engine e2e
make warehouses-journey    # supply chain склад
```

Всё зелёное → платформа жива, можно работать.

---

## 3. Где мы сейчас (честно)

### ✅ Уже сильное

- RBAC Go: auth, sessions, profile, CSRF, admin journeys
- Tenant Licensing: read + internal access-state + platform ops journey
- Manifest Engine: фазы 0–6 (CRUD, audit, versioning hook, OpenAPI, Refine scaffold)
- Warehouses Go + journey
- Products, Inventory — бинарники есть, journeys частично
- Документация: ARCHITECTURE, ADR 0001–0009, ENTERPRISE_HARDENING
- CI на GitHub (go + rbac-checks)

### 🟡 «Пицца», не App Store (осознанно откладываем)

- Все сервисы в одном репо, `make run-*` вручную
- `docker-compose` только PostgreSQL
- Нет runtime-установки модулей (это **фаза 2** после production core)

### 🔴 До production не хватает

| # | Блок | Зачем |
|---|------|-------|
| P1 | **Единый deploy** (compose/k8s всех Go-сервисов + gateway) | Не 5 терминалов вручную |
| P2 | **Supply chain паритет** (products, inventory, wms journeys на Go) | WMS-кубики готовы |
| P3 | **Scheduler** (TL expire, dispatch events, SIEM) | Lifecycle в prod |
| P4 | **Preflight + hardening** в CI/CD | `make preflight`, ENTERPRISE_HARDENING |
| P5 | **Наблюдаемость** (health all, metrics, логи) | Дежурство без боли |
| P6 | **Backup/restore drill** | `make backup-drill` в регламенте |

---

## 4. Пошаговый план (с чувством, толком, с расстановкой)

Один фокус за раз. Не распыляться на avtosbor/devent до закрытия **фазы A**.

### Фаза A — «Поднял одной командой» (1–2 недели)

**Цель:** `docker compose up` поднимает platform core.

1. `deploy/compose.platform.yml`:
   - postgres, rbac, tenant-licensing, manifest-engine, versioning, realtime
   - reverse proxy (Caddy) с маршрутами `/rbac`, `/tenant-licensing`, `/api/data`
2. Healthcheck на каждый сервис
3. `.env.production.example` + `make preflight` в entrypoint
4. Документ `deploy/README.md` — одна страница «как поднять»

**Критерий готовности:** на чистой машине `compose up` → `make rbac-journey` проходит.

### Фаза B — Supply chain на Go (2–3 недели)

**Цель:** цепочка warehouses → products → inventory → wms с journeys.

Порядок (как в [MANIFORGE_GO_MIGRATION.md](MANIFORGE_GO_MIGRATION.md)):

1. `make products-journey` (добавить/дожать)
2. `make inventory-journey` ✅ есть
3. WMS module journey
4. Включить все четыре в compose

**Критерий:** `make enterprise-journey` или новый `make supply-chain-journey` зелёный.

### Фаза C — Production hardening (1–2 недели)

По [MANIFORGE_ENTERPRISE_HARDENING.md](MANIFORGE_ENTERPRISE_HARDENING.md):

1. `APP_ENV=production`, токены, PII encryption
2. Cron/systemd для TL jobs
3. `make backup-drill` по регламенту
4. CI: preflight + journeys на PR

### Фаза D — Module Package (лего-конструктор, после A–C)

Только когда core стабилен:

1. ADR-0010: формат `.mfpack` (manifest.yaml + image + migrations + openapi)
2. Registry API (install/enable/disable)
3. Gateway dynamic routes
4. UI каталог (из `maniforge-modules.php` → runtime)

**Тогда** avtosbor = первый внешний `.mfpack`, не форк.

---

## 5. Что читать, когда забыл контекст

| Вопрос | Документ |
|--------|----------|
| Что за продукт? | [MANIFORGE_PLATFORM_OVERVIEW.md](MANIFORGE_PLATFORM_OVERVIEW.md) |
| Границы сервисов? | [MANIFORGE_ARCHITECTURE.md](MANIFORGE_ARCHITECTURE.md) |
| Как портировать? | [MANIFORGE_GO_MIGRATION.md](MANIFORGE_GO_MIGRATION.md) |
| Где какой файл? | [MANIFORGE_GO_CODEMAP.md](MANIFORGE_GO_CODEMAP.md) |
| Manifest? | [MANIFORGE_MANIFEST_ENGINE.md](MANIFORGE_MANIFEST_ENGINE.md) |
| Prod checklist? | [MANIFORGE_ENTERPRISE_HARDENING.md](MANIFORGE_ENTERPRISE_HARDENING.md) |
| UI стратегия? | [MANIFORGE_UI_STRATEGY.md](MANIFORGE_UI_STRATEGY.md) |

---

## 6. Следующий конкретный шаг (сегодня)

1. Клонировать `Maniforge/Maniforge` ветку `platform-core`
2. Выполнить «Быстрый старт» (§2)
3. Прогнать `make rbac-journey` и `make manifest-journey`
4. Если падает — чиним, не откладываем
5. Начать **Фазу A**: первый черновик `deploy/compose.platform.yml`

Не трогать nzgapp.ru и avtosbor и devent до зелёного `compose up` + journeys.

---

## 7. Журнал прогресса

| Дата | Сделано | Следующий шаг |
|------|---------|---------------|
| 2026-08-28 | План зафиксирован | Фаза A: compose.platform.yml |
| 2026-08-28 | Фаза A черновик: `deploy/compose.platform.yml`, Caddy, Dockerfile, `make platform-up` | Прогнать `make platform-journey` локально |
