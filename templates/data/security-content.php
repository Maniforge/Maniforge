<?php
declare(strict_types=1);

/**
 * Контент страницы «Безопасность» (`/security`).
 */
return [
    'intro' => 'Тенантность, MFA, аудит и 152-ФЗ заложены в архитектуру — не как опция, а как базовый слой платформы.',
    'title' => 'Не на словах',
    'lead' => 'Клиент — оператор персональных данных. Maniforge — обработчик: шифрование, согласия, журналы и ответы субъекту.',
    'items' => [
        [
            'icon' => 'shield-check',
            'title' => '152‑ФЗ',
            'text' => 'Клиент — оператор ПДн. Maniforge — обработчик: шифрование, согласия, ответы субъекту.',
            'href' => '/docs/152FZ_COMPLIANCE.md',
        ],
        [
            'icon' => 'journal-text',
            'title' => 'Аудит',
            'text' => 'Кто, когда и что изменил — в журнале. Версионность записей по tenant.',
            'href' => '/versioning/admin',
        ],
        [
            'icon' => 'fingerprint',
            'title' => 'MFA и политики',
            'text' => 'TOTP, step‑up, IP/time policies, rate limit и lockout.',
            'href' => '/docs/maniforge-enterprise-hardening.md',
        ],
    ],
    'docs' => [
        [
            'title' => '152‑ФЗ compliance',
            'href' => '/docs/152FZ_COMPLIANCE.md',
        ],
        [
            'title' => 'Enterprise hardening',
            'href' => '/docs/maniforge-enterprise-hardening.md',
        ],
        [
            'title' => 'Security incident workflow',
            'href' => '/docs/maniforge-security-incident-workflow.md',
        ],
    ],
];
