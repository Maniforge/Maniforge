<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';
require_once __DIR__ . '/journey_http_common.php';

function securityIncidentUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/security_incident_journey.php [--help]\n");
    fwrite(STDOUT, "Env: JOURNEY_BASE_URL=http://127.0.0.1:8092/rbac\n");
}

if (in_array('--help', $argv, true)) {
    securityIncidentUsage();
    exit(0);
}

$rbacBase = rtrim(journeyEnv('JOURNEY_BASE_URL', journeyEnv('NEW_USER_BASE_URL', 'http://127.0.0.1:8093/rbac')), '/');
$suffix = substr(bin2hex(random_bytes(6)), 0, 8);
$adminLogin = 'sec_admin_' . $suffix;
$victimLogin = 'sec_user_' . $suffix;
$password = 'SecurityIncident!123';
$adminPhone = journeyRandomPhone('+7900');
$victimPhone = journeyRandomPhone('+7901');
$tenantId = '';
$subtenantId = 'main';

$assert = new JourneyHttpAsserts();
$cookies = [];

try {
    $register = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => $adminPhone,
        'email' => $adminLogin . '@example.test',
        'organization_name' => 'Security Incident Org ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($register, 201, 'Register tenant for security incident');
    $tenantId = (string) ($register['body']['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($register['body']['tenant']['subtenant_id'] ?? 'main');
    $assert->assertTrue($tenantId !== '', 'Tenant id received');

    $tenantHeaders = [
        'X-Tenant-ID: ' . $tenantId,
        'X-Subtenant-ID: ' . $subtenantId,
    ];

    $adminLoginResp = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $adminPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($adminLoginResp, 200, 'Admin login');
    [$adminToken, $authHeaders] = journeyAuthFromLogin($adminLoginResp['body'], $tenantHeaders);
    $sessionHeaders = array_merge($tenantHeaders, ['Authorization: Bearer ' . $adminToken]);
    [$actionToken, $actionHeaders] = journeyReauth($rbacBase, $authHeaders, $password, $cookies);
    $assert->assertTrue($actionToken !== '', 'Action token for admin mutations');

    $createUser = journeyHttp('POST', $rbacBase . '/api/v1/admin/users', $actionHeaders, [
        'login' => $victimLogin,
        'password' => $password,
        'phone' => $victimPhone,
        'email' => $victimLogin . '@example.test',
        'status' => 'active',
        'reason' => 'security_incident_journey',
    ], $cookies);
    $assert->assertTrue(in_array((int) $createUser['status'], [200, 201], true), 'Create victim user');
    $victimUserId = (int) ($createUser['body']['user']['id'] ?? 0);
    $assert->assertTrue($victimUserId > 0, 'Victim user id received');

    $assignRole = journeyHttp('POST', $rbacBase . '/api/v1/admin/user-roles/assign', $actionHeaders, [
        'user_id' => $victimUserId,
        'role_code' => 'user',
        'reason' => 'security_incident_journey',
    ], $cookies);
    $assert->assertStatus($assignRole, 200, 'Assign user role to victim');

    $victimLoginResp = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $victimPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($victimLoginResp, 200, 'Victim login before lock');
    [$victimToken] = journeyAuthFromLogin($victimLoginResp['body'], $tenantHeaders);
    $victimHeaders = array_merge($tenantHeaders, ['Authorization: Bearer ' . $victimToken]);
    $assert->assertTrue($victimToken !== '', 'Victim access token issued');

    $victimMe = journeyHttp('GET', $rbacBase . '/api/v1/me', $victimHeaders, null, $cookies);
    $assert->assertStatus($victimMe, 200, 'Victim session active before lock');

    $lock = journeyHttp('POST', $rbacBase . '/api/v1/admin/users/batch-status', $actionHeaders, [
        'reason' => 'security_incident_lock',
        'items' => [
            ['user_id' => $victimUserId, 'status' => 'locked'],
        ],
    ], $cookies);
    $assert->assertStatus($lock, 200, 'Batch-status lock victim');
    $assert->assertTrue((bool) ($lock['body']['ok'] ?? false), 'Batch-status ok=true');
    $assert->assertTrue((int) ($lock['body']['summary']['revoked_sessions'] ?? 0) >= 1, 'Lock revokes victim sessions');

    $victimMeAfter = journeyHttp('GET', $rbacBase . '/api/v1/me', $victimHeaders, null, $cookies);
    $assert->assertStatus($victimMeAfter, 401, 'Revoked victim token returns 401');

    $blockedLogin = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $victimPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($blockedLogin, 403, 'Locked user login denied');

    $securityEvents = journeyHttp('GET', $rbacBase . '/api/v1/admin/security-events', $sessionHeaders, null, $cookies);
    $assert->assertStatus($securityEvents, 200, 'GET security-events');
    $eventTypes = array_map(
        static fn (array $row): string => (string) ($row['event_type'] ?? ''),
        $securityEvents['body']['items'] ?? []
    );
    $assert->assertTrue(in_array('admin.users.batch_status.updated', $eventTypes, true), 'Security events contain batch_status');
    $assert->assertTrue(in_array('auth.login.blocked', $eventTypes, true), 'Security events contain login.blocked');

    $audit = journeyHttp('GET', $rbacBase . '/api/v1/admin/audit', $sessionHeaders, null, $cookies);
    $assert->assertStatus($audit, 200, 'GET admin audit');
    $auditTypes = array_map(
        static fn (array $row): string => (string) ($row['event_type'] ?? ''),
        $audit['body']['items'] ?? []
    );
    $assert->assertTrue(in_array('admin.users.batch_status', $auditTypes, true), 'Audit contains batch_status');

    $sessions = journeyHttp('GET', $rbacBase . '/api/v1/admin/sessions', $sessionHeaders, null, $cookies);
    $assert->assertStatus($sessions, 200, 'GET admin sessions after lock');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in security incident journey');
}

$assert->summary('Security incident journey');
if ($tenantId !== '') {
    fwrite(STDOUT, "Test tenant: {$tenantId}\n");
}

exit($assert->hasFailures() ? 1 : 0);
