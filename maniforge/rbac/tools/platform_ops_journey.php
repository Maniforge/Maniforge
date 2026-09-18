<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';
require_once __DIR__ . '/journey_http_common.php';

function platformOpsUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/platform_ops_journey.php [--help]\n");
    fwrite(STDOUT, "Env:\n");
    fwrite(STDOUT, "  JOURNEY_BASE_URL=http://127.0.0.1:8093/rbac\n");
    fwrite(STDOUT, "  JOURNEY_TL_URL=http://127.0.0.1:8094/tenant-licensing\n");
    fwrite(STDOUT, "  NEW_USER_BASE_URL=http://127.0.0.1:8093/rbac\n");
    fwrite(STDOUT, "  NEW_USER_TL_URL=http://127.0.0.1:8094/tenant-licensing\n");
    fwrite(STDOUT, "  JOURNEY_RBAC_SCHEME=http|https (default http)\n");
    fwrite(STDOUT, "  JOURNEY_RBAC_HOST=127.0.0.1 (default)\n");
    fwrite(STDOUT, "  JOURNEY_RBAC_PORT=8093 (default)\n");
    fwrite(STDOUT, "  JOURNEY_RBAC_PATH=/rbac (default)\n");
    fwrite(STDOUT, "  JOURNEY_TL_SCHEME=http|https (default http)\n");
    fwrite(STDOUT, "  JOURNEY_TL_HOST=127.0.0.1 (default)\n");
    fwrite(STDOUT, "  JOURNEY_TL_PORT=8094 (default)\n");
    fwrite(STDOUT, "  JOURNEY_TL_PATH=/tenant-licensing (default)\n");
}

function envTrim(string $name): string
{
    $value = getenv($name);
    if ($value === false) {
        $value = $_ENV[$name] ?? '';
    }
    return trim((string) $value);
}

/**
 * Возвращает base URL:
 * 1) явный *_URL (или legacy fallback), иначе
 * 2) сборка из scheme/host/port/path.
 */
function resolveBaseUrl(
    string $urlEnv,
    string $legacyUrlEnv,
    string $schemeEnv,
    string $hostEnv,
    string $portEnv,
    string $pathEnv,
    string $defaultUrl,
    string $defaultScheme,
    string $defaultHost,
    string $defaultPort,
    string $defaultPath
): array {
    $rawUrl = envTrim($urlEnv);
    if ($rawUrl === '' && $legacyUrlEnv !== '') {
        $rawUrl = envTrim($legacyUrlEnv);
    }
    if ($rawUrl !== '') {
        return [rtrim($rawUrl, '/'), false, 'url'];
    }

    $scheme = envTrim($schemeEnv);
    if ($scheme === '') {
        $scheme = $defaultScheme;
    }
    $host = envTrim($hostEnv);
    if ($host === '') {
        $host = $defaultHost;
    }
    $port = envTrim($portEnv);
    if ($port === '') {
        $port = $defaultPort;
    }
    $path = envTrim($pathEnv);
    if ($path === '') {
        $path = $defaultPath;
    }
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }

    $built = sprintf('%s://%s:%s%s', $scheme, $host, $port, $path);
    $usedDefaults = ($scheme === $defaultScheme && $host === $defaultHost && $port === $defaultPort && $path === $defaultPath);
    if ($usedDefaults) {
        $built = $defaultUrl;
    }
    return [rtrim($built, '/'), $usedDefaults, 'parts'];
}

if (in_array('--help', $argv, true)) {
    platformOpsUsage();
    exit(0);
}

[$rbacBase, $rbacDefault, $rbacSource] = resolveBaseUrl(
    'JOURNEY_BASE_URL',
    'NEW_USER_BASE_URL',
    'JOURNEY_RBAC_SCHEME',
    'JOURNEY_RBAC_HOST',
    'JOURNEY_RBAC_PORT',
    'JOURNEY_RBAC_PATH',
    'http://127.0.0.1:8093/rbac',
    'http',
    '127.0.0.1',
    '8093',
    '/rbac'
);
[$tlBase, $tlDefault, $tlSource] = resolveBaseUrl(
    'JOURNEY_TL_URL',
    'NEW_USER_TL_URL',
    'JOURNEY_TL_SCHEME',
    'JOURNEY_TL_HOST',
    'JOURNEY_TL_PORT',
    'JOURNEY_TL_PATH',
    'http://127.0.0.1:8094/tenant-licensing',
    'http',
    '127.0.0.1',
    '8094',
    '/tenant-licensing'
);
fwrite(STDOUT, "[INFO] RBAC base: {$rbacBase} (source={$rbacSource}" . ($rbacDefault ? ', defaults' : '') . ")\n");
fwrite(STDOUT, "[INFO] TL base: {$tlBase} (source={$tlSource}" . ($tlDefault ? ', defaults' : '') . ")\n");
$tlAdminToken = trim((string) (getenv('TL_SMOKE_ADMIN_TOKEN') ?: getenv('TENANT_LICENSING_ADMIN_TOKEN') ?: ($_ENV['TL_SMOKE_ADMIN_TOKEN'] ?? $_ENV['TENANT_LICENSING_ADMIN_TOKEN'] ?? '')));
$tlInternalToken = trim((string) (getenv('TL_SMOKE_INTERNAL_TOKEN') ?: getenv('TENANT_LICENSING_INTERNAL_TOKEN') ?: ($_ENV['TL_SMOKE_INTERNAL_TOKEN'] ?? $_ENV['TENANT_LICENSING_INTERNAL_TOKEN'] ?? '')));
$rbacInternalToken = trim((string) (getenv('RBAC_INTERNAL_TOKEN') ?: ($_ENV['RBAC_INTERNAL_TOKEN'] ?? '')));
if ($rbacInternalToken === '') {
    $rbacInternalToken = trim((string) (getenv('TENANT_LICENSING_INTERNAL_TOKEN') ?: ($_ENV['TENANT_LICENSING_INTERNAL_TOKEN'] ?? '')));
}
$tlAdminHeaders = $tlAdminToken !== '' ? ['Authorization: Bearer ' . $tlAdminToken] : [];
$tlInternalHeaders = $tlInternalToken !== '' ? ['Authorization: Bearer ' . $tlInternalToken] : [];
$rbacInternalHeaders = $rbacInternalToken !== '' ? ['Authorization: Bearer ' . $rbacInternalToken] : [];

$suffix = substr(bin2hex(random_bytes(6)), 0, 8);
$adminLogin = 'ops_admin_' . $suffix;
$password = 'PlatformOpsJourney!123';
$adminPhone = journeyRandomPhone('+7900');
$tenantId = '';
$subtenantId = 'main';

$assert = new JourneyHttpAsserts();
$cookies = [];

try {
    $register = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => $adminPhone,
        'email' => $adminLogin . '@example.test',
        'organization_name' => 'Platform Ops Org ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($register, 201, 'Register tenant for platform ops');
    $tenantId = (string) ($register['body']['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($register['body']['tenant']['subtenant_id'] ?? 'main');
    $assert->assertTrue($tenantId !== '', 'Tenant id received');

    $tenantHeaders = [
        'X-Tenant-ID: ' . $tenantId,
        'X-Subtenant-ID: ' . $subtenantId,
    ];

    $loginOk = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $adminPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($loginOk, 200, 'Login while tenant active');

    $accessActive = journeyHttp(
        'GET',
        $tlBase . '/internal/v1/tenants/' . rawurlencode($tenantId) . '/subtenants/' . rawurlencode($subtenantId) . '/access-state',
        $tlInternalHeaders,
        null,
        $cookies
    );
    $assert->assertStatus($accessActive, 200, 'Access-state active');
    $assert->assertTrue((bool) ($accessActive['body']['license_active'] ?? false), 'License active');

    $suspendSub = journeyHttp(
        'PATCH',
        $tlBase . '/api/v1/tenants/' . rawurlencode($tenantId) . '/subtenants/' . rawurlencode($subtenantId),
        $tlAdminHeaders,
        ['name' => 'Main', 'status' => 'suspended'],
        $cookies
    );
    $assert->assertStatus($suspendSub, 200, 'Suspend subtenant via TL API');
    $assert->assertTrue((bool) ($suspendSub['body']['ok'] ?? false), 'Suspend subtenant ok=true');

    $loginSubSuspended = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $adminPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($loginSubSuspended, 403, 'Login denied when subtenant suspended');

    $accessSubSuspended = journeyHttp(
        'GET',
        $tlBase . '/internal/v1/tenants/' . rawurlencode($tenantId) . '/subtenants/' . rawurlencode($subtenantId) . '/access-state',
        $tlInternalHeaders,
        null,
        $cookies
    );
    $assert->assertTrue((bool) ($accessSubSuspended['body']['ok'] ?? false), 'Access-state returns for suspended subtenant');
    $assert->assertTrue((bool) ($accessSubSuspended['body']['subtenant_active'] ?? true) === false, 'Subtenant inactive in access-state');

    $reactivateSub = journeyHttp(
        'PATCH',
        $tlBase . '/api/v1/tenants/' . rawurlencode($tenantId) . '/subtenants/' . rawurlencode($subtenantId),
        $tlAdminHeaders,
        ['name' => 'Main', 'status' => 'active'],
        $cookies
    );
    $assert->assertStatus($reactivateSub, 200, 'Reactivate subtenant');

    $suspendTenant = journeyHttp(
        'PATCH',
        $tlBase . '/api/v1/tenants/' . rawurlencode($tenantId),
        $tlAdminHeaders,
        ['name' => 'Platform Ops Org ' . $suffix, 'status' => 'suspended'],
        $cookies
    );
    $assert->assertStatus($suspendTenant, 200, 'Suspend tenant via TL API');

    $loginTenantSuspended = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $adminPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($loginTenantSuspended, 403, 'Login denied when tenant suspended');

    $events = journeyHttp(
        'GET',
        $tlBase . '/api/v1/events?tenant_code=' . rawurlencode($tenantId) . '&limit=20',
        $tlAdminHeaders,
        null,
        $cookies
    );
    $assert->assertStatus($events, 200, 'List TL lifecycle events');
    $assert->assertTrue(count($events['body']['items'] ?? []) >= 1, 'Lifecycle events recorded');

    $tenantEvent = journeyHttp('POST', $rbacBase . '/internal/v1/tenant-events', $rbacInternalHeaders, [
        'event_type' => 'tenant.suspended',
        'tenant_code' => $tenantId,
        'subtenant_code' => $subtenantId,
        'payload' => ['source' => 'platform_ops_journey'],
    ], $cookies);
    $assert->assertStatus($tenantEvent, 200, 'POST internal tenant-events');
    $assert->assertTrue((bool) ($tenantEvent['body']['ok'] ?? false), 'Tenant event processed');

    $reactivateTenant = journeyHttp(
        'PATCH',
        $tlBase . '/api/v1/tenants/' . rawurlencode($tenantId),
        $tlAdminHeaders,
        ['name' => 'Platform Ops Org ' . $suffix, 'status' => 'active'],
        $cookies
    );
    $assert->assertStatus($reactivateTenant, 200, 'Reactivate tenant for cleanup');

    $loginRestored = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $adminPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($loginRestored, 200, 'Login works after tenant reactivated');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in platform ops journey');
}

$assert->summary('Platform ops journey');
if ($tenantId !== '') {
    fwrite(STDOUT, "Test tenant: {$tenantId}\n");
}

exit($assert->hasFailures() ? 1 : 0);
