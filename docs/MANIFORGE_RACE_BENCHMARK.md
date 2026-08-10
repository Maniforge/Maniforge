# Maniforge — race condition и benchmark PostgreSQL

Инструмент: `cmd/racebench` (Go), аналог PHP `maniforge/rbac/tools/race_condition_check.php` для **PostgreSQL**.

```bash
make racebench
# или
./bin/maniforge-racebench -workers 32 -duration 5s -scenario session_touch_hot
```

## Сценарии

| Сценарий | Что меряем | Ожидаемое поведение |
|----------|------------|---------------------|
| `select_ping` | Baseline round-trip | Высокий QPS, без app-lock |
| `user_read` | SELECT user by scope | Масштабируется с workers |
| `session_touch_hot` | UPDATE **одной** сессии | **Сериализация** — низкий QPS |
| `session_touch_cold` | UPDATE разных сессий | QPS выше hot |
| `profile_upsert_hot` | UPSERT одного profile | Умеренная конкуренция |
| `security_bump_hot` | security_version++ | Hot row на `maniforge_users` |
| `access_state_read` | Licensing read | Read-mostly, JOIN без write |
| `invite_claim_race` | N параллельных `ClaimPendingByToken` | **Ровно 1** успех (FOR UPDATE) |
| `license_assign_race` | N параллельных `AssignLicense` | **1** active license в БД |

## Результаты (пример: local docker PG, 32 workers, 3s)

| Сценарий | QPS | p50 ms | Комментарий |
|----------|-----|--------|-------------|
| select_ping | ~33 600 | 0.8 | baseline |
| user_read | ~18 800 | 1.5 | read scale |
| session_touch_hot | ~1 240 | 20 | **hot row** |
| session_touch_cold | ~8 850 | 3.4 | spread |
| profile_upsert_hot | ~1 100 | 21 | UPSERT конкуренция |
| security_bump_hot | ~1 400 | 18 | user row |
| access_state_read | ~6 300 | 4.8 | licensing read |
| invite_claim_race | 32 burst | 50 | 1 claim OK |
| license_assign_race | 32 burst | — | до fix: **RACE** несколько active |

## Известные узкие места (блокировки)

1. **`session_touch` на hot row** — каждый `Authenticate` → `Touch` держит ROW EXCLUSIVE на одной строке; при высокой частоте `/me` один пользователь ограничивает QPS (~1000/p50_ms).
2. **`security_version` + `RevokeAllForUser`** — UPDATE user + массовый UPDATE sessions/refresh по `user_id` — длинная транзакция, блокирует сессии пользователя.
3. **`AssignLicense`** — было: revoke+insert без `FOR UPDATE` → несколько `active` (исправлено: lock tenant row + unique index `009_tl_one_active_license.sql`).
4. **Invite claim** — `FOR UPDATE` на invite; корректно, второй воркер ждёт lock.
5. **Нет `maniforge_rate_limits` в PG** — rate limit в Go не портирован; PHP race на rate-limit к PG не относится.

## PHP vs Go

| Проверка | PHP (`race_condition_check.php`) | Go (`racebench`) |
|----------|-----------------------------------|------------------|
| Rate limit atomic | MySQL | — (нет таблицы в PG) |
| License assign | MySQL | PostgreSQL |
| Invite register | MySQL full flow | PG invite claim only |
| Session touch QPS | — | PostgreSQL |

## HTTP QPS (отдельно)

`racebench` меряет **SQL/репозиторий**, не Fiber HTTP. Для end-to-end:

```bash
make run-rbac &
wrk -t4 -c32 -d10s http://127.0.0.1:8093/rbac/health
```

С Bearer `/api/v1/me` QPS ниже из-за auth + licensing + session touch.

## Интерпретация QPS

- **select_ping** на локальном PG (docker, 32 workers, 3s): ориентир **3k–15k** QPS (зависит от CPU/пула).
- **session_touch_hot**: ориентир **200–2000** QPS (p50 0.5–5 ms).
- **access_state_read**: **500–5000** QPS (несколько SELECT/JOIN).

Запускайте на своём железе; цифры в отчёте `racebench` — источник истины для вашего окружения.

## Мониторинг блокировок во время теста

`racebench` опрашивает `pg_locks WHERE NOT granted` каждые 50ms (счётчик `lock_waits`).

Ручная диагностика:

```sql
SELECT blocked.pid, blocked.query, blocking.pid, blocking.query
FROM pg_stat_activity blocked
JOIN pg_locks bl ON bl.pid = blocked.pid AND NOT bl.granted
JOIN pg_locks kl ON kl.locktype = bl.locktype AND kl.database IS NOT DISTINCT FROM bl.database
  AND kl.relation IS NOT DISTINCT FROM bl.relation AND kl.pid != bl.pid
JOIN pg_stat_activity blocking ON blocking.pid = kl.pid AND kl.granted;
```
