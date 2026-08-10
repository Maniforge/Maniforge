# ADR-0005: Разделение users и user_profile

- **Статус:** accepted
- **Дата:** 2026-06-08

## Контекст

Смешение identity (phone, password) и UI-профиля (display name) приводило к непредсказуемому logout и путанице в API `updateProfile`.

## Решение

| Таблица | Поля | Поведение при UPDATE |
|---------|------|----------------------|
| `maniforge_users` | login, phone, email, password_hash, mfa_required, status | `security_version++`, `RevokeAllForUser` |
| `maniforge_user_profile` | display_name, avatar_url, bio, locale, timezone | Без отзыва сессий |

Go API:

- `PATCH /me/profile` → profile
- `PATCH /me/identity`, `POST /me/change-password` → users + revoke

## Последствия

- PHP `AuthController::updateProfile` (email/phone без revoke) — технический долг; выровнять с Go.
- Сессии хранят `security_version_snapshot` — двойная защита вместе с явным revoke.

## Альтернативы (отклонённые)

- Одна таблица users — нельзя разделить «мягкие» и «жёсткие» изменения.
- Revoke только по password — недостаточно при смене phone/email.
