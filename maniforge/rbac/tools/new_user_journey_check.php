<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\EntityMetaRepository;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\ContextService;
use App\Maniforge\Rbac\Security\EntityMetaTypes;
use App\Maniforge\Rbac\Security\ProjectService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Rbac\Security\SessionService;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;
use App\Maniforge\Versioning\Repository\VersioningRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function printNewUserUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/new_user_journey_check.php [--help]\n");
    fwrite(STDOUT, "Проверяет полный путь нового пользователя: регистрация → login → проект → invite → versioning.\n");
}

if (in_array('--help', $argv, true)) {
    printNewUserUsage();
    exit(0);
}

final class JourneyAsserts
{
    private int $passed = 0;
    private int $failed = 0;

    public function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            fwrite(STDOUT, "[OK] {$message}\n");
            return;
        }

        $this->failed++;
        fwrite(STDERR, "[FAIL] {$message}\n");
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertTrue(
            $expected === $actual,
            $message . ' (expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true) . ')'
        );
    }

    public function summary(): void
    {
        fwrite(STDOUT, "\nNew user journey passed: {$this->passed}\n");
        fwrite(STDOUT, "New user journey failed: {$this->failed}\n");
    }

    public function hasFailed(): bool
    {
        return $this->failed > 0;
    }
}

function cleanupNewUserTenant(string $tenantId): void
{
    $pdo = Connection::get();
    $tables = [
        'DELETE FROM maniforge_ver_changes WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_user_project_memberships WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_scope_variables WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_projects WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_registration_invites WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_user_roles WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_sessions WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_refresh_tokens WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_audit_log WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_security_events WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_tenant_access_cache WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_quota_usage WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_events WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_audit_log WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_tenants WHERE code = :tenant_id',
        'DELETE FROM maniforge_entity_meta WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_users WHERE tenant_id = :tenant_id',
    ];
    foreach ($tables as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':tenant_id' => $tenantId]);
        } catch (Throwable) {
        }
    }
}

$assert = new JourneyAsserts();
$suffix = substr(bin2hex(random_bytes(6)), 0, 8);
$adminLogin = 'nu_admin_' . $suffix;
$memberLogin = 'nu_member_' . $suffix;
$password = 'NewUserJourney!123';
$phoneAdmin = '+7900' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
$phoneMember = '+7901' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
$emailAdmin = $adminLogin . '@example.test';
$emailMember = $memberLogin . '@example.test';
$tenantId = '';
$projectCode = 'proj_' . $suffix;
$registrationConsents = [['purpose_code' => 'account', 'policy_version' => '1.0']];

try {
    Connection::get();
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$registration = new RegistrationService();
$auth = new AuthService();
$sessions = new SessionService();
$projects = new ProjectService();
$licensing = new TenantLicensingRepository();
$versioning = new VersioningRepository();
$audit = new AuditLogRepository();
$entityMeta = new EntityMetaRepository();
$contexts = new ContextService();

try {
    $assert->assertTrue($registration->isEnabled(), 'Self-registration is enabled');

    $registerResult = $registration->register([
        'password' => $password,
        'phone' => $phoneAdmin,
        'email' => $emailAdmin,
        'organization_name' => 'NU Journey Org ' . $suffix,
        'consents' => $registrationConsents,
    ]);
    $assert->assertTrue((bool) ($registerResult['ok'] ?? false), 'New tenant registration succeeds');
    $assert->assertTrue((int) ($registerResult['status'] ?? 0) === 201, 'Registration returns status 201');
    $expectedAdminLogin = 'u' . preg_replace('/\D+/', '', $phoneAdmin);
    $assert->assertSame(
        $expectedAdminLogin,
        (string) ($registerResult['user']['login'] ?? ''),
        'Login auto-generated from phone'
    );

    $tenantId = (string) ($registerResult['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($registerResult['tenant']['subtenant_id'] ?? 'main');
    $adminUserId = (int) ($registerResult['user']['id'] ?? 0);
    $assert->assertTrue($tenantId !== '', 'Tenant id returned after registration');
    $assert->assertTrue($adminUserId > 0, 'Admin user id returned after registration');
    $assert->assertSame('tenant_admin', (string) ($registerResult['role_code'] ?? ''), 'Bootstrap role is tenant_admin');

    $globalPhoneUserId = $entityMeta->findGlobalPhoneUserId($phoneAdmin);
    $assert->assertSame($adminUserId, $globalPhoneUserId, 'Global entity_meta binds phone to admin user');
    $scopedPhone = $entityMeta->findInScope(
        EntityMetaTypes::TYPE_PHONE,
        $phoneAdmin,
        EntityMetaTypes::I_USER,
        $tenantId,
        $subtenantId,
    );
    $assert->assertTrue(is_array($scopedPhone), 'Tenant-scoped entity_meta row exists for admin phone');
    $assert->assertSame($adminUserId, (int) ($scopedPhone['i_id'] ?? 0), 'Scoped entity_meta points to admin user');

    $duplicatePhoneRegister = $registration->register([
        'password' => $password,
        'phone' => $phoneAdmin,
        'email' => 'dup_' . $emailAdmin,
        'organization_name' => 'Duplicate Org ' . $suffix,
        'consents' => $registrationConsents,
    ]);
    $assert->assertTrue(($duplicatePhoneRegister['ok'] ?? true) === false, 'Duplicate phone self-registration blocked');
    $assert->assertSame(409, (int) ($duplicatePhoneRegister['status'] ?? 0), 'Duplicate phone returns 409');
    $assert->assertSame(
        'phone_already_registered',
        (string) ($duplicatePhoneRegister['code'] ?? ''),
        'Duplicate phone error code'
    );

    $accessState = $licensing->accessState($tenantId, $subtenantId);
    $assert->assertTrue((bool) ($accessState['tenant_active'] ?? false), 'Tenant is active in licensing');
    $assert->assertTrue((bool) ($accessState['license_active'] ?? false), 'License is active after registration');

    $loginOnly = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['login' => $expectedAdminLogin, 'password' => $password],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    $assert->assertTrue(($loginOnly['ok'] ?? true) === false, 'Login by internal login is rejected');
    $assert->assertSame(422, (int) ($loginOnly['status'] ?? 0), 'Login-only returns 422');

    $badPassword = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phoneAdmin, 'password' => 'WrongPassword!000'],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    $assert->assertTrue(($badPassword['ok'] ?? true) === false, 'Wrong password rejected');
    $assert->assertSame(401, (int) ($badPassword['status'] ?? 0), 'Wrong password returns 401');

    $loginResult = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phoneAdmin, 'password' => $password],
        ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'new_user_journey_check']
    );
    $assert->assertTrue((bool) ($loginResult['ok'] ?? false), 'Phone login after registration');
    $session = $loginResult['session'] ?? $loginResult['credentials']['session'] ?? [];
    $assert->assertTrue((string) ($session['access_token'] ?? '') !== '', 'Access token issued on login');

    $meSession = $sessions->authenticate((string) $session['access_token']);
    $assert->assertTrue(is_array($meSession), 'Session token is valid');
    $assert->assertSame($tenantId, (string) ($meSession['tenant_id'] ?? ''), 'Session tenant matches registration');
    $assert->assertSame($subtenantId, (string) ($meSession['subtenant_id'] ?? ''), 'Session subtenant matches registration');

    $ctx = $contexts->contextsForSession($meSession);
    $assert->assertTrue((bool) ($ctx['ok'] ?? false), 'Contexts for session');
    $orgTenantIds = array_map(
        static fn (array $row): string => (string) ($row['tenant_id'] ?? ''),
        $ctx['organizations'] ?? $ctx['home'] ?? []
    );
    $assert->assertTrue(in_array($tenantId, $orgTenantIds, true), 'Organizations include registered tenant');

    $projectResult = $projects->createProject($meSession, [
        'code' => $projectCode,
        'name' => 'Journey Project',
        'metadata' => ['source' => 'new_user_journey_check'],
    ]);
    $assert->assertTrue((bool) ($projectResult['ok'] ?? false), 'Tenant admin creates project');
    $assert->assertSame($projectCode, (string) ($projectResult['project']['code'] ?? ''), 'Project code persisted');

    $listProjects = $projects->listProjects($meSession);
    $assert->assertTrue((bool) ($listProjects['ok'] ?? false), 'List projects succeeds');
    $projectCodes = array_map(
        static fn (array $item): string => (string) ($item['code'] ?? ''),
        $listProjects['items'] ?? []
    );
    $assert->assertTrue(in_array($projectCode, $projectCodes, true), 'Created project visible in list');

    $variableResult = $projects->createGlobalVariable($meSession, [
        'key' => 'env',
        'value' => 'journey-' . $suffix,
        'scope_level' => 'subtenant',
    ]);
    $assert->assertTrue((bool) ($variableResult['ok'] ?? false), 'Create global variable succeeds');

    $inviteResult = $registration->createUserInvite($tenantId, $subtenantId, $adminUserId, 'user');
    $assert->assertTrue((bool) ($inviteResult['ok'] ?? false), 'Tenant admin creates user invite');
    $inviteToken = (string) ($inviteResult['invite_token'] ?? '');
    $assert->assertTrue($inviteToken !== '', 'Invite token issued');

    $memberRegister = $registration->register([
        'password' => $password,
        'phone' => $phoneMember,
        'email' => $emailMember,
        'invite_token' => $inviteToken,
        'consents' => $registrationConsents,
    ]);
    $assert->assertTrue((bool) ($memberRegister['ok'] ?? false), 'Member registers via invite');
    $assert->assertSame(
        'u' . preg_replace('/\D+/', '', $phoneMember),
        (string) ($memberRegister['user']['login'] ?? ''),
        'Invited member login auto-generated from phone'
    );
    $assert->assertSame('user', (string) ($memberRegister['role_code'] ?? ''), 'Invited user gets user role');
    $memberUserId = (int) ($memberRegister['user']['id'] ?? 0);
    $assert->assertSame($memberUserId, $entityMeta->findGlobalPhoneUserId($phoneMember), 'Member global entity_meta bound');
    $assert->assertTrue(
        $entityMeta->findGlobalPhoneUserId($phoneAdmin) === $adminUserId,
        'Admin global phone unchanged after member invite'
    );

    $duplicateInvite = $registration->register([
        'password' => $password,
        'phone' => '+7902' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        'invite_token' => $inviteToken,
        'consents' => $registrationConsents,
    ]);
    $assert->assertTrue(($duplicateInvite['ok'] ?? true) === false, 'Consumed invite cannot be reused');
    $assert->assertSame(409, (int) ($duplicateInvite['status'] ?? 0), 'Consumed invite returns 409');
    $assert->assertSame(
        'invite_already_used',
        (string) ($duplicateInvite['code'] ?? ''),
        'Consumed invite error code'
    );

    $changes = $versioning->listInScope($tenantId, $subtenantId, [
        'entity_table' => 'maniforge_users',
        'limit' => 50,
        'offset' => 0,
    ]);
    $assert->assertTrue(count($changes) >= 2, 'Versioning records user inserts');

    $projectChanges = $versioning->listInScope($tenantId, $subtenantId, [
        'entity_table' => 'maniforge_projects',
        'limit' => 20,
        'offset' => 0,
    ]);
    $assert->assertTrue(count($projectChanges) >= 1, 'Versioning records project insert');

    $auditRows = $audit->listByScope($tenantId, $subtenantId, 50);
    $eventTypes = array_map(static fn (array $row): string => (string) ($row['event_type'] ?? ''), $auditRows);
    $assert->assertTrue(in_array('auth.register', $eventTypes, true), 'Audit contains auth.register');
    $assert->assertTrue(in_array('auth.login.success', $eventTypes, true), 'Audit contains auth.login.success');

    $manualJoin = $registration->register([
        'password' => $password,
        'phone' => '+7903' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        'tenant_id' => $tenantId,
        'subtenant_id' => $subtenantId,
        'consents' => $registrationConsents,
    ]);
    $assert->assertTrue(($manualJoin['ok'] ?? true) === false, 'Manual scope join without invite is blocked');
    $assert->assertTrue((int) ($manualJoin['status'] ?? 0) === 403, 'Manual scope join returns 403');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in new user journey check');
} finally {
    if ($tenantId !== '') {
        cleanupNewUserTenant($tenantId);
    }
    foreach ([$phoneAdmin, $phoneMember] as $phone) {
        if ($phone === '') {
            continue;
        }
        try {
            Connection::get()->prepare(
                'DELETE FROM maniforge_entity_meta WHERE type = :type AND meta = :meta'
            )->execute([
                ':type' => EntityMetaTypes::TYPE_PHONE,
                ':meta' => $phone,
            ]);
        } catch (Throwable) {
        }
    }
}

$assert->summary();
exit($assert->hasFailed() ? 1 : 0);
