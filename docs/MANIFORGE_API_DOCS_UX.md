# UX страницы `/api` — план улучшений

Динамическая документация API: `templates/api.php`, JS в `public/assets/js/api-docs*.js`, стили в `public/assets/css/app.css`.

Порядок внедрения — **строго по номерам**. Статус обновляется при прохождении чеклиста.

| # | Улучшение | Статус | Файлы |
|---|-----------|--------|-------|
| 1 | Компактный layout (категория сверху, колонки, dock-footer) | ✅ | `api.php`, `app.css`, `api-docs.js` |
| 2 | Аккордеон профилей на «Заголовках» | ✅ | `api-docs-headers-panel.php`, `app.css` |
| 3 | Тонкий скроллбар при hover в колонках | ✅ | `app.css` |
| 4 | `localStorage`: последняя вкладка, «Все разделы» | ✅ | `api-docs.js` |
| 5 | Чип профиля заголовков в карточке endpoint | ✅ | `api-endpoint-doc.php`, `helpers.php`, `app.css` |
| 6 | Breadcrumbs в компактном режиме | ✅ | `api.php`, `api-docs-enhancements.js`, `app.css` |
| 7 | Поиск по документации / Ctrl+K | ✅ | `api.php`, `api-docs-tabs.php`, `api-docs-enhancements.js` |
| 8 | Сворачиваемый hero (после первого визита) | ✅ | `api.php`, `api-docs-enhancements.js` |
| 9 | Блок «С чего начать» | ✅ | `api-docs-headers-panel.php` |
| 10 | Mini-TOC на «Заголовках» | ✅ | `api-docs-headers-panel.php` |
| 11 | Символы профилей `MF_HEADER_*` (приглушённый стиль) | ✅ | `helpers.php`, `app.css` |
| 12 | Копирование набора заголовков профиля | ✅ | `api-docs-headers-panel.php`, `api-docs.js` |
| 13 | Диаграмма потока авторизации RBAC | ✅ | `api-docs-headers-panel.php` |
| 14 | Связь manifest `fields[]` ↔ REST POST | ✅ | `api-docs-manifest-fields.php`, `helpers.php` |
| 15 | Live Bearer из сессии RBAC | ✅ | `api-docs-manifest.js` |
| 16 | Scroll-spy (подсветка в сайдбаре) | ✅ | `api-docs-enhancements.js`, `api-docs-nav.php` |
| 17 | Мобильные вкладки «Разделы» / «Документ» | ✅ | `api.php`, `api-docs-enhancements.js` |
| 18 | Доступность (a11y): поиск с клавиатуры, фокус | ✅ | `api-docs-enhancements.js`, `api.php` |
| 19 | Print CSS | ✅ | `app.css` |
| 20 | Тёмная тема чипов/поиска (`prefers-color-scheme`) | ✅ | `app.css` |

## Проверка вручную

1. Открыть `/api`, прокрутить мимо hero → компакт, breadcrumbs, hover-скролл.
2. **Ctrl+K** → найти endpoint, Enter / клик → переход.
3. Вкладка **Заголовки** → аккордеон, TOC, «Скопировать набор заголовков».
4. Карточка метода → цветной чип профиля → якорь в «Заголовках».
5. **Персональные** → клик по полю в `fields[]` → подсветка в POST body.
6. Повторный визит → hero свёрнут (если не разворачивали вручную).
7. Узкий экран → переключатель «Разделы» / «Документ».
8. Печать (Ctrl+P) → без chrome, контент на страницах.

## Ключи localStorage

| Ключ | Назначение |
|------|------------|
| `api-docs-tab` | Последняя вкладка модуля |
| `api-docs-tabs-expanded` | Раскрыт ли список «Все разделы» в компакте |
| `api-docs-hero-collapsed` | Явный выбор пользователя: hero свёрнут (`1`) / развёрнут (`0`) |
| `api-docs-visited` | Был ли хотя бы один визит (для авто-свёртки hero) |
| `maniforge_admin_access_token` | Подстановка в Live OpenAPI (из `maniforge-session.js`) |

## Связанные документы

- [MANIFORGE_UI_STRATEGY.md](MANIFORGE_UI_STRATEGY.md) — стратегия UI
- [MANIFORGE_MANIFEST_ENGINE.md](MANIFORGE_MANIFEST_ENGINE.md) — Manifest Engine, OpenAPI export
- [MANIFORGE_CREDENTIAL_ARCHITECTURE.md](MANIFORGE_CREDENTIAL_ARCHITECTURE.md) — профили заголовков
