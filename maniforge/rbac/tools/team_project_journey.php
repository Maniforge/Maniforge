<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';
require_once __DIR__ . '/journey_http_common.php';

function teamProjectUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/team_project_journey.php [--help]\n");
    fwrite(STDOUT, "Env: JOURNEY_BASE_URL=http://127.0.0.1:8092/rbac\n");
}

if (in_array('--help', $argv, true)) {
    teamProjectUsage();
    exit(0);
}

$rbacBase = rtrim(journeyEnv('JOURNEY_BASE_URL', journeyEnv('NEW_USER_BASE_URL', 'http://127.0.0.1:8092/rbac')), '/');
$suffix = substr(bin2hex(random_bytes(6)), 0, 8);
$adminLogin = 'team_admin_' . $suffix;
$memberLogin = 'team_member_' . $suffix;
$password = 'TeamProjectJourney!123';
$adminPhone = journeyRandomPhone('+7900');
$memberPhone = journeyRandomPhone('+7901');
$projectCode = 'team_proj_' . $suffix;
$tenantId = '';
$subtenantId = 'main';

$assert = new JourneyHttpAsserts();
$cookies = [];

try {
    $register = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => $adminPhone,
        'email' => $adminLogin . '@example.test',
        'organization_name' => 'Team Project Org ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($register, 201, 'Register tenant');
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
    $assert->assertStatus($adminLoginResp, 200, 'Admin login');
    [$adminToken, $authHeaders] = journeyAuthFromLogin($adminLoginResp['body'], $tenantHeaders);
    $sessionHeaders = array_merge($tenantHeaders, ['Authorization: Bearer ' . $adminToken]);
    [, $actionHeaders] = journeyReauth($rbacBase, $authHeaders, $password, $cookies);

    $createProject = journeyHttp('POST', $rbacBase . '/api/v1/projects', $actionHeaders, [
        'code' => $projectCode,
        'name' => 'Team Journey Project',
        'metadata' => ['source' => 'team_project_journey'],
    ], $cookies);
    $assert->assert2xx($createProject, 'Create project');
    $projectId = (int) ($createProject['body']['project']['id'] ?? 0);
    $assert->assertTrue($projectId > 0, 'Project id received');

    $subtenantVar = journeyHttp('POST', $rbacBase . '/api/v1/global-variables', $actionHeaders, [
        'key' => 'team_stage',
        'value' => 'subtenant-' . $suffix,
        'scope_level' => 'subtenant',
    ], $cookies);
    $assert->assert2xx($subtenantVar, 'Create subtenant global variable');

    $createMember = journeyHttp('POST', $rbacBase . '/api/v1/admin/users', $actionHeaders, [
        'login' => $memberLogin,
        'password' => $password,
        'phone' => $memberPhone,
        'email' => $memberLogin . '@example.test',
        'status' => 'active',
        'reason' => 'team_project_journey',
    ], $cookies);
    $assert->assertTrue(in_array((int) $createMember['status'], [200, 201], true), 'Create team member');
    $memberUserId = (int) ($createMember['body']['user']['id'] ?? 0);
    $assert->assertTrue($memberUserId > 0, 'Member user id received');

    $assignRole = journeyHttp('POST', $rbacBase . '/api/v1/admin/user-roles/assign', $actionHeaders, [
        'user_id' => $memberUserId,
        'role_code' => 'user',
        'reason' => 'team_project_journey',
    ], $cookies);
    $assert->assertStatus($assignRole, 200, 'Assign user role to member');

    $membership = journeyHttp('POST', $rbacBase . '/api/v1/projects/memberships', $actionHeaders, [
        'user_id' => $memberUserId,
        'project_code' => $projectCode,
    ], $cookies);
    $assert->assertStatus($membership, 200, 'Assign project membership');
    $assert->assertTrue((bool) ($membership['body']['ok'] ?? false), 'Membership ok=true');

    $memberLoginResp = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $memberPhone,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($memberLoginResp, 200, 'Member login');
    [$memberToken, $memberAuthHeaders] = journeyAuthFromLogin($memberLoginResp['body'], $tenantHeaders);
    $memberSessionHeaders = array_merge($tenantHeaders, ['Authorization: Bearer ' . $memberToken]);

    $memberProjects = journeyHttp('GET', $rbacBase . '/api/v1/projects', $memberSessionHeaders, null, $cookies);
    $assert->assertStatus($memberProjects, 200, 'Member lists assigned projects');
    $codes = array_map(
        static fn (array $item): string => (string) ($item['code'] ?? ''),
        $memberProjects['body']['items'] ?? []
    );
    $assert->assertTrue(in_array($projectCode, $codes, true), 'Member sees assigned project');

    $switchProject = journeyHttp('POST', $rbacBase . '/api/v1/auth/switch-project', $memberAuthHeaders, [
        'project_id' => $projectId,
    ], $cookies);
    $assert->assertStatus($switchProject, 200, 'Member switch-project');
    $assert->assertSame($projectId, (int) ($switchProject['body']['session']['project_id'] ?? 0), 'Switch-project sets project_id');

    $memberMe = journeyHttp('GET', $rbacBase . '/api/v1/me', $memberSessionHeaders, null, $cookies);
    $assert->assertStatus($memberMe, 200, 'GET /me with project context');
    $assert->assertSame($projectId, (int) ($memberMe['body']['session']['project_id'] ?? 0), 'Session carries project_id');

    $adminSwitchProject = journeyHttp('POST', $rbacBase . '/api/v1/auth/switch-project', $authHeaders, [
        'project_id' => $projectId,
    ], $cookies);
    $assert->assertStatus($adminSwitchProject, 200, 'Admin switch-project for variable setup');

    $projectVar = journeyHttp('POST', $rbacBase . '/api/v1/global-variables', $actionHeaders, [
        'key' => 'api_env',
        'value' => 'project-' . $suffix,
        'scope_level' => 'project',
        'project_code' => $projectCode,
    ], $cookies);
    $assert->assert2xx($projectVar, 'Create project-scoped global variable');

    $variables = journeyHttp('GET', $rbacBase . '/api/v1/global-variables', $memberSessionHeaders, null, $cookies);
    $assert->assertStatus($variables, 200, 'List global variables in project context');
    $keys = array_map(
        static fn (array $item): string => (string) ($item['key'] ?? ''),
        $variables['body']['items'] ?? []
    );
    $assert->assertTrue(in_array('api_env', $keys, true), 'Project variable visible in context');
    $assert->assertTrue(in_array('team_stage', $keys, true), 'Subtenant variable visible in project context');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in team project journey');
}

$assert->summary('Team project journey');
if ($tenantId !== '') {
    fwrite(STDOUT, "Test tenant: {$tenantId}, project: {$projectCode}\n");
}

exit($assert->hasFailures() ? 1 : 0);
