<?php
declare(strict_types=1);

return [
    'id' => 'api-inventory',
    'title' => 'Inventory API',
    'description' => 'Остатки (balances) и журнал движений. Требует видимые Products + Warehouses. Проведение атомарно обновляет остатки.',
    'common' => api_doc_module_bearer_common(
        'RBAC и permissions',
        [
            'Префикс модуля: /inventory. Требует видимые Products и Warehouses в scope.',
            'Проведение движения атомарно обновляет остатки; permissions inventory.*.',
        ],
        [
            ['code' => 409, 'description' => 'insufficient_qty — недостаточно остатка', 'example' => '{"ok":false,"error":"insufficient_qty"}'],
            ['code' => 403, 'description' => 'delegated_entity_read_only — мутации только в tenant владельца', 'example' => '{"ok":false,"error":"delegated_entity_read_only"}'],
        ],
    ),
    'groups' => [
        [
            'id' => 'inv-balances',
            'title' => 'Остатки',
            'endpoints' => [
                [
                    'id' => 'inv-balances-list',
                    'method' => 'GET',
                    'path' => '/inventory/api/v1/balances',
                    'title' => 'Список остатков',
                    'summary' => 'JOIN product+stock visibility. Query: product_id, stock_id, non_zero.',
                    'auth' => 'Bearer; inventory.read.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'product_id', 'type' => 'integer', 'required' => false, 'description' => 'Фильтр SKU.'],
                        ['name' => 'stock_id', 'type' => 'integer', 'required' => false, 'description' => 'Фильтр узла.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'items[] с qty.', 'example' => '{"ok":true,"items":[{"product_id":1,"stock_id":2,"qty":"70.000000"}]}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'inv-movements',
            'title' => 'Движения',
            'endpoints' => [
                [
                    'id' => 'inv-movements-post',
                    'method' => 'POST',
                    'path' => '/inventory/api/v1/movements',
                    'title' => 'Провести движение',
                    'summary' => 'movement_type: receipt|issue|transfer|adjustment. По умолчанию posted; status=draft — черновик.',
                    'auth' => 'Bearer; inventory.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'movement_type', 'type' => 'string', 'required' => true, 'description' => 'receipt|issue|transfer|adjustment'],
                            ['name' => 'product_id', 'type' => 'integer', 'required' => false, 'description' => 'Для receipt/issue/transfer/adjustment'],
                            ['name' => 'stock_id', 'type' => 'integer', 'required' => false, 'description' => 'Узел для receipt/issue'],
                            ['name' => 'qty', 'type' => 'string', 'required' => false, 'description' => 'Десятичное количество'],
                            ['name' => 'from_stock_id', 'type' => 'integer', 'required' => false, 'description' => 'transfer'],
                            ['name' => 'to_stock_id', 'type' => 'integer', 'required' => false, 'description' => 'transfer'],
                        ],
                        'example' => '{"movement_type":"receipt","product_id":1,"stock_id":2,"qty":"100"}',
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'movement + lines', 'example' => '{"ok":true,"movement":{"doc_number":"mov-..."}}'],
                    ],
                ],
                [
                    'id' => 'inv-movements-reverse',
                    'method' => 'POST',
                    'path' => '/inventory/api/v1/movements/{id}/reverse',
                    'title' => 'Сторно',
                    'summary' => 'Обратные строки, metadata.reversal_of. Синхронизация КИЗ через WMS.',
                    'auth' => 'Bearer; inventory.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'doc_number', 'type' => 'string', 'required' => false, 'description' => 'Иначе {orig}-rev-xxxx'],
                            ['name' => 'note', 'type' => 'string', 'required' => false, 'description' => 'Комментарий'],
                        ],
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'movement-сторно', 'example' => '{"ok":true,"movement":{}}'],
                        ['code' => 409, 'description' => 'already_reversed'],
                    ],
                ],
                [
                    'id' => 'inv-movements-list',
                    'method' => 'GET',
                    'path' => '/inventory/api/v1/movements',
                    'title' => 'Журнал',
                    'summary' => 'Документы в scope сессии.',
                    'auth' => 'Bearer; inventory.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'items[]', 'example' => '{"ok":true,"items":[]}'],
                    ],
                ],
                [
                    'id' => 'inv-movements-post-draft',
                    'method' => 'POST',
                    'path' => '/inventory/api/v1/movements/{id}/post',
                    'title' => 'Провести черновик',
                    'summary' => 'status=draft → posted, обновление balances.',
                    'auth' => 'Bearer; inventory.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [['code' => 200, 'description' => 'movement posted']],
                ],
            ],
        ],
        [
            'id' => 'inv-orders',
            'title' => 'Заказы склада',
            'endpoints' => [
                [
                    'id' => 'inv-orders-post',
                    'method' => 'POST',
                    'path' => '/inventory/api/v1/orders',
                    'title' => 'Создать заказ',
                    'summary' => 'stock_id, lines[{product_id, qty}]. confirm → резерв inv-order:{number}, fulfill → issue.',
                    'auth' => 'Bearer; inventory.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [['code' => 201, 'description' => 'order draft']],
                ],
            ],
        ],
        [
            'id' => 'inv-lots',
            'title' => 'Партии',
            'endpoints' => [
                [
                    'id' => 'inv-lots-post',
                    'method' => 'POST',
                    'path' => '/inventory/api/v1/lots',
                    'title' => 'Регистрация batch/lot',
                    'summary' => 'product_id, batch_code, lot_code; идемпотентно по ключу.',
                    'auth' => 'Bearer; inventory.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [['code' => 201, 'description' => 'lot']],
                ],
            ],
        ],
    ],
];
