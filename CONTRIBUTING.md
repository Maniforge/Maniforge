# Contributing

Спасибо за интерес к Maniforge.

## Контакты

| | |
|---|---|
| Поддержка | **support@maniforge.ru** |
| Предложения / связь с разработчиками | **hello@maniforge.ru** |
| Security | **security@maniforge.dev** (см. [`SECURITY.md`](SECURITY.md)) |

## Перед началом

1. Прочитайте [`docs/MANIFORGE_GLOSSARY.md`](docs/MANIFORGE_GLOSSARY.md) — термины tenant / subtenant / grant.
2. Архитектура: [`docs/MANIFORGE_ARCHITECTURE.md`](docs/MANIFORGE_ARCHITECTURE.md).
3. Структура репо: [`STRUCTURE.md`](STRUCTURE.md).

## Локальная разработка

```bash
cp .env.example .env
make pg-up
make deps && make build && make migrate
make run-rbac   # :8093
make run-tl     # :8094
make health
make test
```

## Pull Request

1. Ветка от `master`: `feature/…` или `fix/…`
2. Один логический change-set; обновляйте docs при смене контрактов
3. Заполните [PR template](.github/PULL_REQUEST_TEMPLATE.md)
4. CI должен быть зелёным

## Стиль

- Go: стандартный `gofmt` / `go test`
- PHP-референс: сохраняйте паритет контрактов с Go при правках API
- Не коммитьте `.env`, бинарники `bin/`, секреты

## Первый коммит (maintainers)

Если на машине нет git identity:

```bash
git -c user.name="Maniforge" -c user.email="dev@maniforge.dev" commit -m "…"
```

Не используйте `--global` без явного решения команды.
