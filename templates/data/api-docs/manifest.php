<?php
declare(strict_types=1);

$common = api_doc_module_bearer_common(
    'Scope и авторизация',
    [
        'Префикс /manifest-engine дублирует те же пути, что корень сервиса (:8095).',
        'Bearer access_token после login; в сессии обязательны tenant_id + project_id.',
        'origin=platform — пресеты supply chain (product, stock), ставит только платформа.',
        'origin=custom — схемы клиента: POST /manifests, далее REST /api/data/{code}.',
        'REST для данных: fields[] → GET .../openapi → вкладка «Персональные» (схема полей + REST; вкладки по section, сущности по name).',
        'Клиент не может создать/изменить platform-manifest; зарезервированные коды preset отклоняются (422).',
    ],
);

$manifestDoc = [
    'title' => 'Manifest Engine API',
    'description' => 'JSON-схема → REST CRUD. Управление схемами — ниже; '
        . 'сгенерированный data REST — на вкладке «Персональные».',
    'openapi' => null,
    'common' => $common,
    'groups' => [
        [
            'id' => 'mf-health',
            'title' => 'Сервис',
            'endpoints' => [
                [
                    'id' => 'mf-health',
                    'method' => 'GET',
                    'path' => '/manifest-engine/health',
                    'title' => 'Health',
                    'summary' => 'Проверка доступности Manifest Engine.',
                    'auth' => 'Без авторизации.',
                    'headers_profile' => 'none',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Сервис доступен.', 'example' => '{"ok":true,"service":"maniforge-manifest-engine","status":"up"}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'mf-custom-schemas',
            'title' => 'Custom manifest — схемы (origin=custom)',
            'endpoints' => [
                [
                    'id' => 'mf-custom-create',
                    'method' => 'POST',
                    'path' => '/manifest-engine/api/v1/manifests',
                    'title' => 'Создать custom manifest',
                    'summary' => 'Клиент описывает сущность: code + name + fields[]. В ответе origin=custom. '
                        . 'Требуется tenant_admin или subtenant_admin. Пример сущности: invoice.',
                    'auth' => 'Bearer session; роль tenant_admin / subtenant_admin.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'code', 'type' => 'string', 'required' => true, 'description' => 'Код сущности (латиница), напр. invoice, contract.'],
                            ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Отображаемое имя.'],
                            ['name' => 'type', 'type' => 'string', 'required' => false, 'description' => 'Тип сущности (напр. finance, crm) — в описании раздела /api.'],
                            ['name' => 'section', 'type' => 'string', 'required' => false, 'description' => 'Раздел каталога /api → вкладка «Персональные» (напр. sales, customers). Пусто → «Общие».'],
                            ['name' => 'fields', 'type' => 'array', 'required' => true, 'description' => 'Схема полей: name, type, required, max_length, min, items, …'],
                        ],
                        'example' => '{"code":"invoice","name":"Счёт","type":"finance","section":"sales","fields":[{"name":"number","type":"string","required":true,"max_length":32},{"name":"amount","type":"number","required":true,"min":0}]}',
                    ],
                    'responses' => [
                        ['code' => 201, 'description' => 'manifest создан.', 'example' => '{"ok":true,"manifest":{"code":"invoice","name":"Счёт","type":"finance","section":"sales","origin":"custom","version":1,"fields":[...]}}'],
                        ['code' => 409, 'description' => 'Код уже занят в project scope.', 'example' => '{"ok":false,"error":"manifest уже существует"}'],
                        ['code' => 422, 'description' => 'Зарезервированный код preset или невалидные fields.', 'example' => '{"ok":false,"error":"код зарезервирован для platform preset"}'],
                    ],
                ],
                [
                    'id' => 'mf-custom-list',
                    'method' => 'GET',
                    'path' => '/manifest-engine/api/v1/manifests',
                    'title' => 'Список manifest',
                    'summary' => 'Все схемы проекта. Для только custom: ?origin=custom. Для platform presets: ?origin=platform.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'origin', 'required' => false, 'description' => 'custom | platform | пусто = все'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'manifests[] + filter.origin.', 'example' => '{"ok":true,"manifests":[{"code":"invoice","name":"Счёт","origin":"custom","version":1},{"code":"product","origin":"platform"}],"filter":{"origin":"custom"}}'],
                    ],
                ],
                [
                    'id' => 'mf-custom-list-filtered',
                    'method' => 'GET',
                    'path' => '/manifest-engine/api/v1/manifests?origin=custom',
                    'title' => 'Только custom manifest',
                    'summary' => 'Список пользовательских схем клиента в текущем project scope.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'origin', 'required' => true, 'description' => 'custom'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Только origin=custom.', 'example' => '{"ok":true,"manifests":[{"code":"invoice","name":"Счёт","origin":"custom"}],"filter":{"origin":"custom"}}'],
                    ],
                ],
                [
                    'id' => 'mf-custom-get',
                    'method' => 'GET',
                    'path' => '/manifest-engine/api/v1/manifests/invoice',
                    'title' => 'Получить custom manifest',
                    'summary' => 'Схема по коду (подставьте свой code вместо invoice).',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'manifest с fields[].', 'example' => '{"ok":true,"manifest":{"code":"invoice","origin":"custom","fields":[{"name":"number","type":"string","required":true}]}}'],
                        ['code' => 404, 'description' => 'Не найден в scope.', 'example' => '{"ok":false,"error":"manifest не найден"}'],
                    ],
                ],
                [
                    'id' => 'mf-custom-patch',
                    'method' => 'PATCH',
                    'path' => '/manifest-engine/api/v1/manifests/invoice',
                    'title' => 'Обновить custom manifest',
                    'summary' => 'Только origin=custom; platform-manifest клиент изменить не может (403). version++.',
                    'auth' => 'Bearer session; tenant_admin / subtenant_admin.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => [
                        'content_type' => 'application/json',
                        'fields' => [
                            ['name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Новое имя.'],
                            ['name' => 'type', 'type' => 'string', 'required' => false, 'description' => 'Новый тип; "" — сброс.'],
                            ['name' => 'section', 'type' => 'string', 'required' => false, 'description' => 'Новый раздел каталога; "" — «Общие».'],
                            ['name' => 'fields', 'type' => 'array', 'required' => false, 'description' => 'Обновлённый набор полей.'],
                        ],
                        'example' => '{"name":"Счёт (обновлён)","type":"finance","section":"sales","fields":[{"name":"number","type":"string","required":true}]}',
                    ],
                    'responses' => [
                        ['code' => 200, 'description' => 'Обновлён.', 'example' => '{"ok":true,"manifest":{"code":"invoice","origin":"custom","version":2}}'],
                        ['code' => 403, 'description' => 'Попытка изменить platform-manifest.', 'example' => '{"ok":false,"error":"нельзя изменять platform manifest"}'],
                    ],
                ],
                [
                    'id' => 'mf-custom-delete',
                    'method' => 'DELETE',
                    'path' => '/manifest-engine/api/v1/manifests/invoice',
                    'title' => 'Архивировать custom manifest',
                    'summary' => 'status=archived. Только custom; platform — 403.',
                    'auth' => 'Bearer session; tenant_admin / subtenant_admin.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Архивирован.', 'example' => '{"ok":true}'],
                    ],
                ],
                [
                    'id' => 'mf-custom-openapi',
                    'method' => 'GET',
                    'path' => '/manifest-engine/api/v1/manifests/invoice/openapi',
                    'title' => 'OpenAPI custom-сущности (JSON)',
                    'summary' => 'Автогенерация OpenAPI 3 из manifest.fields[]: CRUD /api/data/{code}, PUT/DELETE по каждому полю, min/max и max_length в schema.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'OpenAPI document.', 'example' => '{"openapi":"3.0.0","paths":{"/api/data/invoice":{...}}}'],
                    ],
                ],
                [
                    'id' => 'mf-custom-openapi-yaml',
                    'method' => 'GET',
                    'path' => '/manifest-engine/api/v1/manifests/invoice/openapi.yaml',
                    'title' => 'OpenAPI custom-сущности (YAML)',
                    'summary' => 'Тот же контракт, Content-Type: application/yaml.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'YAML OpenAPI.', 'example' => 'openapi: 3.0.0\npaths:\n  /api/data/invoice: ...'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'mf-platform',
            'title' => 'Platform manifest (presets)',
            'endpoints' => [
                [
                    'id' => 'mf-field-types',
                    'method' => 'GET',
                    'path' => '/manifest-engine/api/v1/catalog/field-types',
                    'title' => 'Типы полей',
                    'summary' => 'Палитра для конструктора custom manifest.',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'field_types[].', 'example' => '{"ok":true,"field_types":[{"code":"string"},{"code":"number"},{"code":"boolean"}]}'],
                    ],
                ],
                [
                    'id' => 'mf-presets-list',
                    'method' => 'GET',
                    'path' => '/manifest-engine/api/v1/manifests/presets',
                    'title' => 'Список пресетов',
                    'summary' => 'Доступные platform-шаблоны (product, stock).',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'presets[].', 'example' => '{"ok":true,"presets":[{"code":"product","name":"Product"},{"code":"stock","name":"Stock"}]}'],
                    ],
                ],
                [
                    'id' => 'mf-presets-install',
                    'method' => 'POST',
                    'path' => '/manifest-engine/api/v1/manifests/presets/product',
                    'title' => 'Установить preset',
                    'summary' => 'Создаёт origin=platform manifest в project. Коды: product, stock.',
                    'auth' => 'Bearer session; tenant_admin / subtenant_admin.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 201, 'description' => 'Установлен.', 'example' => '{"ok":true,"manifest":{"code":"product","origin":"platform"}}'],
                        ['code' => 200, 'description' => 'Уже установлен (идемпотентно).', 'example' => '{"ok":true,"installed":false,"message":"preset уже установлен"}'],
                    ],
                ],
                [
                    'id' => 'mf-platform-data-list',
                    'method' => 'GET',
                    'path' => '/manifest-engine/api/data/product',
                    'title' => 'Данные platform-сущности',
                    'summary' => 'Тот же REST /api/data/{entity} для origin=platform (после install preset).',
                    'auth' => 'Bearer session.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'limit', 'required' => false, 'description' => 'Пагинация.'],
                        ['name' => 'offset', 'required' => false, 'description' => 'Смещение.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'records[].', 'example' => '{"ok":true,"records":[{"id":1,"data":{"code":"sku-1","name":"Товар"}}],"meta":{"total":1}}'],
                    ],
                ],
            ],
        ],
    ],
];

return $manifestDoc;
