<?php
declare(strict_types=1);

return [
    'id' => 'api-wms',
    'title' => 'WMS API',
    'description' => 'КИЗ, групповые упаковки, паллеты SSCC/QR, скан и движения через Inventory. Без внешних интеграций.',
    'common' => api_doc_module_bearer_common(
        'RBAC и permissions',
        [
            'Префикс модуля: /wms (PHP-референс). КИЗ, упаковки, скан — движения через Inventory.',
            'Permissions: wms.*; интеграция с Products и Inventory в том же project scope.',
        ],
        [
            ['code' => 404, 'description' => 'Код/упаковка не найдены', 'example' => '{"ok":false,"error":"not_found"}'],
            ['code' => 422, 'description' => 'pack_not_sealed, marking_unavailable, invalid_pack_status', 'example' => '{"ok":false,"error":"pack_not_sealed"}'],
        ],
    ),
    'groups' => [
        [
            'id' => 'wms-packs',
            'title' => 'Упаковки',
            'endpoints' => [
                [
                    'id' => 'wms-packs-list',
                    'method' => 'GET',
                    'path' => '/wms/api/v1/packs',
                    'title' => 'Список упаковок',
                    'summary' => 'Фильтры: unit_type, status, search.',
                    'auth' => 'Bearer; wms.read.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'unit_type', 'type' => 'string', 'required' => false, 'description' => 'group|pallet|...'],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => 'draft|sealed|at_stock'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'items[]', 'example' => '{"ok":true,"items":[]}'],
                    ],
                ],
                [
                    'id' => 'wms-packs-post',
                    'method' => 'POST',
                    'path' => '/wms/api/v1/packs',
                    'title' => 'Создать упаковку',
                    'summary' => 'unit_type, code; для pallet — auto SSCC + QR после seal.',
                    'auth' => 'Bearer; wms.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'unit_type', 'type' => 'string', 'required' => true, 'description' => 'group|pallet|sscc|consumer'],
                            ['name' => 'code', 'type' => 'string', 'required' => false, 'description' => 'Уникальный код в scope'],
                        ],
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'pack', 'example' => '{"ok":true,"pack":{"id":1,"status":"draft"}}'],
                    ],
                ],
                [
                    'id' => 'wms-packs-delete',
                    'method' => 'DELETE',
                    'path' => '/wms/api/v1/packs/{id}',
                    'title' => 'Удалить draft упаковку',
                    'summary' => 'Только status=draft; удаляет содержимое pack_contents.',
                    'auth' => 'Bearer; wms.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [['code' => 200, 'description' => 'deleted']],
                ],
                [
                    'id' => 'wms-packs-disaggregate',
                    'method' => 'POST',
                    'path' => '/wms/api/v1/packs/{id}/disaggregate',
                    'title' => 'Дизагрегация',
                    'summary' => 'sealed/at_stock → disaggregated, КИЗ → available.',
                    'auth' => 'Bearer; wms.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'pack обновлён'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'wms-scan',
            'title' => 'Скан и движения',
            'endpoints' => [
                [
                    'id' => 'wms-scan',
                    'method' => 'POST',
                    'path' => '/wms/api/v1/scan',
                    'title' => 'Разрешить код',
                    'summary' => 'SSCC, QR JSON, код упаковки, КИЗ, EAN-13 товара (kind=product).',
                    'auth' => 'Bearer; wms.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'code', 'type' => 'string', 'required' => true, 'description' => 'Сканированное значение'],
                        ],
                    ],
                    'responses' => [
                        ['code' => 200, 'description' => 'kind: pack|marking', 'example' => '{"ok":true,"kind":"pack"}'],
                    ],
                ],
                [
                    'id' => 'wms-move-scan',
                    'method' => 'POST',
                    'path' => '/wms/api/v1/movements/scan',
                    'title' => 'Движение по скану',
                    'summary' => 'receipt|issue → Inventory + статусы КИЗ.',
                    'auth' => 'Bearer; wms.write + inventory.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'movement_type', 'type' => 'string', 'required' => true, 'description' => 'receipt|issue'],
                            ['name' => 'stock_id', 'type' => 'integer', 'required' => true, 'description' => 'Узел склада'],
                            ['name' => 'scan', 'type' => 'string', 'required' => false, 'description' => 'Код или pack_unit_id'],
                        ],
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'movement', 'example' => '{"ok":true,"movement":{}}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'wms-markings',
            'title' => 'Маркировка',
            'endpoints' => [
                [
                    'id' => 'wms-markings-bulk',
                    'method' => 'POST',
                    'path' => '/wms/api/v1/markings/bulk',
                    'title' => 'Массовая регистрация КИЗ',
                    'summary' => 'product_id + codes[].',
                    'auth' => 'Bearer; wms.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'product_id', 'type' => 'integer', 'required' => true, 'description' => 'SKU'],
                            ['name' => 'codes', 'type' => 'array', 'required' => true, 'description' => 'Строки КИЗ'],
                        ],
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'created_count, errors[]'],
                    ],
                ],
            ],
        ],
    ],
];
