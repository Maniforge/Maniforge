# maniforge_entity_meta — связь внутренних и внешних идентификаторов

Таблица `maniforge_entity_meta` — сводный реестр между нашим сервисом (RBAC) и внешними системами.

## Поля

| Поле | Назначение |
|------|------------|
| `meta` | Внешний ключ (например `+79001234567`, `productId` WB) |
| `type` | Тип коннектора: `phone`, `wildberries`, … |
| `i_index` | Тип внутренней сущности (`EntityMetaTypes::I_USER` = 1) |
| `i_id` | PK внутренней записи (`maniforge_users.id`, …) |
| `o_index` | Тип внешнего представления (опционально) |
| `o_ref` | Доп. внешняя ссылка (опционально) |
| `tenant_id`, `subtenant_id` | Scope (пустые строки = глобально) |

## Примеры

**Телефон → пользователь (регистрация):**

```
meta = +79001234567
type = phone
i_index = 1
i_id = 42
o_index = 1
tenant_id = t-abc / subtenant_id = main
```

**Wildberries (будущее):**

```
meta = <productId>
type = wildberries
i_index = 3   -- product
i_id = <project or sku id>
o_index = 2
```

## Глобальная привязка телефона

При регистрации создаётся запись с пустым `tenant_id` / `subtenant_id` (глобальный scope).  
Повторная регистрация **нового tenant** на тот же номер → **409** `phone_already_registered`.  
Вторая организация — **invite** (тот же телефон и пароль → `attached: true`), **`POST /auth/accept-invite`** (уже в сессии) или **`POST /admin/organization-members`** (админ по телефону).  
Организации в **`GET /me/contexts`** → `organizations[]`.

## API

Публичный контур **не принимает и не возвращает** `login`. Идентификация:

- регистрация / вход: `phone` + `password`;
- внутри БД `maniforge_users.login` остаётся служебным (генерируется автоматически);
- связь для интеграций — через `maniforge_entity_meta`;
- организации пользователя: `GET /rbac/api/v1/me/contexts` → `organizations[]`.

Код: `App\Maniforge\Rbac\Repository\EntityMetaRepository`, константы `App\Maniforge\Rbac\Security\EntityMetaTypes`.

Миграция: `maniforge/rbac/migrations/028_entity_meta.sql`.
