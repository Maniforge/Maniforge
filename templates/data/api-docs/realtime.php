<?php
declare(strict_types=1);

$common = api_doc_module_bearer_common(
    'Каналы и flow',
    [
        'Рекомендуемый flow: login → POST /subscriptions → WebSocket /realtime/ws?token=…&subscription_id=…',
        'Подписки привязаны к tenant_id + subtenant_id + project_id + user_id сессии.',
        'Каналы: entity.all, entity.custom, entity.platform, entity.{code}, data.{code}, notifications, tenant.',
        'Префикс /realtime дублирует пути сервиса. События manifest.* и record.* — см. протокол WS.',
    ],
);

return [
    'title' => 'Realtime API',
    'description' => 'WebSocket-события для platform и custom сущностей. REST — управление подписками; '
        . 'подключение по access_token и subscription_id или списку каналов.',
    'openapi' => null,
    'common' => $common,
    'groups' => [
        [
            'id' => 'rt-health',
            'title' => 'Сервис',
            'endpoints' => [
                [
                    'id' => 'rt-health',
                    'method' => 'GET',
                    'path' => '/realtime/health',
                    'title' => 'Health',
                    'summary' => 'Проверка доступности Realtime.',
                    'auth' => 'Без авторизации.',
                    'headers_profile' => 'none',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Сервис доступен.', 'example' => '{"ok":true,"service":"maniforge-realtime","status":"up"}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'rt-channels',
            'title' => 'Каналы',
            'endpoints' => [
                [
                    'id' => 'rt-ws-channels',
                    'method' => 'GET',
                    'path' => '/realtime/api/v1/ws/channels',
                    'title' => 'Подсказка каналов',
                    'summary' => 'Манифесты проекта, meta_channels и suggested для подписки.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        [
                            'code' => 200,
                            'description' => 'manifests, suggested, custom_entities.',
                            'example' => '{"ok":true,"meta_channels":["entity.all","entity.custom"],"suggested":["entity.platform","data.product"]}',
                        ],
                    ],
                ],
            ],
        ],
        [
            'id' => 'rt-subscriptions',
            'title' => 'Подписки (REST)',
            'endpoints' => [
                [
                    'id' => 'rt-subscriptions-create',
                    'method' => 'POST',
                    'path' => '/realtime/api/v1/subscriptions',
                    'title' => 'Создать подписку',
                    'summary' => 'name + channels[] — сохраняется для пользователя в scope сессии.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Название подписки.'],
                            ['name' => 'channels', 'type' => 'array', 'required' => true, 'description' => 'Список каналов.'],
                        ],
                        'example' => '{"name":"Склад и счета","channels":["entity.platform","data.invoice"]}',
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'subscription + ws_subscribe hint.', 'example' => '{"ok":true,"subscription":{"id":1,"channels":["entity.platform"]},"ws_subscribe":{"type":"subscribe","subscription_id":1}}'],
                    ],
                ],
                [
                    'id' => 'rt-subscriptions-list',
                    'method' => 'GET',
                    'path' => '/realtime/api/v1/subscriptions',
                    'title' => 'Список подписок',
                    'summary' => 'Активные подписки текущего пользователя в scope.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'items[].', 'example' => '{"ok":true,"items":[{"id":1,"name":"Склад","channels":["entity.all"]}]}'],
                    ],
                ],
                [
                    'id' => 'rt-subscriptions-get',
                    'method' => 'GET',
                    'path' => '/realtime/api/v1/subscriptions/{id}',
                    'title' => 'Подписка по id',
                    'summary' => 'Детали подписки в scope сессии.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'subscription.', 'example' => '{"ok":true,"subscription":{"id":1,"status":"active"}}'],
                        ['code' => 404, 'description' => 'Не найдена.', 'example' => '{"ok":false,"error":"not_found"}'],
                    ],
                ],
                [
                    'id' => 'rt-subscriptions-patch',
                    'method' => 'PATCH',
                    'path' => '/realtime/api/v1/subscriptions/{id}',
                    'title' => 'Обновить подписку',
                    'summary' => 'Изменить name или channels.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Новое имя.'],
                            ['name' => 'channels', 'type' => 'array', 'required' => false, 'description' => 'Новый набор каналов.'],
                        ],
                        'example' => '{"channels":["entity.all","data.invoice"]}',
                    ],
                    'responses' => [
                        ['code' => 200, 'description' => 'Обновлено.', 'example' => '{"ok":true,"subscription":{"id":1}}'],
                    ],
                ],
                [
                    'id' => 'rt-subscriptions-delete',
                    'method' => 'DELETE',
                    'path' => '/realtime/api/v1/subscriptions/{id}',
                    'title' => 'Удалить подписку',
                    'summary' => 'Отзыв подписки пользователя.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Удалена.', 'example' => '{"ok":true}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'rt-websocket',
            'title' => 'WebSocket',
            'endpoints' => [
                [
                    'id' => 'rt-ws-connect',
                    'method' => 'GET',
                    'path' => '/realtime/ws',
                    'title' => 'Подключение WebSocket',
                    'summary' => 'Upgrade на WS. Query: token (access_token), subscription_id — авто-подписка при connect. '
                        . 'Альтернатива после connect: {"type":"subscribe","subscription_id":1} или {"type":"subscribe","channels":[...]}.',
                    'auth' => 'access_token в query или сообщении subscribe.',
                    'headers_profile' => 'none',
                    'query' => [
                        ['name' => 'token', 'required' => true, 'description' => 'RBAC access_token.'],
                        ['name' => 'subscription_id', 'required' => false, 'description' => 'ID подписки из REST API.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 101, 'description' => 'Switching Protocols — subscribed event.', 'example' => '{"type":"subscribed","ok":true,"subscription_id":1,"channels":["entity.platform"]}'],
                    ],
                ],
            ],
        ],
    ],
];
