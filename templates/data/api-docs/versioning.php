<?php
declare(strict_types=1);

return [
    'title' => 'Versioning API',
    'description' => 'Журнал версий записей: before/after JSON по таблицам в scope tenant/subtenant. '
        . 'Запись изменений включается автоматически для отслеживаемых таблиц (см. registry). '
        . 'Опционально: тарифный флаг features.versioning при VERSIONING_FEATURE_ENFORCE=true.',
    'openapi' => null,
    'common' => [
        'access' => [
            'title' => 'Доступ',
            'paragraphs' => [
                'Модуль: /versioning — отдельный HTTP-сервис, авторизация через RBAC session (Bearer access_token).',
                'Permissions: versioning.read (история), versioning.registry.read (реестр таблиц).',
                'UI: /versioning/admin — просмотр истории в браузере (token из localStorage после login).',
                'Подписка (будущее): при VERSIONING_FEATURE_ENFORCE=true нужен features.versioning=true в plan лицензии tenant.',
            ],
        ],
        'header_profiles' => [
            [
                'id' => 'bearer',
                'label' => 'Session Bearer',
                'note' => 'Тот же access_token, что и для RBAC API.',
                'headers' => [
                    ['name' => 'Authorization', 'required' => true, 'description' => 'Bearer {access_token}'],
                    ['name' => 'Accept', 'required' => false, 'description' => 'application/json'],
                ],
            ],
        ],
        'errors' => [
            ['code' => 401, 'description' => 'Не авторизован.', 'example' => '{"ok":false,"error":"Не авторизован"}'],
            ['code' => 403, 'description' => 'Нет permission или step-up.', 'example' => '{"ok":false,"error":"Недостаточно permissions"}'],
            ['code' => 402, 'description' => 'Нет подписки versioning (если включён feature enforce).', 'example' => '{"ok":false,"error":"История версий доступна по подписке versioning","code":"versioning_subscription_required"}'],
        ],
    ],
    'groups' => [
        [
            'id' => 'versioning-service',
            'title' => 'Сервис и реестр',
            'endpoints' => [
                [
                    'id' => 'ver-health',
                    'method' => 'GET',
                    'path' => '/versioning/health',
                    'title' => 'Health',
                    'summary' => 'Проверка доступности модуля versioning и флага записи (VERSIONING_ENABLED).',
                    'auth' => 'Без авторизации.',
                    'headers_profile' => 'none',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Сервис доступен.', 'example' => '{"ok":true,"service":"maniforge-versioning","recording":true}'],
                    ],
                ],
                [
                    'id' => 'ver-registry',
                    'method' => 'GET',
                    'path' => '/versioning/api/v1/registry',
                    'title' => 'Реестр таблиц',
                    'summary' => 'Список таблиц, для которых ведётся история изменений.',
                    'auth' => 'Bearer; versioning.registry.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Массив entity_table + label.', 'example' => '{"ok":true,"items":[{"entity_table":"maniforge_projects","entity_label":"Проекты","is_active":true}]}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'versioning-changes',
            'title' => 'История изменений',
            'endpoints' => [
                [
                    'id' => 'ver-changes-list',
                    'method' => 'GET',
                    'path' => '/versioning/api/v1/changes',
                    'title' => 'Список изменений',
                    'summary' => 'История версий в scope текущей сессии (tenant_id + subtenant_id). Сортировка: changed_at DESC.',
                    'auth' => 'Bearer; versioning.read.',
                    'headers_profile' => 'bearer',
                    'query' => [
                        ['name' => 'entity_table', 'required' => false, 'description' => 'Фильтр по таблице, напр. maniforge_users.'],
                        ['name' => 'entity_id', 'required' => false, 'description' => 'ID записи в таблице.'],
                        ['name' => 'operation', 'required' => false, 'description' => 'insert | update | delete.'],
                        ['name' => 'project_id', 'required' => false, 'description' => 'Фильтр по project_id.'],
                        ['name' => 'from', 'required' => false, 'description' => 'Начало периода (UTC datetime).'],
                        ['name' => 'to', 'required' => false, 'description' => 'Конец периода (UTC datetime).'],
                        ['name' => 'limit', 'required' => false, 'description' => '1–200, по умолчанию 50.'],
                        ['name' => 'offset', 'required' => false, 'description' => 'Смещение пагинации.'],
                    ],
                    'body' => null,
                    'responses' => [
                        [
                            'code' => 200,
                            'description' => 'items[] с before/after JSON; total — всего по фильтру.',
                            'example' => '{"ok":true,"items":[{"id":1,"entity_table":"maniforge_scope_variables","entity_id":"12","operation":"update","before":{"value":"a"},"after":{"value":"b"},"changed_at":"..."}],"total":1,"limit":50,"offset":0}',
                        ],
                    ],
                ],
                [
                    'id' => 'ver-changes-get',
                    'method' => 'GET',
                    'path' => '/versioning/api/v1/changes/{id}',
                    'title' => 'Одна запись истории',
                    'summary' => 'Детали изменения по id в scope сессии.',
                    'auth' => 'Bearer; versioning.read.',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'Объект change.', 'example' => '{"ok":true,"change":{"id":1,"entity_table":"maniforge_users","operation":"update","before":{},"after":{}}}'],
                        ['code' => 404, 'description' => 'Не найдено в scope.', 'example' => '{"ok":false,"error":"Запись истории не найдена"}'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'versioning-tracked',
            'title' => 'Автоматически отслеживаемые таблицы',
            'endpoints' => [
                [
                    'id' => 'ver-tracked-note',
                    'method' => 'GET',
                    'path' => '/versioning/admin',
                    'title' => 'UI просмотра (HTML)',
                    'summary' => 'Веб-интерface истории. Запись в журнал выполняется автоматически при мутациях RBAC: users, projects, scope_variables, roles, role_permissions, user_roles, policy_rules, sessions (revoke), project memberships, registration invites, profile/password.',
                    'auth' => 'Bearer token в браузере (localStorage maniforge_admin_access_token).',
                    'headers_profile' => 'bearer',
                    'query' => [],
                    'body' => null,
                    'responses' => [
                        ['code' => 200, 'description' => 'HTML-страница.', 'example' => ''],
                    ],
                ],
            ],
        ],
    ],
];
