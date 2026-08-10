# Соответствие 152-ФЗ «О персональных данных»

Документ — единая карта соответствия для Maniforge (RBAC, Tenant Licensing, versioning).  
Статус: **фазы 1–4 в коде**; фаза 5 — шаблоны и подписание; SaaS-модель «обработчик» — `docs/MANIFORGE_PD_PROCESSOR_PLATFORM.md`.

## Роли в обработке

| Роль | Кто в продукте | Ответственность |
|------|----------------|-----------------|
| **Оператор ПДн** | Клиент по подписке (tenant), `maniforge_pd_operator_profiles` | Цели, политика, согласия, ответы субъектам, уведомление РКН |
| **Обработчик ПДн** | Maniforge (`RBAC_PLATFORM_PROCESSOR_*`, DPA) | Ст. 19: шифрование, изоляция, audit; обработка по поручению оператора |
| **Субъект ПДн** | Пользователь `maniforge_users` | Права ст. 14 через API запросов |

## Матрица: статья закона → реализация

| Статья / требование | Статус | Где в проекте |
|---------------------|--------|---------------|
| **Ст. 5** — принципы (законность, минимизация, актуальность) | Частично | Цели в `maniforge_pd_processing_purposes`, retention_days |
| **Ст. 6** — правовые основания | Частично | `legal_basis` у цели; согласие в `maniforge_pd_consents` |
| **Ст. 9** — специальные категории | Не покрыто | Явный запрет в целях; отдельная оценка при появлении полей |
| **Ст. 14** — права субъекта | MVP | `GET /api/v1/me/personal-data`, `POST .../subject-requests` |
| **Ст. 18** — локализация (запись граждан РФ) | Документ + поле | `data_storage_region` в профиле оператора; инфра — вне кода |
| **Ст. 18.1** — уведомление субъекта | MVP | `GET /api/v1/privacy/notice` |
| **Ст. 19** — меры защиты | Частично | RBAC, audit, argon2id, сессии — см. `MANIFORGE_RBAC_CHECKPOINT_SUMMARY.md` |
| **Ст. 20** — обязанности оператора | Частично | Профиль оператора, DPO-контакты в API |
| **Ст. 22** — уведомление РКН | Организационно | Поле `roskomnadzor_notified_at`, процесс у юристов |

Легенда: **Готово** / **MVP** / **Частично** / **План** / **Организационно**.

## Фазы внедрения

### Фаза 1 — Учёт и права субъекта (MVP, в коде)

- Таблицы: `maniforge_pd_operator_profiles`, `maniforge_pd_processing_purposes`, `maniforge_pd_consents`, `maniforge_pd_subject_requests`
- API: см. раздел [API 152-ФЗ](#api-152-фз)
- Регистрация: опционально `consents[]`; при `RBAC_PD_REGISTER_CONSENT_REQUIRED=true` — обязательны все `is_mandatory_for_registration` цели
- Проверка: `php maniforge/rbac/tools/pd_compliance_check.php`

### Фаза 2 — Защита ПДн at-rest и журналирование (реализовано)

- **AES-256-GCM** для `email` / `phone` (`PiiFieldCodec`, колонки `email_enc`, `phone_enc`, blind index в `email`/`phone`)
- Маскирование PII в **audit** (`PiiAuditSanitizer`) и **versioning** (redact phone/email)
- Миграция существующих строк: `php maniforge/rbac/tools/pd_migrate_pii_encryption.php`
- Production: `RBAC_PII_ENCRYPTION_ENABLED=true` + `RBAC_PII_ENCRYPTION_KEY`
- Отложено: envelope для полей профиля, immutable audit export / SIEM

### Фаза 3 — Жизненный цикл данных (реализовано)

- `PdRetentionService` + `php maniforge/rbac/tools/pd_retention_enforce.php`:
  - просроченные subject-requests → `rejected`;
  - отзыв согласий по `retention_days` целей (`RBAC_PD_RETENTION_REVOKE_CONSENTS`, default true);
  - purge audit (`RBAC_AUDIT_RETENTION_DAYS`)
- Bootstrap: `pd_bootstrap_compliance.php`, авто-seed при регистрации tenant
- Обезличивание по `erasure` — `PersonalDataService`
- Проверка: `php maniforge/rbac/tools/pd_compliance_journey_check.php`

### Фаза 4 — Делегирование и cross-tenant (реализовано)

- `read_only` — блок всех мутаций (кроме auth switch/logout)
- `operator` — блок префиксов admin write (users, roles, sessions revoke, PD resolve, policies…)
- `admin` — полный delegated write (ограничивается RBAC permissions)
- UI: регистрация + RBAC Admin секция «152-ФЗ / ПДн» (очередь subject-requests)
- `GET /api/v1/admin/audit/export` — выгрузка audit с `manifest_sha256`
- Отложено: grant `metadata_json` договор поручения

### Фаза 5 — Организационный контур (шаблоны в репозитории)

Готовые черновики в **`docs/legal/`** (заполнить и согласовать с юристом):

| Документ | Файл |
|----------|------|
| **Уведомление Роскомнадзору (письмо + сведения)** | [`docs/legal/ROSKOMNADZOR_NOTIFICATION_LETTER.md`](./legal/ROSKOMNADZOR_NOTIFICATION_LETTER.md) |
| Политика конфиденциальности (каркас) | `PRIVACY_POLICY_OUTLINE.md` |
| Приказ о назначении ответственного (DPO) | `DPO_APPOINTMENT_ORDER.md` |
| Реестр операций обработки | `PROCESSING_REGISTRY_TEMPLATE.md` |
| Поручение обработки (SaaS) | `DATA_PROCESSING_AGREEMENT_OUTLINE.md` |
| Ответ субъекту на запрос | `SUBJECT_REQUEST_RESPONSE_TEMPLATE.md` |

После подачи в РКН: `roskomnadzor_notified_at` в operator-profile API.

Остаётся вне git: подписанные PDF, входящий номер РКН, акт классификации ИСПДн, модель угроз (хранить в архиве оператора).

## API 152-ФЗ

Префикс: `/rbac` (или изолированный docroot RBAC).

### Публично (без сессии, нужен tenant-контекст в multi)

| Метод | Путь | Описание |
|-------|------|----------|
| GET | `/api/v1/privacy/notice` | Текстовая сводка для субъекта: оператор, цели, версия политики, регион |

Заголовки в `TENANCY_MODE=multi`: `X-Tenant-ID`, `X-Subtenant-ID`.

### Субъект (сессия)

| Метод | Путь | Permission |
|-------|------|------------|
| GET | `/api/v1/me/personal-data` | `me.personal_data.read` |
| GET | `/api/v1/me/personal-data/consents` | `me.consent.read` |
| POST | `/api/v1/me/personal-data/consents` | `me.consent.manage` |
| POST | `/api/v1/me/personal-data/consents/revoke` | `me.consent.manage` |
| GET | `/api/v1/me/personal-data/subject-requests` | `me.personal_data.request` |
| POST | `/api/v1/me/personal-data/subject-requests` | `me.personal_data.request` |

Типы запросов: `access`, `rectification`, `erasure`, `restriction`, `withdraw_consent`.

### Администратор tenant

| Метод | Путь | Permission |
|-------|------|------------|
| GET/PUT | `/api/v1/admin/personal-data/operator-profile` | `admin.pd.operator.read` / `.write` |
| GET/POST/PATCH | `/api/v1/admin/personal-data/purposes` | `admin.pd.purposes.read` / `.write` |
| GET | `/api/v1/admin/personal-data/subject-requests` | `admin.pd.requests.read` |
| POST | `/api/v1/admin/personal-data/subject-requests/resolve` | `admin.pd.requests.handle` |
| GET | `/api/v1/admin/audit/export?limit=5000` | `admin.audit.export` |

Мутации admin — step-up reauth (как остальные admin API).

## Переменные окружения

| Переменная | По умолчанию | Назначение |
|------------|--------------|------------|
| `RBAC_PD_REGISTER_CONSENT_REQUIRED` | `false` | Обязательные согласия при регистрации |
| `RBAC_PD_REQUEST_SLA_DAYS` | `30` | Срок рассмотрения запроса субъекта (дней) |
| `RBAC_PD_NOTICE_MIN_PURPOSES` | `1` | Минимум активных целей для публикации notice |
| `RBAC_PII_ENCRYPTION_ENABLED` | `false` | Шифрование email/phone at-rest |
| `RBAC_PII_ENCRYPTION_KEY` | — | 32 байта, base64 |
| `RBAC_PII_BLIND_INDEX_KEY` | — | Опционально, отдельный ключ для blind index |
| `RBAC_AUDIT_RETENTION_DAYS` | `0` | Purge audit (0 = не удалять) |
| `RBAC_PD_RETENTION_REVOKE_CONSENTS` | `true` | Отзыв согласий по истечении `retention_days` цели |

Production profile (`docs/MANIFORGE_ENTERPRISE_HARDENING.md`):

```dotenv
RBAC_PD_REGISTER_CONSENT_REQUIRED=true
RBAC_PD_REQUEST_SLA_DAYS=30
RBAC_PII_ENCRYPTION_ENABLED=true
RBAC_PII_ENCRYPTION_KEY=<base64-32-bytes>
```

## Чеклист перед production

- [ ] Заполнен `operator-profile` для каждого production tenant
- [ ] Опубликованы цели обработки (`purposes`) с `legal_basis` и `retention_days`
- [ ] `privacy_policy_url` ведёт на актуальную политику
- [ ] `data_storage_region=RU`, хостинг БД в РФ задокументирован
- [ ] `RBAC_PD_REGISTER_CONSENT_REQUIRED=true`
- [ ] Envelope encryption PII (фаза 2)
- [ ] Прогон `pd_compliance_check.php`, `preflight.php`, `new_user_suite.php`
- [ ] Регламент ответа на `subject-requests` (ответственный, SLA)
- [ ] Заполнен шаблон уведомления РКН (`docs/legal/ROSKOMNADZOR_NOTIFICATION_LETTER.md`)
- [ ] `php maniforge/rbac/tools/check_all.php` (включает PD checks)

## Связанные документы

- `docs/MANIFORGE_PD_PROCESSOR_PLATFORM.md` — SaaS: клиент = оператор, Maniforge = обработчик, онбординг и модули
- `docs/MANIFORGE_TENANT_DELEGATION.md` — изоляция и аудит cross-tenant
- `docs/MANIFORGE_ENTERPRISE_HARDENING.md` — production gates
- `docs/MANIFORGE_NEW_USER_WORKFLOW.md` — регистрация и согласия
- `docs/MANIFORGE_SECURITY_INCIDENT_WORKFLOW.md` — инциденты
- `docs/legal/README.md` — юридические шаблоны (в т.ч. письмо для РКН)
