# print_task

Модуль заданий печати с калькулятором ввода количества.

**Стек:** PHP 8 · jQuery 3.7 · Bootstrap 5.3

## Структура

```
print_task/
├── index.php              # главная страница
├── api/
│   ├── order-info.php     # POST → JSON { html } (модалка заказа)
│   └── calculator.php     # POST → JSON { html } (фрагмент калькулятора)
├── assets/
│   ├── css/app.css
│   └── js/app.js
├── config/app.php
├── src/
│   ├── bootstrap.php
│   ├── TaskRepository.php
│   ├── TaskService.php
│   ├── OrderService.php
│   └── helpers.php
├── templates/             # PHP-шаблоны (Bootstrap)
└── function/              # mock_data, заглушки БД (PRINT_TASK_STUB)
```

## Запуск

```bash
cd print_task
php -S 127.0.0.1:8765 -t .
```

| URL | Экран |
|-----|--------|
| http://127.0.0.1:8765/ | Список заданий |
| http://127.0.0.1:8765/?iwi=501 | Карточка + выбор участка |
| http://127.0.0.1:8765/?iwi=501&iwp=6 | Калькулятор |

Двойной клик по заданию в списке — модалка заказа (Bootstrap).

## Режим БД

`function/stub.php` → `PRINT_TASK_STUB = false`, раскомментировать `connect_*.php`.

Старые пути `workspace/test2.php`, `collections/info_show.php` перенаправлены на новые.
