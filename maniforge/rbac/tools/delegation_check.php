<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\SessionRepository;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\ContextService;
use App\Maniforge\Rbac\Security\DelegatedAccessPolicy;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

final class DelegationAsserts
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
            $message . " (expected=" . var_export($expected, true) . ", actual=" . var_export($actual, true) . ")"
        );
    }

    public function summary(): void
    {
        fwrite(STDOUT, "\nDelegation checks passed: {$this->passed}\n");
        fwrite(STDOUT, "Delegation checks failed: {$this->failed}\n");
    }

    public function hasFailed(): bool
    {
        return $this->failed > 0;
    }
}

$assert = new DelegationAsserts();
$principalCode = 'agency-demo';
$managedCode = 'client-demo';
$principalSub = 'main';
$managedSub = 'main';
$adminLogin = 'agency-admin';
$adminPhone = '+79000000003';
$adminPassword = trim((string) ($_ENV['MANIFORGE_DEMO_ADMIN_PASSWORD'] ?? ''));
if ($adminPassword === '') {
    $adminPassword = 'DemoAdmin!12345';
}
$actor = 'delegation_check';

$licensing = new TenantLicensingRepository();
$contexts = new ContextService();
$sessions = new SessionRepository();
$auth = new AuthService();

try {
    Connection::get()->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "DB unavailable: " . $e->getMessage() . "\n");
    exit(2);
}

$tenantStmt = Connection::get()->prepare('SELECT code FROM maniforge_tl_tenants WHERE code = :code LIMIT 1');
$tenantStmt->execute([':code' => $principalCode]);
if (!is_array($tenantStmt->fetch())) {
    fwrite(STDERR, "Missing {$principalCode}. Run: php maniforge/rbac/tools/demo_seed.php\n");
    exit(2);
}

$grant = $licensing->createManagedTenantGrant(
    $principalCode,
    $managedCode,
    'operator',
    $actor,
    ['source' => 'delegation_check']
);
$assert->assertTrue(
    ($grant['ok'] ?? false) === true || (int) ($grant['status'] ?? 0) === 409,
    'createManagedTenantGrant succeeds or already exists'
);

$items = $licensing->listManagedTenants($principalCode, true);
$found = false;
foreach ($items as $row) {
    if (is_array($row) && (string) ($row['managed_tenant_code'] ?? '') === $managedCode) {
        $found = true;
        $assert->assertSame('active', (string) ($row['status'] ?? ''), 'managed grant is active');
        break;
    }
}
$assert->assertTrue($found, 'listManagedTenants contains client-demo');

$phoneStmt = Connection::get()->prepare(
    'SELECT phone FROM maniforge_users
     WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id AND login = :login
     LIMIT 1'
);
$phoneStmt->execute([
    ':tenant_id' => $principalCode,
    ':subtenant_id' => $principalSub,
    ':login' => $adminLogin,
]);
$phoneRow = $phoneStmt->fetch();
if (is_array($phoneRow) && trim((string) ($phoneRow['phone'] ?? '')) !== '') {
    $adminPhone = trim((string) $phoneRow['phone']);
}

$login = $auth->login(
    ['tenant_id' => $principalCode, 'subtenant_id' => $principalSub],
    ['phone' => $adminPhone, 'password' => $adminPassword],
    ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'delegation_check']
);
$assert->assertTrue(($login['ok'] ?? false) === true, 'agency-admin login by phone');
if (($login['ok'] ?? false) !== true) {
    $assert->summary();
    exit($assert->hasFailed() ? 1 : 0);
}

$loginSession = $login['session'] ?? $login['credentials']['session'] ?? [];
$accessToken = (string) ($loginSession['access_token'] ?? '');
$session = $sessions->findActiveByToken($accessToken);
$assert->assertTrue(is_array($session), 'session row loaded');
if (!is_array($session)) {
    $assert->summary();
    exit(1);
}

$assert->assertSame($principalCode, (string) $session['tenant_id'], 'session starts in principal tenant');

$ctxBefore = $contexts->contextsForSession($session);
$assert->assertTrue(($ctxBefore['ok'] ?? false) === true, 'contexts before switch');
$hasDelegated = false;
foreach ($ctxBefore['delegated'] ?? [] as $entry) {
    if (
        is_array($entry)
        && (string) ($entry['tenant_id'] ?? '') === $managedCode
        && (string) ($entry['subtenant_id'] ?? '') === $managedSub
    ) {
        $hasDelegated = true;
        $assert->assertSame('operator', (string) ($entry['grant_level'] ?? ''), 'delegated entry has grant_level');
    }
}
$assert->assertTrue($hasDelegated, 'delegated contexts include client-demo/main');

$switch = $contexts->switchContext($session, $accessToken, $managedCode, $managedSub);
$assert->assertTrue(($switch['ok'] ?? false) === true, 'switchContext to managed tenant');
$assert->assertSame('delegated', (string) ($switch['session']['kind'] ?? ''), 'switch session kind');
$assert->assertTrue(($switch['session']['delegated'] ?? false) === true, 'switch session delegated flag');

$sessionAfter = $sessions->findActiveByToken($accessToken);
$assert->assertTrue(is_array($sessionAfter), 'session after switch');
if (is_array($sessionAfter)) {
    $assert->assertSame($managedCode, (string) $sessionAfter['tenant_id'], 'session tenant_id switched to managed');
    $assert->assertSame($managedSub, (string) $sessionAfter['subtenant_id'], 'session subtenant_id switched');
}

$ctxAfter = $contexts->contextsForSession($sessionAfter ?? $session);
$current = $ctxAfter['current'] ?? [];
$assert->assertSame($managedCode, (string) ($current['tenant_id'] ?? ''), 'current context tenant after switch');
$assert->assertSame('delegated', (string) ($current['kind'] ?? ''), 'current context kind delegated');

$pdo = Connection::get();
$pdo->prepare(
    'UPDATE maniforge_tl_tenant_grants SET grant_level = :level
     WHERE principal_tenant_code = :principal AND managed_tenant_code = :managed AND status = :status'
)->execute([
    ':level' => 'read_only',
    ':principal' => $principalCode,
    ':managed' => $managedCode,
    ':status' => 'active',
]);

$policy = new DelegatedAccessPolicy();
$ctxReadOnly = $contexts->contextsForSession($sessionAfter ?? $session);
$assert->assertSame('read_only', (string) (($ctxReadOnly['current'] ?? [])['grant_level'] ?? ''), 'grant_level read_only after update');

$denyAdmin = $policy->allowsHttpMutation($sessionAfter ?? $session, 'POST', '/api/v1/admin/users');
$assert->assertTrue(($denyAdmin['ok'] ?? false) !== true, 'read_only blocks POST admin/users');
$assert->assertSame('delegated_read_only', (string) ($denyAdmin['code'] ?? ''), 'read_only error code');

$allowSwitch = $policy->allowsHttpMutation($sessionAfter ?? $session, 'POST', '/api/v1/auth/switch-context');
$assert->assertTrue(($allowSwitch['ok'] ?? false) === true, 'read_only allows switch-context');

$pdo->prepare(
    'UPDATE maniforge_tl_tenant_grants SET grant_level = :level
     WHERE principal_tenant_code = :principal AND managed_tenant_code = :managed AND status = :status'
)->execute([
    ':level' => 'operator',
    ':principal' => $principalCode,
    ':managed' => $managedCode,
    ':status' => 'active',
]);

$ctxOperator = $contexts->contextsForSession($sessionAfter ?? $session);
$assert->assertSame('operator', (string) (($ctxOperator['current'] ?? [])['grant_level'] ?? ''), 'grant_level operator restored');

$denyOperatorCreate = $policy->allowsHttpMutation($sessionAfter ?? $session, 'POST', '/api/v1/admin/users');
$assert->assertTrue(($denyOperatorCreate['ok'] ?? false) !== true, 'operator blocks POST admin/users');
$assert->assertSame('delegated_operator_restricted', (string) ($denyOperatorCreate['code'] ?? ''), 'operator error code');

$allowOperatorAudit = $policy->allowsHttpMutation($sessionAfter ?? $session, 'GET', '/api/v1/admin/audit');
$assert->assertTrue(($allowOperatorAudit['ok'] ?? false) === true, 'operator allows GET (non-mutation)');

$limitPlan = 'delegation_limit_' . substr(bin2hex(random_bytes(4)), 0, 8);
$limitPrincipal = 'dl_principal_' . substr(bin2hex(random_bytes(4)), 0, 8);
$licensing->upsertPlan(
    $limitPlan,
    'Delegation Limit Test',
    'active',
    ['rbac' => true],
    ['max_users' => 5, 'max_sessions' => 50, 'max_subtenants' => 1, 'max_tenants' => 1],
    $actor
);
$licensing->createTenant($limitPrincipal, 'Limit Principal', $actor, ['source' => 'delegation_check']);
$licensing->createSubtenant($limitPrincipal, 'main', 'Main', $actor, ['source' => 'delegation_check']);
$licensing->assignLicense($limitPrincipal, $limitPlan, $actor, gmdate('Y-m-d H:i:s', strtotime('+30 days')), 5);

$managedA = 'dl_managed_a_' . substr(bin2hex(random_bytes(3)), 0, 6);
$managedB = 'dl_managed_b_' . substr(bin2hex(random_bytes(3)), 0, 6);
foreach ([$managedA, $managedB] as $code) {
    $licensing->createTenant($code, $code, $actor, ['source' => 'delegation_check']);
    $licensing->createSubtenant($code, 'main', 'Main', $actor, ['source' => 'delegation_check']);
}

$firstGrant = $licensing->createManagedTenantGrant($limitPrincipal, $managedA, 'read_only', $actor, []);
$assert->assertTrue(($firstGrant['ok'] ?? false) === true, 'first grant under max_tenants=1');

$secondGrant = $licensing->createManagedTenantGrant($limitPrincipal, $managedB, 'read_only', $actor, []);
$assert->assertSame(403, (int) ($secondGrant['status'] ?? 0), 'second grant rejected at max_tenants');

foreach ([$managedA, $managedB, $limitPrincipal] as $code) {
    $pdo = Connection::get();
    $pdo->prepare('DELETE FROM maniforge_tl_tenant_grants WHERE principal_tenant_code = :p OR managed_tenant_code = :m')
        ->execute([':p' => $code, ':m' => $code]);
    $pdo->prepare('DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :t')->execute([':t' => $code]);
    $pdo->prepare('DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :t')->execute([':t' => $code]);
    $pdo->prepare('DELETE FROM maniforge_tl_tenants WHERE code = :t')->execute([':t' => $code]);
}
$pdo = Connection::get();
$pdo->prepare('DELETE FROM maniforge_tl_license_plans WHERE code = :c')->execute([':c' => $limitPlan]);

$assert->summary();
exit($assert->hasFailed() ? 1 : 0);
