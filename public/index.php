<?php
declare(strict_types=1);

use App\Support\MarkdownDocPage;

require_once dirname(__DIR__) . '/config/bootstrap.php';

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (servePublicAsset($requestUri)) {
    return;
}

// Маркетинговый лендинг — всегда PHP, до SPA-роутера (не перехватывается /app/*).
if ($requestUri === '/') {
    require dirname(__DIR__) . '/templates/home.php';
    return;
}

if (serveSpaAt($requestUri, '/app', 'app') || serveSpaAt($requestUri, '/scanner', 'scanner')) {
    return;
}

if (str_starts_with($requestUri, '/nzg/') || $requestUri === '/nzg') {
    header('Location: ' . (str_starts_with($requestUri, '/nzg/') ? substr($requestUri, 4) : '/'), true, 301);
    return;
}

if (str_starts_with($requestUri, '/maniforge/') || $requestUri === '/maniforge') {
    header('Location: ' . (str_starts_with($requestUri, '/maniforge/') ? substr($requestUri, 10) : '/'), true, 301);
    return;
}

if (preg_match('#^/docs/nzg-(.+)$#', $requestUri, $legacyDoc)) {
    header('Location: /docs/maniforge-' . $legacyDoc[1], true, 301);
    return;
}

if (str_starts_with($requestUri, '/rbac')) {
    require dirname(__DIR__) . '/maniforge/rbac/public/index.php';
    return;
}

if (str_starts_with($requestUri, '/tenant-licensing')) {
    require dirname(__DIR__) . '/maniforge/tenant-licensing/public/index.php';
    return;
}

if (str_starts_with($requestUri, '/versioning')) {
    require dirname(__DIR__) . '/maniforge/versioning/public/index.php';
    return;
}

if (str_starts_with($requestUri, '/warehouses')) {
    require dirname(__DIR__) . '/maniforge/warehouses/public/index.php';
    return;
}

if (str_starts_with($requestUri, '/products')) {
    require dirname(__DIR__) . '/maniforge/products/public/index.php';
    return;
}

if (str_starts_with($requestUri, '/inventory')) {
    require dirname(__DIR__) . '/maniforge/inventory/public/index.php';
    return;
}

if (str_starts_with($requestUri, '/wms')) {
    require dirname(__DIR__) . '/maniforge/wms/public/index.php';
    return;
}

switch ($requestUri) {
    case '/':
        require dirname(__DIR__) . '/templates/home.php';
        break;
    case '/pricing':
    case '/tarify':
        require dirname(__DIR__) . '/templates/pricing.php';
        break;
    case '/login':
        require dirname(__DIR__) . '/templates/login.php';
        break;
    case '/register':
    case '/register/':
        require dirname(__DIR__) . '/templates/register.php';
        break;
    case '/profile':
        require dirname(__DIR__) . '/templates/profile.php';
        break;
    case '/admin':
        require dirname(__DIR__) . '/templates/admin.php';
        break;
    case '/refine-manifest':
    case '/refine-manifest/':
        require dirname(__DIR__) . '/templates/refine-manifest/index.php';
        break;
    case '/operator':
        require dirname(__DIR__) . '/templates/agency.php';
        break;
    case '/agency':
        header('Location: /operator', true, 302);
        break;
    case '/admin/tenant':
        require dirname(__DIR__) . '/templates/admin-tenant.php';
        break;
    case '/projects':
        require dirname(__DIR__) . '/templates/projects.php';
        break;
    case '/admin/platform':
        require dirname(__DIR__) . '/templates/admin-platform.php';
        break;
    case '/api':
        require dirname(__DIR__) . '/templates/api.php';
        break;
    case '/developers':
        require dirname(__DIR__) . '/templates/developers.php';
        break;
    case '/stack':
        require dirname(__DIR__) . '/templates/stack.php';
        break;
    case '/modules':
        require dirname(__DIR__) . '/templates/modules.php';
        break;
    case '/get-started':
        require dirname(__DIR__) . '/templates/get-started.php';
        break;
    case '/security':
        require dirname(__DIR__) . '/templates/security.php';
        break;
    case '/why':
        require dirname(__DIR__) . '/templates/why.php';
        break;
    case '/docs/maniforge-rbac-openapi.yaml':
        $path = dirname(__DIR__) . '/docs/MANIFORGE_RBAC_OPENAPI.yaml';
        if (is_file($path)) {
            header('Content-Type: application/yaml; charset=utf-8');
            readfile($path);
            break;
        }
        http_response_code(404);
        require dirname(__DIR__) . '/templates/404.php';
        break;
    case '/docs/MANIFORGE_CREDENTIAL_ARCHITECTURE.md':
    case '/docs/maniforge-credential-architecture.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_CREDENTIAL_ARCHITECTURE.md',
            'Модель ключей Maniforge',
            '/docs/MANIFORGE_CREDENTIAL_ARCHITECTURE.md'
        );
        break;
    case '/docs/maniforge-glossary.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_GLOSSARY.md',
            'Глоссарий Maniforge',
            '/docs/maniforge-glossary.md'
        );
        break;
    case '/docs/maniforge-entity-scope.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_ENTITY_SCOPE.md',
            'Scope сущностей',
            '/docs/maniforge-entity-scope.md'
        );
        break;
    case '/docs/maniforge-tenant-delegation.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_TENANT_DELEGATION.md',
            'Делегирование tenant',
            '/docs/maniforge-tenant-delegation.md'
        );
        break;
    case '/docs/maniforge-enterprise-hardening.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_ENTERPRISE_HARDENING.md',
            'Enterprise hardening',
            '/docs/maniforge-enterprise-hardening.md'
        );
        break;
    case '/docs/maniforge-new-user-workflow.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_NEW_USER_WORKFLOW.md',
            'Workflow нового пользователя',
            '/docs/maniforge-new-user-workflow.md'
        );
        break;
    case '/docs/maniforge-supply-chain-modules.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_SUPPLY_CHAIN_MODULES.md',
            'Модули Supply Chain',
            '/docs/maniforge-supply-chain-modules.md'
        );
        break;
    case '/docs/maniforge-warehouses.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_WAREHOUSES.md',
            'Maniforge Warehouses',
            '/docs/maniforge-warehouses.md'
        );
        break;
    case '/docs/maniforge-products.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_PRODUCTS.md',
            'Maniforge Products',
            '/docs/maniforge-products.md'
        );
        break;
    case '/docs/maniforge-inventory.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_INVENTORY.md',
            'Maniforge Inventory',
            '/docs/maniforge-inventory.md'
        );
        break;
    case '/docs/maniforge-wms.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_WMS.md',
            'Maniforge WMS',
            '/docs/maniforge-wms.md'
        );
        break;
    case '/docs/maniforge-supply-chain-audit.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_SUPPLY_CHAIN_AUDIT.md',
            'Maniforge Supply Chain Audit',
            '/docs/maniforge-supply-chain-audit.md'
        );
        break;
    case '/docs/maniforge-wms-scanner-ui.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_WMS_SCANNER_UI.md',
            'Maniforge WMS — UI сканера',
            '/docs/maniforge-wms-scanner-ui.md'
        );
        break;
    case '/docs/maniforge-realtime.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_REALTIME.md',
            'Maniforge Realtime',
            '/docs/maniforge-realtime.md'
        );
        break;
    case '/docs/maniforge-manifest-engine.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_MANIFEST_ENGINE.md',
            'Maniforge Manifest Engine',
            '/docs/maniforge-manifest-engine.md'
        );
        break;
    case '/docs/maniforge-ui-strategy.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_UI_STRATEGY.md',
            'Стратегия UI Maniforge',
            '/docs/maniforge-ui-strategy.md'
        );
        break;
    case '/docs/maniforge-go-migration.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_GO_MIGRATION.md',
            'Миграция на Go',
            '/docs/maniforge-go-migration.md'
        );
        break;
    case '/docs/maniforge-principles.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_PRINCIPLES.md',
            'Принципы Maniforge',
            '/docs/maniforge-principles.md'
        );
        break;
    case '/docs/maniforge-security-incident-workflow.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_SECURITY_INCIDENT_WORKFLOW.md',
            'Инциденты безопасности',
            '/docs/maniforge-security-incident-workflow.md'
        );
        break;
    case '/docs/maniforge-rbac-checkpoint-summary.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_RBAC_CHECKPOINT_SUMMARY.md',
            'RBAC checkpoint',
            '/docs/maniforge-rbac-checkpoint-summary.md'
        );
        break;
    case '/docs/maniforge-agency-model.md':
        header('Location: /docs/maniforge-tenant-delegation.md', true, 302);
        break;
    case '/docs/maniforge-tenant-licensing-openapi.yaml':
        $path = dirname(__DIR__) . '/docs/MANIFORGE_TENANT_LICENSING_OPENAPI.yaml';
        if (is_file($path)) {
            header('Content-Type: application/yaml; charset=utf-8');
            readfile($path);
            break;
        }
        http_response_code(404);
        require dirname(__DIR__) . '/templates/404.php';
        break;
    case '/docs/152FZ_COMPLIANCE.md':
    case '/docs/152fz-compliance.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/152FZ_COMPLIANCE.md',
            '152-ФЗ — персональные данные',
            '/docs/152FZ_COMPLIANCE.md'
        );
        break;
    case '/docs/maniforge-pd-processor-platform.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/MANIFORGE_PD_PROCESSOR_PLATFORM.md',
            'Платформа-обработчик ПДн',
            '/docs/maniforge-pd-processor-platform.md'
        );
        break;
    case '/docs/legal/roskomnadzor-notification.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/legal/ROSKOMNADZOR_NOTIFICATION_LETTER.md',
            'Уведомление Роскомнадзору',
            '/docs/legal/roskomnadzor-notification.md'
        );
        break;
    case '/docs/legal/readme.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/legal/README.md',
            'Юридические шаблоны',
            '/docs/legal/readme.md'
        );
        break;
    case '/docs/legal/privacy-policy-outline.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/legal/PRIVACY_POLICY_OUTLINE.md',
            'Политика конфиденциальности',
            '/docs/legal/privacy-policy-outline.md'
        );
        break;
    case '/docs/legal/dpo-appointment-order.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/legal/DPO_APPOINTMENT_ORDER.md',
            'Приказ DPO',
            '/docs/legal/dpo-appointment-order.md'
        );
        break;
    case '/docs/legal/processing-registry-template.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/legal/PROCESSING_REGISTRY_TEMPLATE.md',
            'Реестр обработки ПДн',
            '/docs/legal/processing-registry-template.md'
        );
        break;
    case '/docs/legal/data-processing-agreement-outline.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/legal/DATA_PROCESSING_AGREEMENT_OUTLINE.md',
            'Договор поручения обработки',
            '/docs/legal/data-processing-agreement-outline.md'
        );
        break;
    case '/docs/legal/subject-request-response-template.md':
        MarkdownDocPage::serve(
            dirname(__DIR__) . '/docs/legal/SUBJECT_REQUEST_RESPONSE_TEMPLATE.md',
            'Ответ субъекту ПДн',
            '/docs/legal/subject-request-response-template.md'
        );
        break;
    default:
        if (serveSpaAt($requestUri, '/app', 'app') || serveSpaAt($requestUri, '/scanner', 'scanner')) {
            break;
        }
        http_response_code(404);
        require dirname(__DIR__) . '/templates/404.php';
        break;
}

/**
 * React SPA (admin: public/app/, scanner: public/scanner/).
 *
 * @return bool true when SPA asset or index.html was served
 */
function serveSpaPathMatches(string $requestUri, string $prefix): bool
{
    return $requestUri === $prefix || str_starts_with($requestUri, $prefix . '/');
}

function serveSpaAt(string $requestUri, string $prefix, string $dirName): bool
{
    if (!serveSpaPathMatches($requestUri, $prefix)) {
        return false;
    }

    $publicRoot = realpath(__DIR__);
    if ($publicRoot === false) {
        return false;
    }

    $appRoot = $publicRoot . '/' . $dirName;
    $assetsPrefix = $prefix . '/assets/';
    if (str_starts_with($requestUri, $assetsPrefix)) {
        $candidate = realpath($appRoot . substr($requestUri, strlen($prefix)));
        if ($candidate === false || !is_file($candidate) || !str_starts_with($candidate, $appRoot . DIRECTORY_SEPARATOR)) {
            return false;
        }
        $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        $contentTypes = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
        ];
        header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
        readfile($candidate);

        return true;
    }

    $relative = substr($requestUri, strlen($prefix));
    if ($relative !== '' && $relative !== '/') {
        $static = realpath($appRoot . $relative);
        if ($static !== false && is_file($static) && str_starts_with($static, $appRoot . DIRECTORY_SEPARATOR)) {
            $extension = strtolower(pathinfo($static, PATHINFO_EXTENSION));
            $contentTypes = [
                'webmanifest' => 'application/manifest+json; charset=utf-8',
                'json' => 'application/json; charset=utf-8',
                'ico' => 'image/x-icon',
            ];
            header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
            readfile($static);

            return true;
        }
    }

    $index = $appRoot . '/index.html';
    if (!is_file($index)) {
        return false;
    }

    header('Content-Type: text/html; charset=utf-8');
    readfile($index);

    return true;
}

/**
 * @return bool true when a static file was served
 */
function servePublicAsset(string $requestUri): bool
{
    if (!preg_match('#^/(assets/|robots\.txt)#', $requestUri)) {
        return false;
    }

    $publicRoot = realpath(__DIR__);
    if ($publicRoot === false) {
        return false;
    }

    $candidate = realpath($publicRoot . $requestUri);
    if ($candidate === false || !is_file($candidate) || !str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)) {
        return false;
    }

    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $contentTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
    ];

    header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
    readfile($candidate);

    return true;
}
