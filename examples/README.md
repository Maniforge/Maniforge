# Examples

Демонстрации **поверх** self-hosted Maniforge (не ядро платформы).

| Пример | Описание |
|--------|----------|
| [`00_access_desk/`](00_access_desk/) | **Access Desk** — временные пропуска (RBAC + Manifest Engine). [`marketing.md`](00_access_desk/marketing.md) · [`technical.md`](00_access_desk/technical.md) |
| [`print_task/`](print_task/) | UI заданий печати + калькулятор | `cd print_task && php -S 127.0.0.1:8765 -t .` |

## Соглашение по структуре

Нумерованные кейсы платформы:

```
examples/00_name/
  marketing.md    # зачем модуль бизнесу
  technical.md    # как развернуть и вызвать API
  files/          # manifest, scripts, ui, env.example
```
