<?php
declare(strict_types=1);

/**
 * Технологический стек Maniforge (страница /stack).
 */
return [
    'intro' => 'Два runtime-контура — Go для модулей и PHP для сайта/референса — одна PostgreSQL, общие REST-контракты и journey-тесты.',
    'groups' => [
        [
            'id' => 'backend-go',
            'title' => 'Backend — Go',
            'icon' => 'lightning-charge',
            'items' => [
                ['name' => 'Go 1.25', 'detail' => 'Основной runtime: RBAC, licensing, manifest, versioning, realtime, supply chain'],
                ['name' => 'Fiber v2', 'detail' => 'HTTP API, middleware, health-чеки, маршрутизация модулей'],
                ['name' => 'pgx v5', 'detail' => 'Драйвер PostgreSQL, пул соединений, миграции через cmd/migrate'],
                ['name' => 'WebSocket', 'detail' => 'gofiber/contrib/websocket — модуль Realtime'],
                ['name' => 'argon2id + OTP', 'detail' => 'Хеши паролей, TOTP MFA (pquerna/otp)'],
                ['name' => 'YAML / godotenv', 'detail' => 'OpenAPI-манифесты, конфигурация сервисов'],
            ],
        ],
        [
            'id' => 'backend-php',
            'title' => 'Backend — PHP',
            'icon' => 'filetype-php',
            'items' => [
                ['name' => 'PHP 8.2', 'detail' => 'Маркетинговый сайт, прокси модулей, legacy-админка, WMS-референс'],
                ['name' => 'app/Maniforge/*', 'detail' => 'Референсные модули: RBAC, licensing, supply chain, journey-тесты'],
                ['name' => 'Встроенный сервер', 'detail' => 'make run-web → public/index.php, единая точка входа'],
                ['name' => 'MySQL (референс)', 'detail' => 'Legacy-миграции PHP RBAC до полного cutover на PostgreSQL'],
            ],
        ],
        [
            'id' => 'data',
            'title' => 'Данные',
            'icon' => 'database',
            'items' => [
                ['name' => 'PostgreSQL 16', 'detail' => 'Docker Compose, единая схема migrations/pg/'],
                ['name' => 'maniforge-migrate', 'detail' => 'CLI-миграции: make migrate'],
                ['name' => 'tenant_id + project_id', 'detail' => 'Изоляция модулей; licensing runtime по tenant'],
                ['name' => 'PII codec', 'detail' => 'AES-256-GCM для email/phone в профилях'],
            ],
        ],
        [
            'id' => 'frontend',
            'title' => 'Frontend',
            'icon' => 'layout-text-window',
            'items' => [
                ['name' => 'React 19', 'detail' => 'Admin SPA (/app) и Scanner (/scanner)'],
                ['name' => 'TypeScript 5.7', 'detail' => 'Строгая типизация фронтенд-приложений'],
                ['name' => 'Vite 6', 'detail' => 'Сборка и dev-server: make frontend-dev, scanner-dev'],
                ['name' => 'React Router 7', 'detail' => 'Клиентская маршрутизация SPA'],
                ['name' => 'PHP templates', 'detail' => 'Лендинг, вход, pricing, legacy /admin'],
                ['name' => 'Bootstrap 5.3', 'detail' => 'UI-кит сайта и Bootstrap Icons'],
                ['name' => 'Refine scaffold', 'detail' => 'Прототип Manifest UI: /refine-manifest, make manifest-refine-gen'],
            ],
        ],
        [
            'id' => 'api',
            'title' => 'API и интеграции',
            'icon' => 'braces',
            'items' => [
                ['name' => 'REST JSON', 'detail' => 'Единые контракты Go ↔ PHP, паритет journey-тестов'],
                ['name' => 'OpenAPI', 'detail' => 'RBAC, licensing YAML; manifest — автогенерация из схемы'],
                ['name' => 'WebSocket events', 'detail' => 'Подписки manifest/record, REST /api/v1/subscriptions'],
                ['name' => 'Health endpoints', 'detail' => 'Каждый модуль — /{prefix}/health для проверки доступности'],
            ],
        ],
        [
            'id' => 'ops',
            'title' => 'Инфраструктура и сборка',
            'icon' => 'gear',
            'items' => [
                ['name' => 'Docker Compose', 'detail' => 'postgres:16-alpine, volume maniforge_pg_data'],
                ['name' => 'Make', 'detail' => 'build, migrate, run-*, *-journey, frontend-build'],
                ['name' => 'Go modules', 'detail' => 'maniforge/go.mod, бинарники в bin/'],
                ['name' => 'GitHub Actions', 'detail' => 'CI: PHP 8.2, rbac-checks, journey в pipeline'],
            ],
        ],
        [
            'id' => 'quality',
            'title' => 'Тесты и качество',
            'icon' => 'check2-circle',
            'items' => [
                ['name' => 'Go test', 'detail' => 'Юнит и интеграционные тесты internal/*'],
                ['name' => 'make *-journey', 'detail' => 'E2E сценарии: rbac, manifest, warehouses, inventory, enterprise'],
                ['name' => 'PHP journey', 'detail' => 'maniforge/rbac/tools — регрессия при портировании'],
                ['name' => 'preflight / racebench', 'detail' => 'Проверка окружения и нагрузочный бенчмарк RBAC'],
            ],
        ],
        [
            'id' => 'security',
            'title' => 'Безопасность',
            'icon' => 'shield-lock',
            'items' => [
                ['name' => 'RBAC', 'detail' => 'Роли, permissions, deny-by-default, licensing client'],
                ['name' => 'Сессии', 'detail' => 'Refresh, revoke, security_version++, CSRF'],
                ['name' => 'MFA / policies', 'detail' => 'TOTP, step-up, IP/time, rate limit, lockout'],
                ['name' => '152‑ФЗ', 'detail' => 'Согласия, subject-requests, audit log, versioning с redact PII'],
            ],
        ],
        [
            'id' => 'roadmap',
            'title' => 'Roadmap',
            'icon' => 'signpost-2',
            'items' => [
                ['name' => 'Redis', 'detail' => 'Кэш сессий и hot data — в планах'],
                ['name' => 'RabbitMQ', 'detail' => 'Очереди, вебхуки, фоновые задачи — в планах'],
                ['name' => 'Refine Admin', 'detail' => 'Полноценная React-админка вместо legacy PHP — см. UI strategy'],
            ],
        ],
    ],
];
