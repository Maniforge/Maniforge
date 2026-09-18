<?php
declare(strict_types=1);

/**
 * Контент страницы /developers — хаб документации для интеграции.
 */
return [
    'intro' => 'Справочник для интеграции: методы API, модули, OpenAPI и стек. Подключение к платформе — через регистрацию; свой инстанс — по README репозитория.',
    'links' => [
        [
            'icon' => 'signpost-split',
            'title' => 'Как начать',
            'href' => '/get-started',
            'description' => 'Регистрация на Free, организация и первый запрос к API.',
        ],
        [
            'icon' => 'book',
            'title' => 'Каталог API',
            'href' => '/api',
            'description' => 'Эндпоинты, примеры тел запросов и ответов.',
        ],
        [
            'icon' => 'grid-3x3-gap',
            'title' => 'Модули',
            'href' => '/modules',
            'description' => 'Что входит в платформу и куда ведёт кнопка API.',
        ],
        [
            'icon' => 'filetype-yml',
            'title' => 'RBAC OpenAPI',
            'href' => '/docs/maniforge-rbac-openapi.yaml',
            'description' => 'Контракт: вход, сессии, роли, проекты.',
        ],
        [
            'icon' => 'filetype-yml',
            'title' => 'Licensing OpenAPI',
            'href' => '/docs/maniforge-tenant-licensing-openapi.yaml',
            'description' => 'Контракт: tenant, планы, лицензии, квоты.',
        ],
        [
            'icon' => 'layers',
            'title' => 'Стек',
            'href' => '/stack',
            'description' => 'Go, PostgreSQL, React — для архитекторов и техлидов.',
        ],
    ],
    'cta' => [
        'title' => 'Попробовать платформу',
        'lead' => 'Тариф Free — регистрация и доступ к модулям без оплаты. Self-hosted — см. README в репозитории.',
        'primary_label' => 'Регистрация',
        'primary_href' => '/register',
        'secondary_label' => 'Тарифы',
        'secondary_href' => '/pricing',
    ],
];
