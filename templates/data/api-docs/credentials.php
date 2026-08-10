<?php
declare(strict_types=1);

return [
    'title' => 'Ключи и авторизация',
    'description' => 'Какой токен нужен для вашего сценария: вход пользователя, вызов API модуля или операции платформы.',
    'actions' => [
        ['label' => 'Заготовки JSON', 'href' => '#api-headers-kit', 'tab' => 'api-headers-docs'],
        ['label' => 'Полная спецификация', 'href' => '/docs/MANIFORGE_CREDENTIAL_ARCHITECTURE.md'],
    ],
    'overview' => [
        'levels' => [
            [
                'title' => 'Сессия пользователя',
                'badge' => 'Обычный клиент',
                'summary' => 'access_token после login — для RBAC и модулей.',
                'anchor' => 'api-credentials-session',
            ],
            [
                'title' => 'Подтверждение admin-действий',
                'badge' => 'Step-up',
                'summary' => 'action_token после reauth — только для опасных admin-мутаций.',
                'anchor' => 'api-credentials-action',
            ],
            [
                'title' => 'Платформа и сервисы',
                'badge' => 'Не для браузера',
                'summary' => 'Секреты из env — только оператор и backend-сервисы.',
                'anchor' => 'api-credentials-platform',
            ],
        ],
        'flow' => [
            [
                'title' => 'Вход',
                'text' => 'POST /rbac/api/v1/auth/login → access_token, refresh_token, csrf_token.',
            ],
            [
                'title' => 'Чтение',
                'text' => 'GET и прочие без изменений — Authorization: Bearer {access_token}.',
            ],
            [
                'title' => 'Изменение',
                'text' => 'POST / PATCH / DELETE — Bearer + X-CSRF-Token.',
            ],
            [
                'title' => 'Опасная admin-операция',
                'text' => 'POST /auth/reauth → Bearer + X-Action-Token + CSRF.',
            ],
        ],
    ],
    'sections' => [
        [
            'id' => 'api-credentials-session',
            'title' => 'Сессия пользователя',
            'tokens' => [
                [
                    'name' => 'access_token',
                    'label' => 'Токен доступа',
                    'when' => 'Каждый защищённый запрос к RBAC и модулям.',
                    'how_get' => 'POST /rbac/api/v1/auth/login → credentials.session.access_token',
                    'how_send' => 'Authorization: Bearer {access_token}',
                    'lifetime' => '~12 часов',
                    'header_prefix' => 'rbac',
                    'header_profile' => 'bearer',
                ],
                [
                    'name' => 'refresh_token',
                    'label' => 'Токен обновления',
                    'when' => 'Продление сессии без повторного login.',
                    'how_get' => 'Ответ login; POST /auth/refresh',
                    'how_send' => 'В теле JSON, не в заголовке',
                    'lifetime' => 'Дольше access',
                    'header_prefix' => 'rbac',
                    'header_profile' => 'refresh',
                ],
                [
                    'name' => 'csrf_token',
                    'label' => 'CSRF-токен',
                    'when' => 'RBAC POST, PATCH, PUT, DELETE.',
                    'how_get' => 'Поле csrf_token в ответе login',
                    'how_send' => 'X-CSRF-Token',
                    'lifetime' => 'Пока жива сессия',
                    'header_prefix' => 'rbac',
                    'header_profile' => 'bearer_csrf',
                ],
            ],
        ],
        [
            'id' => 'api-credentials-action',
            'title' => 'Подтверждение admin-действий',
            'tokens' => [
                [
                    'name' => 'action_token',
                    'label' => 'Action-токен',
                    'when' => 'Admin-мутации при ответе step_up_required.',
                    'how_get' => 'POST /rbac/api/v1/auth/reauth',
                    'how_send' => 'X-Action-Token + Bearer + X-CSRF-Token',
                    'lifetime' => '~15 минут',
                    'header_prefix' => 'rbac',
                    'header_profile' => 'bearer_action_csrf',
                ],
            ],
        ],
        [
            'id' => 'api-credentials-platform',
            'title' => 'Платформа и сервисы',
            'tokens' => [
                [
                    'name' => 'TENANT_LICENSING_ADMIN_TOKEN',
                    'label' => 'Токен оператора платформы',
                    'when' => 'Tenant Licensing: тенанты, тарифы, лицензии.',
                    'how_get' => 'Секрет платформы (env)',
                    'how_send' => 'Authorization: Bearer',
                    'lifetime' => 'Долгоживущий',
                    'client_warning' => 'Не для браузера и не для админа клиента в RBAC.',
                    'header_prefix' => 'platform',
                    'header_profile' => 'platform',
                ],
                [
                    'name' => 'INTERNAL_SERVICE_TOKENS',
                    'label' => 'Внутренние токены (сервис-сервис)',
                    'when' => 'Межсервисные вызовы licensing и RBAC events.',
                    'how_get' => 'TENANT_LICENSING_INTERNAL_TOKEN, RBAC_INTERNAL_TOKEN — из env',
                    'how_send' => 'Authorization: Bearer',
                    'lifetime' => 'Долгоживущий',
                    'header_prefix' => 'platform',
                    'header_profile' => 'internal',
                ],
            ],
        ],
        [
            'id' => 'api-credentials-tenancy',
            'title' => 'Организация и workspace',
            'scenarios' => [
                [
                    'title' => 'single',
                    'text' => 'Tenant/workspace из настроек сервера — на login заголовки не нужны.',
                ],
                [
                    'title' => 'multi',
                    'text' => 'X-Tenant-ID и X-Subtenant-ID только на POST /auth/login. Дальше — Bearer.',
                ],
                [
                    'title' => 'agency',
                    'text' => 'GET /me/contexts → POST /auth/switch-context для смены организации.',
                ],
            ],
        ],
    ],
];
