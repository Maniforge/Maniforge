# Supply chain на шлюзе и глубина API-тестов

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать 63 метода warehouses/products/inventory/wms доступными через Caddy наравне с 8093–8097 и заменить дымовые HTTP-тесты доменными (проводки, права, grant-peers, живой WS).

**Architecture:** Go-бинарники `:8098`–`:8101` уже есть (`cmd/warehouses|products|inventory|wms`). Их нет в Docker-образе, systemd и Caddy — catch-all отвечает «Maniforge platform», PHP `public/index.php` до клиента не доходит. Сначала вывести сервисы на шлюз (фаза A из PRODUCTION_PLAN, блок P2), затем углубить тесты без расширения поверхности API. PHP (`app/Maniforge`, `maniforge/`) не удалять.

**Tech Stack:** Go Fiber, PostgreSQL, Caddy, systemd, `internal/platform/apitest`, `go test -p 1` (гонка `CREATE DATABASE`).

## Global Constraints

- PHP-референс не удалять и не выключать из git: `app/Maniforge/*`, `maniforge/*`, `public/index.php`.
- Домен inventory: типы `receipt|issue|transfer|adjustment`; статусы `draft` / `posted`; `POST …/post` и `POST …/reverse`; остаток `qty` vs `qty_available` (резервы); soft-delete `NOT del` там, где колонка есть; SQL только с плейсхолдерами `$1…`.
- HTTP-тесты: `TENANCY_MODE=single`, сессия через `apitest.RegisterTenantAdmin`, без Postgres — `Skip`, не «зелёный pass».
- Коммиты только если явно попросил человек; шаги «Commit» ниже — чеклист, не автозапуск.
- Каталог `deploy/www-desk/assets/api-docs-catalog.json` — навигация desk (13 модулей), не матрица 197 методов. Не использовать его как Definition of Done по API.
- Не портировать PHP HTML (`GET /`, `/admin`, `/api-docs`) и TL form-алиасы `POST /admin/*`.

**Срез 2026-09-17:** 197 liveRoutes в 9 `http_api_test.go`; на шлюзе 134 (8093–8097); вне шлюза 63 (8098–8101). Аудит: канвас покрытия в Cursor.

---

## File map

| Файл | Зачем |
|------|--------|
| `deploy/Dockerfile.platform` | Собрать 4 бинарника supply chain |
| `deploy/compose.platform.yml` | Контейнеры + health + depends_on gateway |
| `deploy/Caddyfile`, `Caddyfile.server`, `Caddyfile.production` | `reverse_proxy` `/warehouses` `/products` `/inventory` `/wms` |
| `deploy/systemd/maniforge-{warehouses,products,inventory,wms}.service` | Host-run как RBAC |
| `deploy/scripts/server-build.sh`, `server-up.sh`, `verify-production.sh`, `lib/gateway-health.sh` | Сборка, старт, health |
| `Makefile` (`build`, `run-*`, `platform-health`) | Локальный контур |
| `internal/platform/apitest/client.go` | `AssertLiveRoutesExact` (Fiber ⊆ liveRoutes) |
| `internal/supplychain/guard.go` | Общий `ListGrantPeers` |
| `internal/{warehouses,products,inventory}/app.go` | Вызов общего grant-peers |
| `internal/inventory/http_api_test.go` | Недостаток остатка, reverse после transfer, draft |
| `internal/realtime/http_api_test.go` | Реальный WebSocket |
| `docs/PRODUCTION_PLAN.md`, `docs/MANIFORGE_GO_CODEMAP.md`, `docs/README.md` | Журнал и ссылки |

Не трогать: `DrvFRLib_TLB.pas` / Delphi ARM; PHP-роутеры HTML; манифест `/api/*` на 8095 (не пересекается с `/warehouses/api/v1`).

---

### Task 1: Supply chain на Caddy / systemd / Docker

**Files:**
- Modify: `deploy/Dockerfile.platform`
- Modify: `deploy/compose.platform.yml`
- Modify: `deploy/Caddyfile`, `deploy/Caddyfile.server`, `deploy/Caddyfile.production`
- Create: `deploy/systemd/maniforge-warehouses.service`, `maniforge-products.service`, `maniforge-inventory.service`, `maniforge-wms.service`
- Modify: `deploy/scripts/server-build.sh`, `server-up.sh`, `verify-production.sh`, `lib/gateway-health.sh`
- Modify: `Makefile` (`build`, `run-*`, `health`, `platform-health`)

**Interfaces:**
- Consumes: `cmd/*/main.go` уже слушают `MANIFORGE_WAREHOUSES_ADDR=:8098`, `PRODUCTS=:8099`, `INVENTORY=:8100`, `WMS=:8101`; health `GET /<prefix>/health` и `GET /health` на том же Fiber.
- Produces: gateway отдаёт 2xx на `/warehouses/health`, `/products/health`, `/inventory/health`, `/wms/health`.

- [x] **Step 1: Дописать сборку в Dockerfile и server-build**

В `deploy/Dockerfile.platform` после `maniforge-realtime` добавить четыре `go build` в тот же `RUN`:

```dockerfile
    CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/maniforge-warehouses ./cmd/warehouses && \
    CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/maniforge-products ./cmd/products && \
    CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/maniforge-inventory ./cmd/inventory && \
    CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/maniforge-wms ./cmd/wms
```

В `deploy/scripts/server-build.sh` и цель `build` в `Makefile` — те же четыре `-o bin/maniforge-* ./cmd/*`.

- [x] **Step 2: Caddy handles (три файла одинаково по смыслу)**

Вставить **до** финального `handle { respond … }`. Manifest оставить на `/api/*` — префиксы складов другие.

```caddy
	@warehouses path /warehouses /warehouses/*
	handle @warehouses {
		reverse_proxy 127.0.0.1:8098
	}

	@products path /products /products/*
	handle @products {
		reverse_proxy 127.0.0.1:8099
	}

	@inventory path /inventory /inventory/*
	handle @inventory {
		reverse_proxy 127.0.0.1:8100
	}

	@wms path /wms /wms/*
	handle @wms {
		reverse_proxy 127.0.0.1:8101
	}
```

В `deploy/Caddyfile` (compose) upstreams: `warehouses:8098`, `products:8099`, `inventory:8100`, `wms:8101`.

- [x] **Step 3: systemd-юниты**

Копия `deploy/systemd/maniforge-rbac.service`, заменить Description/ExecStart:

| Unit | ExecStart |
|------|-----------|
| `maniforge-warehouses.service` | `/opt/maniforge/platform-core/bin/maniforge-warehouses` |
| `maniforge-products.service` | `…/bin/maniforge-products` |
| `maniforge-inventory.service` | `…/bin/maniforge-inventory` |
| `maniforge-wms.service` | `…/bin/maniforge-wms` |

В `server-up.sh` и `verify-production.sh` массив `UNITS=(…)`: вставить четыре юнита **перед** `maniforge-caddy.service`. В `maniforge-caddy.service` `After=` можно добавить новые сервисы, чтобы Caddy поднимался после них.

- [x] **Step 4: compose.platform.yml**

Четыре сервиса по образцу `realtime` (health `wget` на `/warehouses/health` и т.д., порты 8098–8101). В `gateway.depends_on` — все четыре `service_healthy`.

- [x] **Step 5: Health-скрипты**

`lib/gateway-health.sh` — четыре `check`:

```bash
  check "/warehouses/health" "warehouses"
  check "/products/health" "products"
  check "/inventory/health" "inventory"
  check "/wms/health" "wms"
```

`Makefile` `platform-health` / `health`: `curl -sf` на 8098–8101 и на `http://127.0.0.1:8080/warehouses/health` (локальный compose) / `:18090` на сервере.

Цели `run-warehouses` … `run-wms` рядом с `run-realtime`.

- [ ] **Step 6: Проверка** — **SKIP (2026-09-17):** Docker Desktop daemon был down; `make platform-up` и curl через публичный Caddy/nzgapp не выполнялись.

```bash
make build
# локально:
make platform-up
make platform-health
curl -sf http://127.0.0.1:8080/warehouses/health
curl -sf http://127.0.0.1:8080/products/health
curl -sf http://127.0.0.1:8080/inventory/health
curl -sf http://127.0.0.1:8080/wms/health
```

Ожидание: JSON `{"ok":true,"service":"warehouses"}` (и аналоги). Сейчас без этих handle curl получит текст «Maniforge platform gateway».

На хосте nzgapp: `./deploy/scripts/server-build.sh` → `server-up.sh` → `verify-production.sh`. Не считать задачу закрытой, пока health не зелёный **через публичный Caddy**, а не только `127.0.0.1:8098`.

- [x] **Step 7: Документация деплоя**

Обновить `docs/PRODUCTION_PLAN.md` журнал (P2 частично закрыт: сервисы на шлюзе). `docs/MANIFORGE_GO_CODEMAP.md` — systemd-файлы. `deploy/README.md` — restart четырёх юнитов. Не писать, что PHP удалён.

- [ ] **Step 8: Commit** (только по просьбе)

```bash
git add deploy/Dockerfile.platform deploy/compose.platform.yml deploy/Caddyfile deploy/Caddyfile.server deploy/Caddyfile.production deploy/systemd/maniforge-warehouses.service deploy/systemd/maniforge-products.service deploy/systemd/maniforge-inventory.service deploy/systemd/maniforge-wms.service deploy/scripts Makefile docs/PRODUCTION_PLAN.md docs/MANIFORGE_GO_CODEMAP.md deploy/README.md
git commit -m "$(cat <<'EOF'
Put warehouses, products, inventory, and WMS on the Caddy gateway.

EOF
)"
```

---

### Task 2: Тест Fiber ⊆ liveRoutes

**Files:**
- Modify: `internal/platform/apitest/client.go` (после `FiberRouteSet`)
- Modify: все девять `internal/*/http_api_test.go` — функция `Test*FiberRegistersAllLiveRoutes`

**Interfaces:**
- Consumes: `FiberRouteSet(app) map[string]struct{}`, слайсы `*LiveRoutes`
- Produces: `AssertLiveRoutesExact(t, app, live []string)` падает, если Fiber шире списка

- [x] **Step 1: Хелпер**

```go
func AssertLiveRoutesExact(t *testing.T, app *fiber.App, live []string) {
	t.Helper()
	got := FiberRouteSet(app)
	want := map[string]struct{}{}
	for _, r := range live {
		want[r] = struct{}{}
		if _, ok := got[r]; !ok {
			t.Errorf("нет маршрута %s", r)
		}
	}
	for r := range got {
		if _, ok := want[r]; !ok {
			t.Errorf("лишний маршрут %s", r)
		}
	}
}
```

Игнорировать только уже отфильтрованные HEAD/OPTIONS внутри `FiberRouteSet`.

- [x] **Step 2: Заменить ручной цикл** в каждом `Test*FiberRegistersAllLiveRoutes` на `apitest.AssertLiveRoutesExact(t, app, xxxLiveRoutes)`.

- [x] **Step 3: Прогнать**

```bash
go test -p 1 ./internal/rbac ./internal/tenantlicensing ./internal/manifestengine ./internal/versioninghttp ./internal/realtime ./internal/warehouses ./internal/products ./internal/inventory ./internal/wms -count=1
```

Ожидание: PASS. Если всплыл «лишний маршрут» — либо добавить в liveRoutes **и** happy-path hit, либо удалить мёртвый `app.Get` из Fiber. Не расширять PHP HTML.

- [ ] **Step 4: Commit** (по просьбе) — `test: fail when Fiber grows past liveRoutes`.

---

### Task 3: grant-peers из TL, не пустой массив

**Files:**
- Modify: `internal/supplychain/guard.go` — `ListGrantPeers(db *sql.DB, principalTenant string) ([]map[string]any, error)`
- Modify: `internal/warehouses/app.go` `GrantPeers` — вызов хелпера, **не** глотать `Query` ошибку в `200 + items:[]`
- Modify: `internal/products/app.go`, `internal/inventory/app.go` — тот же хелпер вместо stub
- Modify: `internal/warehouses/http_api_test.go` (или отдельный `grant_peers_test.go`) — создать grant через TL, затем `GET …/delegation/grant-peers` не пустой
- PHP эталон: `EntityDelegationShareService::listActiveGrantPeers`; таблица `maniforge_tl_tenant_grants` (`principal_tenant_code`, `managed_tenant_code`, `grant_level`, `status='active'`)

**Interfaces:**
- Consumes: сессия tenant_admin; `HasAdminRole`; SQL как сейчас в warehouses
- Produces: `{ok:true, items:[{tenant_id, grant_level, status}, …]}`; не-admin → 403 «Требуется tenant_admin»; ошибка SQL → 500, не маскировать пустым списком

- [x] **Step 1: Тест, который сейчас красный**

Зарегистрировать tenant A (admin), через TL создать managed tenant + active grant, затем:

```go
out := wh.MustOK("GET", "/api/v1/delegation/grant-peers", nil)
items := out["items"].([]any)
if len(items) < 1 {
    t.Fatalf("ожидали peer из TL grant, получили %v", out)
}
```

Тот же сценарий для products и inventory (один хелпер в apitest, три вызова). Не-admin пользователь → 403.

- [x] **Step 2: Реализация**

Вынести запрос из `warehouses.Handler.GrantPeers` (строки ~181–197) в `supplychain.ListGrantPeers`. Warehouses/products/inventory:

```go
items, err := supplychain.ListGrantPeers(h.db, sess.TenantID)
if err != nil {
    return httpx.Fail(c, 500, err.Error())
}
return httpx.OK(c, fiber.Map{"ok": true, "items": items})
```

Не возвращать 200 при ошибке Query.

- [x] **Step 3:**

```bash
go test -p 1 ./internal/warehouses ./internal/products ./internal/inventory -count=1
```

Ожидание: PASS, grant-peers не пустой при живом grant.

- [ ] **Step 4: Commit** (по просьбе).

---

### Task 4: Домен inventory (проводки / undo / остаток)

**Files:**
- Modify: `internal/inventory/http_api_test.go` (новые `TestInventory*`, не раздувать один `TestInventoryHTTPAllLiveMethods` бесконечно)
- Read-only: `internal/inventory/posting.go` (`Post`, `Reverse`, `assertSufficient`, `qtyOnHand` / `qtyReserved`)
- Эталон: `docs/MANIFORGE_INVENTORY.md`, PHP `app/Maniforge/Inventory/Security/InventoryPostingService.php`

**Interfaces:**
- Consumes: тот же HTTP, что liveRoutes: `POST /api/v1/movements`, `…/post`, `…/reverse`, `GET /api/v1/balances`
- Produces: инварианты ниже. Не менять семантику ради зелёного теста — чинить код, если расходится с PHP/докой.

Кейсы (каждый — отдельный `func TestInventory…`):

1. **Issue без остатка** → 409, в теле `insufficient` / `Недостаточно`; `GET balances` не ушёл в минус.
2. **Receipt 5, issue 3, reverse issue** → остаток снова 5; повторный reverse того же id → 409 (уже есть кусок в дымовом тесте — вынести/повторить явно).
3. **Transfer A→B, затем reverse transfer** → qty на A восстановлен, B не отрицательный. Сейчас дымовой тест специально reverse **до** transfer, потому что иначе минус — это баг или дыра теста; закрыть по правде.
4. **Draft** `post_immediately: false` → status draft, balances не меняются; `POST …/post` проводит; `DELETE` черновика ок, `DELETE` posted → ошибка.
5. **Reserve:** `POST /reserves` больше `qty_available` → 409 `insufficient_available`; после reserve issue на весь on-hand без учёта reserve → 409.
6. **Adjustment** до целевого qty и reverse adjustment.

Фикстура как в текущем тесте: склады через `warehouses.NewApp`, товар через `products.NewApp`, телефон `apitest.RegisterTenantAdmin` с уникальным prefix.

- [x] **Step 1:** Написать тесты 1–6. Запустить — часть должна упасть (особенно 3, если reverse-after-transfer ломает остаток).
- [x] **Step 2:** Чинить только `internal/inventory/posting.go` / `extras.go` (резервы). Не ослаблять `assertSufficient`.
- [x] **Step 3:**

```bash
go test -p 1 ./internal/inventory -count=1 -timeout 60s
```

- [ ] **Step 4:** Commit (по просьбе) — `fix: inventory reverse/transfer and insufficient stock`.

---

### Task 5: Негативная матрица HTTP (403 / 422 / CSRF)

Не дублировать все 197 методов. По одному каноническому мутатору на сервис + RBAC CSRF.

**Files:**
- Create: `internal/platform/apitest/negatives.go` — хелперы `MustStatus`, пользователь без роли
- Modify: `internal/rbac/http_api_test.go` — POST без CSRF → 403/419 как в живом middleware
- Modify: `internal/{warehouses,products,inventory,wms,tenantlicensing}/http_api_test.go` — 403 без permission, 422 пустое тело create

Минимум:

| Сервис | Без permission | Валидация |
|--------|----------------|-----------|
| warehouses | user без `warehouses.write` → POST `/api/v1/stocks` 403 | POST без `name`/`type` 422 |
| products | без `products.write` → POST product 403 | POST без `name` 422 |
| inventory | без `inventory.write` → POST movement 403 | POST без `movement_type` 422 |
| wms | без `wms.write` → POST pack 403 | POST pack без `unit_type` 422 |
| rbac | POST `/api/v1/auth/logout` с валидным Bearer и **пустым** CSRF | статус как в коде (не 200) |

Как создать user без права: зарегистрировать второго пользователя в том же tenant **без** назначения `tenant_admin` / write-ролей (роль из `005_rbac_roles.sql`, не выдуманный `moderator`).

- [x] **Step 1:** Тесты.
- [x] **Step 2:** Если 200 вместо 403 — дыра Guard/permission seed (`migrations/pg/`), чинить seed или `supplychain.Guard`, не тест.
- [x] **Step 3:** `go test -p 1 ./internal/rbac ./internal/warehouses ./internal/products ./internal/inventory ./internal/wms -count=1`
- [ ] **Step 4:** Commit (по просьбе).

---

### Task 6: Realtime — живой WebSocket

**Files:**
- Modify: `internal/realtime/http_api_test.go`
- Read: `internal/realtime/handler/ws.go`, `internal/realtime/app.go` (`GET /ws`)

Сейчас `GET /realtime/ws` без Upgrade принимается как 426/400/401. Это не сессия.

- [x] **Step 1:** Поднять `rtApp.Listen("127.0.0.1:0")` в горутине (или `net.Listen` + `app.Listener`), взять порт.
- [x] **Step 2:** Dial `ws://127.0.0.1:<port>/realtime/ws` с `Authorization: Bearer <access>` (тот же токен, что HTTP-тест). Ожидание: handshake 101.
- [x] **Step 3:** `POST /realtime/internal/v1/broadcast` (как в liveRoutes) → клиент читает кадр за 2s. Без токена dial → отказ (401 в тесте не зафиксирован — minor).
- [x] **Step 4:** `go test -p 1 ./internal/realtime -count=1 -timeout 30s`
- [ ] **Step 5:** Commit (по просьбе).

Не подменять это ещё одним `app.Test` GET без Upgrade.

---

### Task 7: Документы и Definition of Done

**Files:**
- Modify: `docs/PRODUCTION_PLAN.md` — журнал, P2: «HTTP на шлюзе есть; глубина тестов — этот план Tasks 2–6»
- Modify: `docs/README.md` — ссылка на этот файл
- Modify: `docs/MANIFORGE_INVENTORY.md` только если поведение posting изменилось в Task 4
- Не раздувать `api-docs-catalog.json` до 197 методов (это nav)

- [x] **Step 1:** Журнал `PRODUCTION_PLAN.md`, ссылка в `docs/README.md`, примечание Go/PHP reverse adjustment в `MANIFORGE_INVENTORY.md`.
- [x] **Step 2:** Чекбоксы Tasks 1–6 в этом файле; Task 1 Step 6 оставлен открытым (SKIP Docker).

Definition of Done всего плана:

1. `curl` публичного origin `/warehouses/health` … `/wms/health` → 2xx JSON.
2. `go test -p 1 ./internal/rbac ./internal/tenantlicensing ./internal/manifestengine ./internal/versioninghttp ./internal/realtime ./internal/warehouses ./internal/products ./internal/inventory ./internal/wms -count=1` зелёный **с Postgres**.
3. Fiber не шире liveRoutes.
4. grant-peers возвращает TL grant.
5. Inventory: нет отрицательного остатка после issue/transfer/reverse в тестах Task 4.
6. PHP на месте.

Вне скоупа (не делать в этом плане): HTML PHP admin, `.mfpack` (фаза D PRODUCTION_PLAN), удаление `public/index.php`, полный CSRF на все 76 RBAC методов, каждая сущность custom-manifest `/api/data/:entity`.

---

## Порядок и оценка

| Task | Зачем сначала | Зависимости |
|------|----------------|-------------|
| 1 шлюз | 63 метода не существуют на nzgapp.ru | нет |
| 2 exact routes | ловит случайные `app.Get` | нет, можно параллельно с 1 |
| 3 grant-peers | UI делегирования врёт пустым списком | TL таблицы; лучше после 1 если проверять через Caddy |
| 4 inventory | домен ARM/постеры | нет |
| 5 403/422 | дыры Guard | 4 не блокирует |
| 6 WS | realtime сейчас не проверен | нет |
| 7 docs | фиксирует DoD | после 1–6 |

Параллелить можно 2+4+5+6, пока 1 идёт на сервере. Task 3 трогает те же `app.go`, что 5 — не параллелить в одном дереве.

## Self-review

- Спека аудита (шлюз, глубина тестов, stub peers, WS, catalog≠methods, PHP keep) закрыта Tasks 1–7.
- Нет TBD/«добавить валидацию» без файла и команды.
- Имена: `ListGrantPeers`, `AssertLiveRoutesExact`, порты 8098–8101 совпадают с `internal/config/config.go`.
