# Maniforge Platform Core

[![CI — Go](https://github.com/Maniforge/Maniforge/actions/workflows/ci-go.yml/badge.svg)](https://github.com/Maniforge/Maniforge/actions/workflows/ci-go.yml)
[![CI — RBAC](https://github.com/Maniforge/Maniforge/actions/workflows/rbac-checks.yml/badge.svg)](https://github.com/Maniforge/Maniforge/actions/workflows/rbac-checks.yml)
[![Go 1.25](https://img.shields.io/badge/Go-1.25-00ADD8?logo=go&logoColor=white)](https://go.dev/)
[![PostgreSQL 16](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Release](https://img.shields.io/github/v/tag/Maniforge/Maniforge?label=v0.1.0-box&color=blue)](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.0-box)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)

**Production deployment package** for the Maniforge platform: API-first backend with multi-tenant RBAC, tenant licensing, Manifest Engine (entity → REST + OpenAPI), versioning, and realtime services. Delivered as reproducible source, install scripts, and operational runbooks — ready for on-premise deployment on your infrastructure.

| | |
|---|---|
| **Production Box (полная спецификация)** | [`docs/PRODUCTION_BOX.md`](docs/PRODUCTION_BOX.md) |
| Архитектура | [`docs/MANIFORGE_ARCHITECTURE.md`](docs/MANIFORGE_ARCHITECTURE.md) |
| Обзор платформы | [`docs/MANIFORGE_PLATFORM_OVERVIEW.md`](docs/MANIFORGE_PLATFORM_OVERVIEW.md) |
| OpenAPI | [`docs/openapi/`](docs/openapi/) |

**Репозиторий:** [github.com/Maniforge/Maniforge](https://github.com/Maniforge/Maniforge) · ветка **`platform-core`** · релиз **[`v0.1.0-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.0-box)**

---

## Что такое продаваемый деплой (Production Box)

**Production Box** — это готовый к установке комплект платформенного ядра Maniforge для развёртывания на сервере заказчика (on-premise). Покупатель получает исходный код, скрипты установки и проверки, конфигурацию шлюза и базы данных, а также документацию по эксплуатации. Установка выполняется на чистой Ubuntu 22.04/24.04 LTS за один проход: клонирование репозитория, настройка секретов, автоматическая сборка сервисов, миграции и запуск через systemd.

Комплект **не** является облачным SaaS и **не** включает прикладные модули (WMS, supply chain, `.mfpack`) — они поставляются отдельными фазами. Версия **v0.1.0-box** — первый коммерчески воспроизводимый релиз: проверен на staging, публичен на GitHub, готов к передаче DevOps-команде покупателя.

---

## Состав комплекта

| Включено | Не включено (v0.1.0-box) |
|----------|--------------------------|
| 5 Go-сервисов platform core (RBAC, Tenant Licensing, Manifest Engine, Versioning, Realtime) | App Store / runtime `.mfpack` |
| PostgreSQL 16 — primary + streaming replica | Модули supply chain (warehouses, WMS, inventory) |
| Caddy gateway (HTTPS по домену или staging по IP) | Managed SaaS / мульти-тенант хостинг |
| systemd unit-файлы с политикой перезапуска | Полный CI/CD pipeline заказчика |
| Скрипты `install-production.sh`, `verify-production.sh`, upgrade path | PHP reference stack |

Подробности, backup/restore, TLS и post-install checklist — в [`docs/PRODUCTION_BOX.md`](docs/PRODUCTION_BOX.md).

---

## Требования

| Ресурс | Минимум | Рекомендуется (до 50 пользователей) |
|--------|---------|-------------------------------------|
| CPU | 2 vCPU | 4 vCPU |
| RAM | 4 GB | 8 GB |
| Disk | 40 GB SSD | 80 GB SSD |
| OS | Ubuntu 22.04 или 24.04 LTS | то же |
| Сеть | 80, 443 (production) или 18090 (staging) | статический IP, DNS A-record |

Дополнительно на сервере: `git`, `sudo`, доступ в интернет для apt/docker при первой установке.

---

## Установка (greenfield)

Скопируйте блок целиком. Все команды — для чистого сервера Ubuntu.

```bash
git clone --branch platform-core https://github.com/Maniforge/Maniforge.git
cd Maniforge
# или сразу в целевой каталог:
# git clone --branch platform-core https://github.com/Maniforge/Maniforge.git /opt/maniforge/platform-core
# cd /opt/maniforge/platform-core

cp deploy/.env.platform.server.example deploy/.env.platform
# отредактируйте секреты в deploy/.env.platform — не коммитьте этот файл

sudo bash deploy/scripts/install-production.sh --skip-apt --non-interactive
bash deploy/scripts/verify-production.sh
```

**Production с HTTPS** (DNS A-record уже указывает на сервер):

```bash
sudo bash deploy/scripts/install-production.sh --domain platform.customer.ru
bash deploy/scripts/verify-production.sh
```

Скрипт установки **идемпотентен** — повторный запуск безопасен.

---

## Проверка работоспособности

После `verify-production.sh` ожидается: 6/6 systemd active, health всех сервисов через gateway, replica PostgreSQL в состоянии `streaming`.

| Профиль | URL health-check |
|---------|------------------|
| Production (HTTPS) | `https://<ваш-домен>/rbac/health` |
| Staging (IP, без TLS) | `http://<IP>:18090/rbac/health` |

Пример:

```bash
curl -sf https://platform.customer.ru/rbac/health
# или для staging:
curl -sf http://203.0.113.10:18090/rbac/health
```

Дополнительно: `make preflight`, `make rbac-journey` — см. [`deploy/README.md`](deploy/README.md).

---

## Состав платформы

```mermaid
flowchart LR
  Client[Clients / Integrations] --> GW[Caddy Gateway]
  GW --> RBAC[RBAC :8093]
  GW --> TL[Tenant Licensing :8094]
  GW --> ME[Manifest Engine :8095]
  GW --> VER[Versioning :8096]
  GW --> RT[Realtime :8097]
  RBAC --> PG[(PostgreSQL 16)]
  TL --> PG
  ME --> PG
  VER --> PG
  RT --> PG
```

| Сервис | Назначение |
|--------|------------|
| **RBAC** | Аутентификация, роли, политики, MFA, audit, 152-ФЗ hooks |
| **Tenant Licensing** | Тенанты, планы, квоты, lifecycle |
| **Manifest Engine** | Сущность → REST API + OpenAPI + UI-заготовки |
| **Versioning** | История изменений сущностей |
| **Realtime** | WebSocket / live-события |

| Слой | Технологии |
|------|------------|
| Runtime | Go 1.25, Fiber |
| БД | PostgreSQL 16 (Docker + streaming replica) |
| Gateway | Caddy (TLS auto или staging :18090) |
| Контракты | OpenAPI YAML |

---

## Документация

| Документ | Содержание |
|----------|------------|
| [`docs/PRODUCTION_BOX.md`](docs/PRODUCTION_BOX.md) | Спецификация Production Box, backup, upgrade |
| [`deploy/README.md`](deploy/README.md) | Операционные команды, server vs local |
| [`docs/MANIFORGE_ARCHITECTURE.md`](docs/MANIFORGE_ARCHITECTURE.md) | Слои, границы сервисов |
| [`docs/MANIFORGE_NEW_USER_WORKFLOW.md`](docs/MANIFORGE_NEW_USER_WORKFLOW.md) | Onboarding администратора |
| [`docs/README.md`](docs/README.md) | Индекс документации |

---

## Поддержка и лицензия

| | |
|---|---|
| GitHub | https://github.com/Maniforge/Maniforge |
| Issues | https://github.com/Maniforge/Maniforge/issues |
| Поддержка | **support@maniforge.ru** |
| Security | **support@maniforge.ru** · [`SECURITY.md`](SECURITY.md) |
| Contributing | [`CONTRIBUTING.md`](CONTRIBUTING.md) |

**Лицензия:** Apache License 2.0 — см. [`LICENSE`](LICENSE).

**Версия:** [`v0.1.0-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.0-box) · ветка `platform-core`

---

## Development

Локальная разработка (Docker, `make`, PHP journey-тесты, примеры) — в отдельном lab-репозитории [`Maniforge/maniforge_low_code_platform`](https://github.com/Maniforge/maniforge_low_code_platform). Для production-установки используйте только **Maniforge/Maniforge** `platform-core`.
