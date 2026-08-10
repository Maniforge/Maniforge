<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';
require_once __DIR__ . '/journey_http_common.php';

function rbacAdminUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/rbac_admin_journey.php [--help]\n");
    fwrite(STDOUT, "Env: JOURNEY_BASE_URL=http://127.0.0.1:8092/rbac\n");
}

if (in_array('--help', $argv, true)) {
    rbacAdminUsage();
    exit(0);
}

$rbacBase = rtrim(journeyEnv('JOURNEY_BASE_URL', journeyEnv('NEW_USER_BASE_URL', 'http://127.0.0.1:8093/rbac')), '/');
$suffix = substr(bin2hex(random_bytes(6)), 0, 8);
$adminLogin = 'rbac_admin_' . $suffix;
$newUserLogin = 'rbac_user_' . $suffix;
$password = 'RbacAdminJourney!123';
$adminPhone = journeyRandomPhone('+7900');
$newUserPhone = journeyRandomPhone('+7901');
$tenantId = '';
$subtenantId = 'main';

$assert = new JourneyHttpAsserts();
$cookies = [];

try {
    $register = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => $adminPhone,
        'email' => $adminLogin . '@example.test',
        'organization_name' => 'RBAC Admin Org ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($register, 201, 'Register tenant admin');

    $tenantId = (string) ($register['body']['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($register['body']['tenant']['subtenant_id'] ?? 'main');
    $tenantHeaders = [
        'X-Tenant-ID: ' . $tenantId,
        'X-Subtenant-ID: ' . $subtenantId,
    ];

    $adminLoginResp = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $adminPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($adminLoginResp, 200, 'Tenant admin login');
    [$adminToken, $authHeaders] = journeyAuthFromLogin($adminLoginResp['body'], $tenantHeaders);
    $sessionHeaders = array_merge($tenantHeaders, ['Authorization: Bearer ' . $adminToken]);
    [, $actionHeaders] = journeyReauth($rbacBase, $authHeaders, $password, $cookies);

    $createUser = journeyHttp('POST', $rbacBase . '/api/v1/admin/users', $actionHeaders, [
        'login' => $newUserLogin,
        'password' => $password,
        'phone' => $newUserPhone,
        'email' => $newUserLogin . '@example.test',
        'status' => 'active',
        'reason' => 'rbac_admin_journey',
    ], $cookies);
    $assert->assertTrue(in_array((int) $createUser['status'], [200, 201], true), 'Admin creates user');
    $newUserId = (int) ($createUser['body']['user']['id'] ?? 0);
    $assert->assertTrue($newUserId > 0, 'New user id received');

    $assignRole = journeyHttp('POST', $rbacBase . '/api/v1/admin/user-roles/assign', $actionHeaders, [
        'user_id' => $newUserId,
        'role_code' => 'user',
        'reason' => 'rbac_admin_journey',
    ], $cookies);
    $assert->assertStatus($assignRole, 200, 'Assign user role');
    $assert->assertTrue((bool) ($assignRole['body']['ok'] ?? false), 'Role assign ok=true');

    $userRoles = journeyHttp(
        'GET',
        $rbacBase . '/api/v1/admin/user-roles?user_id=' . rawurlencode((string) $newUserId),
        $sessionHeaders,
        null,
        $cookies
    );
    $assert->assertStatus($userRoles, 200, 'GET user-roles');
    $roleCodes = array_map(
        static fn (array $row): string => (string) ($row['role_code'] ?? $row['code'] ?? ''),
        $userRoles['body']['items'] ?? []
    );
    $assert->assertTrue(in_array('user', $roleCodes, true), 'User role assigned');

    $effectiveAccess = journeyHttp(
        'GET',
        $rbacBase . '/api/v1/admin/effective-access?user_id=' . rawurlencode((string) $newUserId),
        $sessionHeaders,
        null,
        $cookies
    );
    $assert->assertStatus($effectiveAccess, 200, 'GET effective-access');
    $assert->assertTrue((bool) ($effectiveAccess['body']['ok'] ?? false), 'Effective-access ok=true');
    $permissions = $effectiveAccess['body']['access']['permissions'] ?? $effectiveAccess['body']['access'] ?? [];
    $assert->assertTrue(is_array($permissions) && $permissions !== [], 'Effective-access returns permissions');

    $policiesGet = journeyHttp('GET', $rbacBase . '/api/v1/admin/policies', $sessionHeaders, null, $cookies);
    $assert->assertStatus($policiesGet, 200, 'GET policies');
    $rules = $policiesGet['body']['rules'] ?? [];
    $allowedIps = is_array($rules['allowed_ips'] ?? null) ? $rules['allowed_ips'] : [];

    $policiesPost = journeyHttp('POST', $rbacBase . '/api/v1/admin/policies', $actionHeaders, [
        'reason' => 'rbac_admin_journey_noop',
        'allowed_ips' => $allowedIps,
        'allowed_hour_start_utc' => (int) ($rules['allowed_hour_start_utc'] ?? 0),
        'allowed_hour_end_utc' => (int) ($rules['allowed_hour_end_utc'] ?? 23),
        'require_step_up' => (bool) ($rules['require_step_up'] ?? true),
    ], $cookies);
    $assert->assertStatus($policiesPost, 200, 'POST policies noop');
    $assert->assertTrue((bool) ($policiesPost['body']['ok'] ?? false), 'Policies update ok=true');

    $opsSummary = journeyHttp('GET', $rbacBase . '/api/v1/admin/ops-summary', $sessionHeaders, null, $cookies);
    $assert->assertStatus($opsSummary, 200, 'GET ops-summary');
    $assert->assertTrue((int) ($opsSummary['body']['summary']['users_total'] ?? 0) >= 2, 'Ops summary shows users');

    $newUserLoginResp = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $newUserPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($newUserLoginResp, 200, 'New user can login after role assign');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in RBAC admin journey');
}

$assert->summary('RBAC admin journey');
if ($tenantId !== '') {
    fwrite(STDOUT, "Test tenant: {$tenantId}\n");
}

exit($assert->hasFailures() ? 1 : 0);
