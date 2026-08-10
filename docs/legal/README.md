# Юридические шаблоны (152-ФЗ)

Шаблоны для организационного контура соответствия. **Не являются юридической консультацией** — перед подачей в Роскомнадзор и публикацией согласуйте с вашим юристом.

| Файл | Назначение |
|------|------------|
| [ROSKOMNADZOR_NOTIFICATION_LETTER.md](./ROSKOMNADZOR_NOTIFICATION_LETTER.md) | **Уведомление об обработке ПДн** (письмо + структура сведений для РКН) |
| [PRIVACY_POLICY_OUTLINE.md](./PRIVACY_POLICY_OUTLINE.md) | Каркас политики конфиденциальности для сайта |
| [PROCESSING_REGISTRY_TEMPLATE.md](./PROCESSING_REGISTRY_TEMPLATE.md) | Внутренний реестр операций обработки |
| [DPO_APPOINTMENT_ORDER.md](./DPO_APPOINTMENT_ORDER.md) | Приказ о назначении ответственного за организацию обработки ПДн |
| [DATA_PROCESSING_AGREEMENT_OUTLINE.md](./DATA_PROCESSING_AGREEMENT_OUTLINE.md) | Поручение обработки (платформа ↔ клиент-оператор) |
| [SUBJECT_REQUEST_RESPONSE_TEMPLATE.md](./SUBJECT_REQUEST_RESPONSE_TEMPLATE.md) | Ответ субъекту на запрос по ст. 14 |

Связь с техконтуром Maniforge: `docs/152FZ_COMPLIANCE.md`, модель SaaS-обработчика: `docs/MANIFORGE_PD_PROCESSOR_PLATFORM.md`.

После заполнения:
1. Укажите `privacy_policy_url` в `PUT /api/v1/admin/personal-data/operator-profile`.
2. Зафиксируйте дату уведомления РКН в поле `roskomnadzor_notified_at` (API operator-profile).
3. Храните подписанные PDF и входящий номер РКН в архиве оператора (вне репозитория).
