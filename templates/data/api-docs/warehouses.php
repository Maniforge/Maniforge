<?php
declare(strict_types=1);

$common = api_doc_module_bearer_common(
    'RBAC и permissions',
    [
        'Иерархия складских узлов в scope tenant/subtenant из access_token.',
        'Permissions: warehouses.*. Ответы включают created_by_user; аудит — maniforge_audit_log.',
    ],
);

return [
    'title' => 'Warehouses API',
    'description' => 'Иерархия складских узлов. RBAC Bearer, permissions warehouses.*.',
    'openapi' => null,
    'common' => $common,
    'groups' => [
        [
            'id' => 'wh-health',
            'title' => 'Сервис',
            'endpoints' => [
                [
                    'id' => 'wh-health',
                    'method' => 'GET',
                    'path' => '/warehouses/health',
                    'title' => 'Health',
                    'summary' => 'Доступность модуля.',
                    'auth' => 'Без авторизации.',
                    'headers_profile' => 'none',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Сервис up.', 'example' => '{"ok":true,"service":"maniforge-warehouses","status":"up"}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'wh-stocks',
            'title' => 'Узлы (stocks)',
            'endpoints' => [
                [
                    'id' => 'wh-stock-types',
                    'method' => 'GET',
                    'path' => '/warehouses/api/v1/stock-types',
                    'title' => 'Каталог типов',
                    'summary' => 'Справочник stock_types (warehouse, zone, rack, …).',
                    'auth' => 'Bearer; warehouses.types.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'items[] типов.', 'example' => '{"ok":true,"items":[{"code":"warehouse","name":"Склад (здание)"}]}'],
                    ],
                ],
                [
                    'id' => 'wh-stocks-list',
                    'method' => 'GET',
                    'path' => '/warehouses/api/v1/stocks',
                    'title' => 'Список узлов',
                    'summary' => 'Фильтры: type, search, parent_id, roots_only, status.',
                    'auth' => 'Bearer; warehouses.read.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'type', 'type' => 'string', 'required' => false, 'description' => 'Код типа.'],
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'description' => 'active | archived | all (default active).'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'items[] с type_label, created_by_user.', 'example' => '{"ok":true,"items":[{"id":1,"code":"wh-msk","type":"warehouse","created_by_user":{"id":1,"phone":"+7900..."}}]}'],
                    ],
                ],
                [
                    'id' => 'wh-stocks-tree',
                    'method' => 'GET',
                    'path' => '/warehouses/api/v1/stocks/tree',
                    'title' => 'Дерево',
                    'summary' => 'Вложенное дерево children[].',
                    'auth' => 'Bearer; warehouses.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'tree[], flat_count.', 'example' => '{"ok":true,"tree":[{"id":1,"children":[]}],"flat_count":1}'],
                    ],
                ],
                [
                    'id' => 'wh-stocks-create',
                    'method' => 'POST',
                    'path' => '/warehouses/api/v1/stocks',
                    'title' => 'Создать узел',
                    'summary' => 'name, type обязательны; parent_id по правилам типов; data — JSON.',
                    'auth' => 'Bearer; warehouses.write.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'code', 'type' => 'string', 'required' => false, 'description' => 'Уникален в scope; иначе автоген.'],
                            ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Название.'],
                            ['name' => 'type', 'type' => 'string', 'required' => true, 'description' => 'Код из stock-types.'],
                            ['name' => 'parent_id', 'type' => 'integer|null', 'required' => false, 'description' => 'Родительский узел.'],
                            ['name' => 'data', 'type' => 'object', 'required' => false, 'description' => 'Произвольные атрибуты.'],
                            ['name' => 'scope_visibility', 'type' => 'string', 'required' => false, 'description' => 'project|subtenant|tenant (default project).'],
                            ['name' => 'delegation_share_tenant_ids', 'type' => 'string[]', 'required' => false, 'description' => 'Peer tenant по grant (родитель↔клиент). tenant_admin.'],
                            ['name' => 'share_with_principal', 'type' => 'boolean', 'required' => false, 'description' => 'Managed → открыть principal.'],
                            ['name' => 'share_with_managed', 'type' => 'boolean|array', 'required' => false, 'description' => 'Principal → managed peer(s).'],
                        ],
                        'example' => '{"code":"wh-msk","name":"Склад Москва","type":"warehouse","data":{"city":"Москва"}}',
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'stock создан.', 'example' => '{"ok":true,"status":201,"stock":{"id":1,"created_by_user":{...}}}'],
                    ],
                ],
                [
                    'id' => 'wh-stocks-get',
                    'method' => 'GET',
                    'path' => '/warehouses/api/v1/stocks/{id}',
                    'title' => 'Узел по id',
                    'summary' => 'Детали + path + children_count.',
                    'auth' => 'Bearer; warehouses.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'stock.', 'example' => '{"ok":true,"stock":{"id":1,"path":[...]}}'],
                    ],
                ],
                [
                    'id' => 'wh-stocks-audit',
                    'method' => 'GET',
                    'path' => '/warehouses/api/v1/stocks/{id}/audit',
                    'title' => 'Аудит узла',
                    'summary' => 'События warehouses.stock.* из maniforge_audit_log с actor_user.',
                    'auth' => 'Bearer; warehouses.audit.read.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'limit', 'type' => 'integer', 'required' => false, 'description' => '1–200, default 50.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'items[] событий.', 'example' => '{"ok":true,"stock_id":1,"items":[{"event_type":"warehouses.stock.created","actor_user":{"id":1}}]}'],
                    ],
                ],
                [
                    'id' => 'wh-stocks-delete',
                    'method' => 'DELETE',
                    'path' => '/warehouses/api/v1/stocks/{id}',
                    'title' => 'Архивировать',
                    'summary' => 'status=archived; дочерние active должны быть архивированы раньше.',
                    'auth' => 'Bearer; warehouses.delete.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Архивирован.', 'example' => '{"ok":true,"stock":{"status":"archived"}}'],
                        ['code' => 409, 'description' => 'has_active_children.', 'example' => '{"ok":false,"code":"has_active_children"}'],
                    ],
                ],
            ],
        ],
    ],
];
