<?php
declare(strict_types=1);

/**
 * Каталог секций /api: группировка по назначению и модулям.
 *
 * @return array{
 *     categories: list<array{
 *         id: string,
 *         title: string,
 *         description: string,
 *         modules: list<array{
 *             key: string,
 *             file: string,
 *             section_id: string,
 *             nav_label: string,
 *             nav_purpose: string,
 *             badge: string,
 *             badge_label: string,
 *             common_nav_label: string,
 *             actions?: list<array{label: string, href: string}>
 *         }>
 *     }>,
 *     references: list<array{label: string, href: string}>
 * }
 */
return [
    'default_module_key' => 'public',
    'categories' => [
        [
            'id' => 'reference',
            'title' => 'Справочник',
            'description' => 'Общие правила HTTP и авторизации для всех модулей.',
            'modules' => [
                [
                    'key' => 'credentials',
                    'file' => 'credentials.php',
                    'section_id' => 'api-credentials-docs',
                    'nav_label' => 'Ключи',
                    'nav_purpose' => 'Какой токен для какого запроса',
                    'badge' => 'reference',
                    'badge_label' => 'Справочник',
                    'common_nav_label' => 'Обзор',
                    'is_reference' => true,
                    'reference_panel' => 'credentials',
                ],
                [
                    'key' => 'headers',
                    'file' => 'headers.php',
                    'section_id' => 'api-headers-docs',
                    'nav_label' => 'Заголовки',
                    'nav_purpose' => 'MF_HEADER_*, JSON для клиента',
                    'badge' => 'reference',
                    'badge_label' => 'Справочник',
                    'common_nav_label' => 'Обзор',
                    'is_reference' => true,
                    'reference_panel' => 'headers',
                ],
            ],
        ],
        [
            'id' => 'personal',
            'title' => 'Персональные',
            'description' => 'Сгенерированный REST custom manifest: вкладки по разделу (section), сущности по названию (name).',
            'modules' => [],
        ],
        [
            'id' => 'platform',
            'title' => 'Платформа',
            'description' => 'Доступ, лицензии, динамические сущности, аудит и события.',
            'modules' => [
                [
                    'key' => 'public',
                    'file' => 'public.php',
                    'section_id' => 'api-public-docs',
                    'nav_label' => 'RBAC',
                    'nav_purpose' => 'Вход, сессии, роли, проекты',
                    'badge' => 'public',
                    'badge_label' => 'Публичное',
                    'common_nav_label' => 'Обзор',
                    'actions' => [
                        ['label' => 'OpenAPI YAML', 'href' => '/docs/maniforge-rbac-openapi.yaml'],
                        ['label' => 'Ключи', 'href' => '#api-credentials-overview', 'tab' => 'api-credentials-docs'],
                    ],
                ],
                [
                    'key' => 'private',
                    'file' => 'private.php',
                    'section_id' => 'api-private-docs',
                    'nav_label' => 'Tenant Licensing',
                    'nav_purpose' => 'Тенанты, тарифы, лицензии (платформа)',
                    'badge' => 'private',
                    'badge_label' => 'Приватное',
                    'common_nav_label' => 'Обзор',
                    'actions' => [
                        ['label' => 'OpenAPI YAML', 'href' => '/docs/maniforge-tenant-licensing-openapi.yaml'],
                    ],
                ],
                [
                    'key' => 'manifest',
                    'file' => 'manifest.php',
                    'section_id' => 'api-manifest-docs',
                    'nav_label' => 'Manifest Engine',
                    'nav_purpose' => 'Custom manifest REST + platform presets',
                    'badge' => 'module',
                    'badge_label' => 'Модуль',
                    'common_nav_label' => 'Scope и авторизация',
                    'actions' => [
                        ['label' => 'Персональные', 'href' => '/api#api-personal-sales-docs'],
                        ['label' => 'Custom REST (схемы)', 'href' => '/api#mf-custom-schemas'],
                        ['label' => 'Полная спека (MD)', 'href' => '/docs/maniforge-manifest-engine.md'],
                        ['label' => 'UI прототип', 'href' => '/refine-manifest'],
                    ],
                ],
                [
                    'key' => 'versioning',
                    'file' => 'versioning.php',
                    'section_id' => 'api-versioning-docs',
                    'nav_label' => 'Versioning',
                    'nav_purpose' => 'Журнал изменений записей',
                    'badge' => 'module',
                    'badge_label' => 'Модуль',
                    'common_nav_label' => 'Доступ и permissions',
                    'actions' => [
                        ['label' => 'UI истории', 'href' => '/versioning/admin'],
                    ],
                ],
                [
                    'key' => 'realtime',
                    'file' => 'realtime.php',
                    'section_id' => 'api-realtime-docs',
                    'nav_label' => 'Realtime',
                    'nav_purpose' => 'WebSocket и подписки на события',
                    'badge' => 'module',
                    'badge_label' => 'Модуль',
                    'common_nav_label' => 'Каналы и flow',
                    'actions' => [
                        ['label' => 'Протокол WS (MD)', 'href' => '/docs/maniforge-realtime.md'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'supply-chain',
            'title' => 'Supply Chain',
            'description' => 'Склады, номенклатура, остатки и маркировка для WMS.',
            'modules' => [
                [
                    'key' => 'warehouses',
                    'file' => 'warehouses.php',
                    'section_id' => 'api-warehouses-docs',
                    'nav_label' => 'Warehouses',
                    'nav_purpose' => 'Иерархия складских узлов',
                    'badge' => 'module',
                    'badge_label' => 'Модуль',
                    'common_nav_label' => 'RBAC и permissions',
                    'actions' => [
                        ['label' => 'Markdown', 'href' => '/docs/maniforge-warehouses.md'],
                    ],
                ],
                [
                    'key' => 'products',
                    'file' => 'products.php',
                    'section_id' => 'api-products-docs',
                    'nav_label' => 'Products',
                    'nav_purpose' => 'Номенклатура SKU',
                    'badge' => 'module',
                    'badge_label' => 'Модуль',
                    'common_nav_label' => 'RBAC и permissions',
                    'actions' => [
                        ['label' => 'Markdown', 'href' => '/docs/maniforge-products.md'],
                    ],
                ],
                [
                    'key' => 'inventory',
                    'file' => 'inventory.php',
                    'section_id' => 'api-inventory-docs',
                    'nav_label' => 'Inventory',
                    'nav_purpose' => 'Остатки, движения, заказы',
                    'badge' => 'module',
                    'badge_label' => 'Модуль',
                    'common_nav_label' => 'RBAC и permissions',
                    'actions' => [
                        ['label' => 'Markdown', 'href' => '/docs/maniforge-inventory.md'],
                    ],
                ],
                [
                    'key' => 'wms',
                    'file' => 'wms.php',
                    'section_id' => 'api-wms-docs',
                    'nav_label' => 'WMS',
                    'nav_purpose' => 'КИЗ, упаковки, сканирование',
                    'badge' => 'module',
                    'badge_label' => 'Модуль',
                    'common_nav_label' => 'RBAC и permissions',
                    'actions' => [
                        ['label' => 'Markdown', 'href' => '/docs/maniforge-wms.md'],
                        ['label' => 'UI сканера', 'href' => '/docs/maniforge-wms-scanner-ui.md'],
                    ],
                ],
            ],
        ],
    ],
    'references' => [
        ['label' => 'Глоссарий', 'href' => '/docs/maniforge-glossary.md'],
        ['label' => '152‑ФЗ / ПДн', 'href' => '/docs/152FZ_COMPLIANCE.md'],
        ['label' => 'Обработчик SaaS', 'href' => '/docs/maniforge-pd-processor-platform.md'],
    ],
];
