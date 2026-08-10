<?php
declare(strict_types=1);

return [
    'id' => 'api-products',
    'title' => 'Products API',
    'description' => 'Номенклатура (SKU) в scope tenant + project. RBAC Bearer, permissions products.*. Delegation share — как Warehouses.',
    'common' => api_doc_module_bearer_common(
        'RBAC и permissions',
        [
            'Префикс модуля: /products. Scope tenant + project; delegation share — как у Warehouses.',
            'Permissions: products.read, products.write, products.delete.',
        ],
        [
            ['code' => 404, 'description' => 'Товар не найден в видимом scope', 'example' => '{"ok":false,"error":"not_found"}'],
            ['code' => 409, 'description' => 'code_exists / duplicate', 'example' => '{"ok":false,"error":"code_exists"}'],
        ],
    ),
    'groups' => [
        [
            'id' => 'pr-health',
            'title' => 'Health',
            'endpoints' => [
                [
                    'id' => 'pr-health',
                    'method' => 'GET',
                    'path' => '/products/health',
                    'title' => 'Health',
                    'summary' => 'Статус модуля.',
                    'auth' => 'Публично.',
                    'headers_profile' => 'none',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'up.', 'example' => '{"ok":true,"service":"maniforge-products","status":"up"}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'pr-products',
            'title' => 'Товары',
            'endpoints' => [
                [
                    'id' => 'pr-list',
                    'method' => 'GET',
                    'path' => '/products/api/v1/products',
                    'title' => 'Список',
                    'summary' => 'Товары в видимом scope (+ delegation share). Query: search, status (active|archived|all).',
                    'auth' => 'Bearer; products.read.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'search', 'type' => 'string', 'required' => false, 'description' => 'По name или code.'],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => 'active (default), archived, all.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'items[].', 'example' => '{"ok":true,"items":[{"id":1,"code":"sku-1","name":"Товар","unit":"pcs","scope_visibility":"project"}]}'],
                    ],
                ],
                [
                    'id' => 'pr-by-barcode',
                    'method' => 'GET',
                    'path' => '/products/api/v1/products/by-barcode/{ean13}',
                    'title' => 'По штрихкоду EAN-13',
                    'summary' => 'Поиск active товара по EAN-13 (валидация контрольной цифры).',
                    'auth' => 'Bearer; products.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'kind=product, product{}', 'example' => '{"ok":true,"kind":"product","barcode":"4600000000008"}'],
                    ],
                ],
                [
                    'id' => 'pr-create',
                    'method' => 'POST',
                    'path' => '/products/api/v1/products',
                    'title' => 'Создать',
                    'summary' => 'name обязателен; code опционален; scope как у warehouses/stocks.',
                    'auth' => 'Bearer; products.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'code', 'type' => 'string', 'required' => false, 'description' => 'SKU в scope.'],
                            ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Название.'],
                            ['name' => 'unit', 'type' => 'string', 'required' => false, 'description' => 'Ед. изм., default pcs.'],
                            ['name' => 'barcode_ean13', 'type' => 'string', 'required' => false, 'description' => 'EAN-13 (12 цифр UPC-A → 13), проверка GS1.'],
                            ['name' => 'description', 'type' => 'string', 'required' => false, 'description' => 'Описание.'],
                            ['name' => 'attributes', 'type' => 'object', 'required' => false, 'description' => 'Атрибуты JSON.'],
                            ['name' => 'scope_visibility', 'type' => 'string', 'required' => false, 'description' => 'project|subtenant|tenant.'],
                            ['name' => 'share_with_principal', 'type' => 'boolean', 'required' => false, 'description' => 'Managed → principal (grant).'],
                        ],
                        'example' => '{"code":"sku-001","name":"Товар A","unit":"pcs","attributes":{"color":"red"}}',
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'product создан.', 'example' => '{"ok":true,"status":201,"product":{"id":1}}'],
                    ],
                ],
                [
                    'id' => 'pr-get',
                    'method' => 'GET',
                    'path' => '/products/api/v1/products/{id}',
                    'title' => 'По id',
                    'summary' => 'Детали товара.',
                    'auth' => 'Bearer; products.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'product.', 'example' => '{"ok":true,"product":{"id":1}}'],
                    ],
                ],
                [
                    'id' => 'pr-delete',
                    'method' => 'DELETE',
                    'path' => '/products/api/v1/products/{id}',
                    'title' => 'Архивировать',
                    'summary' => 'status=archived.',
                    'auth' => 'Bearer; products.delete.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Архивирован.', 'example' => '{"ok":true,"product":{"status":"archived"}}'],
                    ],
                ],
            ],
        ],
    ],
];
