<?php
declare(strict_types=1);

$rbacCommon = api_doc_rbac_common();
$platformCommon = api_doc_platform_common();

return [
    'title' => 'Заголовки',
    'description' => 'JSON-заготовки MF_HEADER_* для вставки в fetch, Postman и клиенты. Типы токенов — в справочнике «Ключи».',
    'actions' => [
        ['label' => 'Ключи', 'href' => '#api-credentials-overview', 'tab' => 'api-credentials-docs'],
        ['label' => 'OpenAPI RBAC', 'href' => '/docs/maniforge-rbac-openapi.yaml'],
    ],
    'overview' => [
        'title' => 'Обзор',
        'paragraphs' => [
            'Нажмите MF_HEADER_* — в буфер попадёт JSON-объект заголовков с плейсхолдерами {access_token}, {csrf_token} и т.д.',
            'Какой токен когда нужен — см. справочник «Ключи». Здесь только состав HTTP-заголовков для копирования.',
        ],
        'headers' => [
            ['name' => 'Accept', 'scope' => 'Все методы', 'required' => 'нет', 'description' => 'application/json (рекомендуется).'],
            ['name' => 'Content-Type', 'scope' => 'Запросы с телом', 'required' => 'да', 'description' => 'application/json'],
            ['name' => 'Authorization', 'scope' => 'Защищённые методы', 'required' => 'да', 'description' => 'Bearer {token} — access_token, platform token или internal token.'],
            ['name' => 'X-Tenant-ID', 'scope' => 'POST /auth/login (multi)', 'required' => 'да*', 'description' => 'Код организации. В single-режиме не нужен.'],
            ['name' => 'X-Subtenant-ID', 'scope' => 'POST /auth/login (multi)', 'required' => 'да*', 'description' => 'Код workspace. Альтернатива: tenant_id/subtenant_id в JSON.'],
            ['name' => 'X-CSRF-Token', 'scope' => 'RBAC мутации с CSRF', 'required' => 'да', 'description' => 'csrf_token из ответа login.'],
            ['name' => 'X-Action-Token', 'scope' => 'RBAC admin step-up', 'required' => 'да', 'description' => 'Короткоживущий токен из POST /auth/reauth.'],
        ],
    ],
    'sections' => [
        [
            'id' => 'headers-rbac',
            'profile_prefix' => 'rbac',
            'title' => 'RBAC — сессия пользователя',
            'lead' => '',
            'profiles' => $rbacCommon['header_profiles'],
        ],
        [
            'id' => 'headers-platform',
            'profile_prefix' => 'platform',
            'title' => 'Tenant Licensing — платформа',
            'lead' => '',
            'profiles' => $platformCommon['header_profiles'],
        ],
        [
            'id' => 'headers-modules',
            'profile_prefix' => 'modules',
            'title' => 'Модули (Manifest, Versioning, Realtime, Supply Chain)',
            'lead' => '',
            'profiles' => [
                [
                    'id' => 'none',
                    'label' => 'Без авторизации',
                    'note' => 'GET /{module}/health и мониторинг.',
                    'headers' => [
                        ['name' => 'Accept', 'required' => false, 'description' => 'application/json (рекомендуется).'],
                    ],
                ],
                [
                    'id' => 'bearer',
                    'label' => 'Session Bearer',
                    'note' => 'Все data-методы модулей после login.',
                    'headers' => api_doc_headers_bearer_session(false),
                ],
            ],
        ],
    ],
];
