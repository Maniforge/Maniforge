<?php
declare(strict_types=1);

/**
 * Контент главной `/` — только hero, метрики, навигация по разделам, тариф-тизер и CTA.
 * Подробности — на /why, /modules, /security, /get-started, /pricing, /developers.
 */
return [
    'stats' => [
        [
            'icon' => 'grid-3x3-gap',
            'value' => '9',
            'label' => 'модулей',
            'hint' => 'RBAC, licensing, склад',
            'href' => '/modules',
        ],
        [
            'icon' => 'layers',
            'value' => 'Стек',
            'label' => 'технологий',
            'hint' => 'Go, PostgreSQL, React',
            'href' => '/stack',
        ],
        [
            'icon' => 'shield-lock',
            'value' => '152‑ФЗ',
            'label' => 'compliance',
            'hint' => 'PII, MFA, аудит',
            'href' => '/security',
        ],
        [
            'icon' => 'check2-circle',
            'value' => 'E2E',
            'label' => 'journey-тесты',
            'hint' => 'контракт API проверен',
            'href' => '/get-started',
        ],
    ],
    'explore' => [
        [
            'icon' => 'lightning-charge',
            'title' => 'Почему Maniforge',
            'text' => 'Боли CTO и готовые ответы платформы.',
            'href' => '/why',
        ],
        [
            'icon' => 'grid-3x3-gap',
            'title' => 'Модули',
            'text' => '9 сервисов: RBAC, склад, manifest, realtime.',
            'href' => '/modules',
        ],
        [
            'icon' => 'shield-lock',
            'title' => 'Безопасность',
            'text' => '152‑ФЗ, MFA, аудит и журнал версий.',
            'href' => '/security',
        ],
        [
            'icon' => 'signpost-split',
            'title' => 'Как начать',
            'text' => '10 минут до первого API локально.',
            'href' => '/get-started',
        ],
    ],
    'pricing_teaser' => [
        'title' => 'Без скрытых лимитов',
        'text' => 'Free для разработки. Starter и Business — для пилота и роста.',
    ],
    'cta' => [
        'title' => 'Пришёл. Увидел. Запустил.',
        'lead' => 'Локальный Free‑тариф: один tenant, все модули.',
        'primary_href' => '/register',
        'primary_label' => 'Попробовать',
        'secondary_href' => '/get-started',
        'secondary_label' => 'Как начать',
    ],
];
