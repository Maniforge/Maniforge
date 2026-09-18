# svit-wms-system

**WMS + Wildberries** для склада Svitex: учёт остатков, наборки (сканеры/ТСД), заявки клиентам, синхронизация с WB Seller API.

| | |
|---|---|
| **Prod** | https://devent.svitex.ru |
| **Sandbox API** | https://devent.svitex.ru/sandbox/docs (`docs/SANDBOX_API.md`) |
| **OpenAPI** | `/docs` (локально / после входа на проде) |
| **Стек** | Python 3.12 · FastAPI · SQLite (+ опционально MySQL/MariaDB) · static HTML/JS · PHP ASGI-oneshot на shared hosting |

---

## Что умеет система

### Склад (WMS)
- Склады, номенклатура, остатки: **свободно = остаток − набрано**
- Приход / расход / инвентаризация
- **Заявки (счета)** с клиентом из справочника (`client_id` → `wms_clients`)
- Редактирование счёта: убрать позицию или уменьшить qty (`DELETE` / `PATCH …/items/{id}`)
- Выполнение заявки → списание; отмена

### Сканеры / ТСД
- Батч-пик по заявке: `POST /api/v1/wms/{order_id}/pick/{warehouse_id}`
- Пик увеличивает **наборку** (`assembly_qty` / legacy `packed`), физический `quantity` не трогает
- Идентификация строки: `product_id` **или** `barcode` **или** `supplier_article`
- Контракт: [`docs/SCANNER_API.md`](docs/SCANNER_API.md) · ADR: [`kb/adr/004-scanner-orders.md`](kb/adr/004-scanner-orders.md)

### Wildberries
- Буфер/синк остатков, отчёты analytics (warehouse-remains и др.)
- Опционально Maniforce MariaDB (`wb/maniforce/`)

### Админка
- Админ входит по **`ADMIN_ACCESS_KEY`** (один ключ из `.env`) — регистрация сотрудников, склады, settings
- Сотрудник склада — **логин/пароль** → cookie-сессия → работа в `/app/*`

---

## Авторизация (важно)

**Нет** персонального API-ключа у сотрудника.

```text
Админ:     POST /api/admin/login-key  + ADMIN_ACCESS_KEY  → cookie wb_admin_session
Сотрудник: POST /api/admin/login      + username/password → cookie wb_admin_session
API:       запросы с Cookie (credentials: include)
```

| Роль | Доступ |
|------|--------|
| `admin` | `/api/admin/*`, settings, склады; **не** складские операции / сканер |
| `user` (сотрудник) | `/api/v1/wms/*`, UI склада `/app/store`, `/app/wms` |
| гость | `/login`, login API; остальное → 401 / редирект |

Для ТСД сейчас нужна сессия как у браузера (cookie). Отдельный API key для устройств — в планах (см. ADR-004).

---

## Структура репозитория

```text
wb/                 FastAPI-приложение (WMS, WB, admin, jobs)
web/                исходники UI (admin login + app)
public/             document root хостинга (index.php + синк статики из web/)
asgi_oneshot.py     PHP → один HTTP-запрос = один Python-процесс
deploy/             helpers shared-hosting
sql/                схемы SQLite / MySQL
docs/               контракты API (сканер)
kb/                 ADR, оркестрация Maniforge, карта проекта
scripts/            sync-public-static, verify notes
tests/              unittest (пик/наборка, заявки)
.cursor/            агенты /maniforge
```

Карта ownership: [`kb/projects.md`](kb/projects.md) · ADR: [`kb/adr/`](kb/adr/).

---

## Быстрый старт (локально)

```powershell
cd <repo>
python -m venv .venv
.\.venv\Scripts\pip.exe install -r requirements.txt
copy .env.example .env.app   # заполнить ключи (не коммитить)
$env:WB_ONESHOT = "0"
$env:PYTHONPATH = (Get-Location).Path
.\.venv\Scripts\uvicorn.exe wb.rest.app:app --host 127.0.0.1 --port 8000 --reload
```

Открыть: http://127.0.0.1:8000/login  

1. Войти **админом** (ключ `ADMIN_ACCESS_KEY`) → создать сотрудника  
2. Выйти → войти сотрудником → `/app/store` / `/app/wms`

### Тесты

```powershell
$env:PYTHONPATH = (Get-Location).Path
$env:WMS_DB_ENABLED = "0"
.\.venv\Scripts\python.exe -m unittest tests.test_scanner_pick_load -v
```

### Статика под Apache (`public/`)

После правок в `web/`:

```powershell
.\scripts\sync-public-static.ps1
# или: bash scripts/sync-public-static.sh
```

Не затирает `public/index.php` и `public/.htaccess`.

---

## Ключевые API (WMS)

Базовый префикс: `/api/v1/wms` (нужна сессия сотрудника).

| Метод | Путь | Назначение |
|-------|------|------------|
| GET/POST | `/clients` | Справочник клиентов |
| GET/POST | `/orders` | Список / создать заявку (`client_id` обязателен) |
| DELETE | `/orders/{id}/items/{item_id}` | Убрать позицию из счёта |
| PATCH | `/orders/{id}/items/{item_id}` | `{ "quantity": N }` (0 = удалить) |
| POST | `/orders/{id}/complete` · `/cancel` | Выполнить / отменить |
| POST | `/{order_id}/pick/{warehouse_id}` | Сканер: батч → набрано |
| GET | `/stock` · `/warehouses` · `/products` | Остатки и справочники |

Полный OpenAPI: `/docs`.

---

## Прод (Reg.ru shared hosting)

| Параметр | Значение |
|----------|----------|
| Домен | https://devent.svitex.ru |
| Код на сервере | `/var/www/u1688586/data/www/ent-dev-artem` |
| Document root | `public/` |
| Статика | Apache (`public/app/`, `login.html`) + Cache-Control |
| API | `public/index.php` → `.venv` + `asgi_oneshot.py` (**без** постоянного uvicorn — политика хостинга) |

Деплой (кратко): бэкап → залить код (без `.venv`/`.env`/sqlite) → при необходимости `pip install -r requirements.txt` → `sync-public-static` → smoke `/login` + один API.  
План масштабирования: [`kb/adr/002-scale-and-deploy.md`](kb/adr/002-scale-and-deploy.md).  
SSH: [`SSH-ПОДКЛЮЧЕНИЕ.md`](SSH-ПОДКЛЮЧЕНИЕ.md) (пароли только в локальном `.env`, не в git).

---

## Переменные окружения

См. [`.env.example`](.env.example). Секреты — только локально / на сервере:

| Группа | Примеры |
|--------|---------|
| Admin | `ADMIN_ACCESS_KEY`, `ADMIN_SESSION_SECRET`, `ADMIN_COOKIE_SECURE` |
| WB | `WB_API_TOKEN`, `WB_DEMO`, пути БД |
| WMS MySQL | `WMS_DB_ENABLED`, `WMS_DB_*` |
| WMS behavior | `WMS_AUTO_INVENTORY=1` — при нехватке свободно: инвентаризация до нужного qty, затем расход/пик |
| Maniforce | `MARIADB_*` |

На хостинге часто используют `.env` / `.env.app` (оба в `.gitignore`).

---

## Модель данных заявок (кратко)

```text
wms_clients (id, name, inn, is_active)
     ↑
wms_orders.client_id   + items[] (product_id, quantity)
```

В заявке **не** хранится свободный текст фирмы — только `client_id`; имя отдаётся join’ом как `client_name`.

Остатки: `quantity` (физический), `assembly_qty` / `packed` (набрано), `free_qty = quantity − assembly`.

---

## Разработка с Maniforge

В репозитории лежит pack оркестрации агентов:

- Команда: `/maniforge` (`.cursor/commands/maniforge.md`)
- Правила: [`kb/ORCHESTRATION.md`](kb/ORCHESTRATION.md)
- Роли: `.cursor/agents/*`

Маршруты: `tiny` · `single-area` · `eng-full` · `product` · `product→eng`.

---

## Лицензия / статус

Внутренний продукт Maniforge / Svitex. Репозиторий: [Maniforge/svit-wms-system](https://github.com/Maniforge/svit-wms-system).

Вопросы по прод-доступу и секретам — вне git (ISPmanager / локальный `.env`).
