# Maniforge Realtime — WebSocket для фронта

Live-события для **platform** и **custom** сущностей проекта.  
Клиент создаёт подписки через REST API и подключается к WS по `subscription_id` или списку каналов.

## Endpoints

| Метод | URL | Auth |
|-------|-----|------|
| `GET` | `/health` | нет |
| `GET` | `/api/v1/ws/channels` | Bearer session |
| `POST` | `/api/v1/subscriptions` | Bearer session |
| `GET` | `/api/v1/subscriptions` | Bearer session |
| `GET` | `/api/v1/subscriptions/:id` | Bearer session |
| `PATCH` | `/api/v1/subscriptions/:id` | Bearer session |
| `DELETE` | `/api/v1/subscriptions/:id` | Bearer session |
| `GET` | `/ws?token=<access_token>&subscription_id=<id>` | RBAC access_token |
| `POST` | `/internal/v1/broadcast` | service token (серверы) |

Дубли с prefix: `/realtime/health`, `/realtime/ws`, …

Env: `MANIFORGE_REALTIME_URL=ws://127.0.0.1:8097` (для фронта).

## Рекомендуемый flow

### 1. Получить access_token

`POST /rbac/api/v1/auth/login` → `credentials.access_token`.

### 2. Подсказка каналов (опционально)

```http
GET http://127.0.0.1:8097/api/v1/ws/channels
Authorization: Bearer <access_token>
```

Ответ:

```json
{
  "ok": true,
  "manifests": { "invoice": "custom", "product": "platform" },
  "meta_channels": ["entity.all", "entity.custom", "entity.platform", "notifications", "tenant"],
  "suggested": ["entity.all", "entity.custom", "entity.platform", "data.invoice", "entity.invoice", "data.product", "entity.product"],
  "custom_entities": ["invoice"],
  "platform_entities": ["product"]
}
```

### 3. Создать подписку

```http
POST http://127.0.0.1:8097/api/v1/subscriptions
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "name": "Склад и счета",
  "channels": ["entity.platform", "data.invoice"]
}
```

Ответ `201`:

```json
{
  "ok": true,
  "subscription": {
    "id": 1,
    "name": "Склад и счета",
    "channels": ["entity.platform", "data.invoice"],
    "status": "active"
  },
  "ws_subscribe": { "type": "subscribe", "subscription_id": 1 }
}
```

Подписки привязаны к `tenant_id` + `subtenant_id` + `project_id` + `user_id` сессии.

### 4. WebSocket

**Авто-подписка при connect:**

```
ws://127.0.0.1:8097/ws?token=<access_token>&subscription_id=1
```

**Или подписка после connect:**

```json
{"type":"subscribe","subscription_id":1}
```

**Или напрямую по каналам (без API-подписки):**

```json
{"type":"subscribe","channels":["entity.all","data.invoice"]}
```

Ответ:

```json
{"type":"subscribed","ok":true,"subscription_id":1,"channels":["entity.platform","data.invoice"]}
```

## Каналы

| Канал | События | Охват |
|-------|---------|-------|
| `entity.all` | manifest.* | platform + custom |
| `entity.custom` | manifest.* | только `origin=custom` |
| `entity.platform` | manifest.* | только `origin=platform` |
| `entity.<code>` | manifest.* | одна сущность |
| `data.<code>` | record.* | записи DocType |
| `notifications` | системные (опционально) | — |
| `tenant` | lifecycle tenant (опционально) | — |

Мета-каналы (`entity.all`, `entity.custom`, `entity.platform`) сопоставляются с событиями по `payload.origin`.

## События (сервер → клиент)

```json
{
  "type": "event",
  "channel": "data.product",
  "payload": {
    "event": "record.updated",
    "entity": "product",
    "origin": "platform",
    "record_id": 7,
    "project_id": 1
  }
}
```

### Manifest

| `payload.event` | Когда |
|-----------------|-------|
| `manifest.created` | preset install / `POST /api/v1/manifests` |
| `manifest.updated` | `PATCH /api/v1/manifests/:code` |
| `manifest.archived` | `DELETE /api/v1/manifests/:code` |

### Records

| `payload.event` | Когда |
|-----------------|-------|
| `record.created` | `POST /api/data/:entity` |
| `record.updated` | `PATCH`, `PUT field` |
| `record.deleted` | `DELETE /api/data/:entity/:id` |

Полные тела записей **не** шлются по WS — клиент делает `GET /api/data/:entity/:id` при необходимости.

## CRUD подписок

| Метод | Действие |
|-------|----------|
| `GET /subscriptions` | список активных подписок пользователя |
| `GET /subscriptions/:id` | одна подписка |
| `PATCH /subscriptions/:id` | обновить `name` + `channels` |
| `DELETE /subscriptions/:id` | архивировать (`status=archived`) |

## Keep-alive

- Клиент: `{"type":"ping"}` → `{"type":"pong","ok":true}`
- Сервер: WebSocket ping каждые 45 с

## Пример (JavaScript)

```javascript
const token = localStorage.getItem('access_token');
const headers = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };

const sub = await fetch('http://127.0.0.1:8097/api/v1/subscriptions', {
  method: 'POST',
  headers,
  body: JSON.stringify({
    name: 'Всё по проекту',
    channels: ['entity.all'],
  }),
}).then((r) => r.json());

const ws = new WebSocket(
  `ws://127.0.0.1:8097/ws?token=${encodeURIComponent(token)}&subscription_id=${sub.subscription.id}`,
);

ws.onmessage = (ev) => {
  const msg = JSON.parse(ev.data);
  if (msg.type === 'event') {
    console.log(msg.channel, msg.payload);
  }
};
```

## Internal (Manifest Engine → Realtime)

Manifest Engine публикует события через `POST /internal/v1/broadcast` (fire-and-forget).

Env: `MANIFORGE_REALTIME_INTERNAL_URL=http://127.0.0.1:8097`

## Запуск

```bash
make migrate
make run-realtime
```
