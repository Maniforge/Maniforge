# Структура репозитория

Единая точка входа для платформы Maniforge (раньше было размазано по `zaxar/test-calculation`, Desktop и Downloads).

```
maniforge_low_code_platform/
├── cmd/                  # Go entrypoints (rbac, tenant-licensing, manifest-engine, …)
├── internal/             # Go packages (ядро платформы)
├── migrations/pg/        # PostgreSQL-миграции (основной runtime)
├── bin/                  # Собранные бинарники (не в git)
├── maniforge/            # PHP-референс модулей (HTTP + journey-тесты)
├── app/Maniforge/        # PHP-классы референса (Controllers / Security / …)
├── public/               # PHP web root + собранный admin/scanner UI
├── templates/            # PHP-шаблоны лендинга и docs UI
├── frontend/             # Исходники React (admin, scanner)
├── docs/                 # Документация платформы
│   └── openapi/          # Канонические OpenAPI (RBAC, Tenant Licensing)
├── examples/             # Демо поверх платформы
│   ├── 00_access_desk/   # Пропуска
│   ├── 01_org_structure/ # Оргструктура компании
│   └── print_task/       # Демо заданий печати
├── config/               # PHP bootstrap / database
├── .cursor/rules/        # Правила агента Cursor
├── .github/workflows/    # CI
├── docker-compose.yml    # PostgreSQL :5433
├── Makefile              # build / migrate / run / health / journeys
├── .env.example          # Шаблон окружения
├── README.md             # Описание для GitHub
└── STRUCTURE.md          # Этот файл
```

## Два контура

| Контур | Роль | Где код |
|--------|------|---------|
| **Go + Fiber** | Основной runtime | `cmd/`, `internal/` |
| **PHP 8** | Референс контрактов и journey-тестов | `maniforge/`, `app/Maniforge/` |

Оба контура используют одну терминологию; Go — продакшн-путь. См. `docs/MANIFORGE_PLATFORM_OVERVIEW.md`.

## Порты (local)

| Сервис | Порт |
|--------|------|
| PHP web | `8092` |
| Go RBAC | `8093` |
| Tenant Licensing | `8094` |
| Manifest Engine | `8095` |
| Versioning | `8096` |
| Realtime | `8097` |
| PostgreSQL (docker) | `5433` |
