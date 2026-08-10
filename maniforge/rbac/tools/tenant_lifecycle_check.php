<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Security\TenantLicensingClient;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

final class LifecycleAsserts
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
        fwrite(STDOUT, "\nLifecycle checks passed: {$this->passed}\n");
        fwrite(STDOUT, "Lifecycle checks failed: {$this->failed}\n");
    }

    public function hasFailed(): bool
    {
        return $this->failed > 0;
    }
}

function cleanupLifecycleData(string $tenantCode, string $planCode): void
{
    $pdo = Connection::get();
    foreach ([
        'DELETE FROM maniforge_tenant_access_cache WHERE tenant_code = :tenant_code',
        'DELETE FROM maniforge_tl_quota_usage WHERE tenant_code = :tenant_code',
        'DELETE FROM maniforge_tl_events WHERE tenant_code = :tenant_code',
        'DELETE FROM maniforge_tl_audit_log WHERE tenant_code = :tenant_code',
        'DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :tenant_code',
        'DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :tenant_code',
        'DELETE FROM maniforge_tl_tenants WHERE code = :tenant_code',
    ] as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tenant_code' => $tenantCode]);
    }

    $stmt = $pdo->prepare('DELETE FROM maniforge_tl_license_plans WHERE code = :plan_code');
    $stmt->execute([':plan_code' => $planCode]);
}

$assert = new LifecycleAsserts();
$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$tenantCode = 'lc_' . $suffix;
$subtenantCode = 'main';
$planCode = 'lc_plan_' . $suffix;
$actor = 'tenant_lifecycle_check';
$previousEnforcement = $_ENV['TENANT_LICENSING_ENFORCEMENT'] ?? null;
$previousInternalUrl = $_ENV['TENANT_LICENSING_INTERNAL_URL'] ?? null;

try {
    $_ENV['TENANT_LICENSING_ENFORCEMENT'] = 'strict';
    $_ENV['TENANT_LICENSING_INTERNAL_URL'] = '';

    $repository = new TenantLicensingRepository();
    $client = new TenantLicensingClient();

    cleanupLifecycleData($tenantCode, $planCode);

    $tenant = $repository->createTenant($tenantCode, 'Lifecycle Check Tenant', $actor, ['check' => true]);
    $assert->assertSame(true, (bool) ($tenant['ok'] ?? false), 'Tenant created');

    $subtenant = $repository->createSubtenant($tenantCode, $subtenantCode, 'Main Workspace', $actor, ['check' => true]);
    $assert->assertSame(true, (bool) ($subtenant['ok'] ?? false), 'Subtenant created');

    $plan = $repository->upsertPlan(
        $planCode,
        'Lifecycle Check Plan',
        'active',
        ['rbac' => true, 'admin_api' => true],
        ['max_users' => 1, 'max_sessions' => 10],
        $actor
    );
    $assert->assertSame(true, (bool) ($plan['ok'] ?? false), 'Plan upserted');

    $license = $repository->assignLicense($tenantCode, $planCode, $actor, gmdate('Y-m-d H:i:s', strtotime('+1 day')), 1);
    $assert->assertSame(true, (bool) ($license['ok'] ?? false), 'Active license assigned');

    $state = $repository->accessState($tenantCode, $subtenantCode);
    $assert->assertSame(true, (bool) ($state['tenant_active'] ?? false), 'Access state tenant active');
    $assert->assertSame(true, (bool) ($state['subtenant_active'] ?? false), 'Access state subtenant active');
    $assert->assertSame(true, (bool) ($state['license_active'] ?? false), 'Access state license active');

    $access = $client->assertAccess($tenantCode, $subtenantCode);
    $assert->assertSame(true, (bool) ($access['ok'] ?? false), 'RBAC client allows active licensed tenant');

    $seatAllowed = $client->assertUserActivationAllowed($tenantCode, $subtenantCode, 0);
    $assert->assertSame(true, (bool) ($seatAllowed['ok'] ?? false), 'Seats allow first active user');
    $assert->assertSame(true, (bool) ($seatAllowed['seats']['enforced'] ?? false), 'Seats enforcement is active');

    $seatDenied = $client->assertUserActivationAllowed($tenantCode, $subtenantCode, 1);
    $assert->assertSame(false, (bool) ($seatDenied['ok'] ?? true), 'Seats deny user beyond license limit');
    $assert->assertSame('seats_quota_exceeded', (string) ($seatDenied['deny_reason'] ?? ''), 'Seats deny reason is quota exceeded');

    $repository->updateSubtenant($tenantCode, $subtenantCode, ['name' => 'Main Workspace', 'status' => 'suspended'], $actor);
    $subtenantDenied = $client->assertAccess($tenantCode, $subtenantCode);
    $assert->assertSame(false, (bool) ($subtenantDenied['ok'] ?? true), 'Suspended subtenant denies access');
    $assert->assertSame('subtenant_not_active', (string) ($subtenantDenied['deny_reason'] ?? ''), 'Subtenant deny reason recorded');

    $repository->updateSubtenant($tenantCode, $subtenantCode, ['name' => 'Main Workspace', 'status' => 'active'], $actor);
    $repository->updateTenant($tenantCode, ['name' => 'Lifecycle Check Tenant', 'status' => 'suspended'], $actor);
    $tenantDenied = $client->assertAccess($tenantCode, $subtenantCode);
    $assert->assertSame(false, (bool) ($tenantDenied['ok'] ?? true), 'Suspended tenant denies access');
    $assert->assertSame('tenant_not_active', (string) ($tenantDenied['deny_reason'] ?? ''), 'Tenant deny reason recorded');

    $repository->updateTenant($tenantCode, ['name' => 'Lifecycle Check Tenant', 'status' => 'active'], $actor);
    $repository->revokeLicense($tenantCode, $actor, 'lifecycle_check_revoke');
    $licenseDenied = $client->assertAccess($tenantCode, $subtenantCode);
    $assert->assertSame(false, (bool) ($licenseDenied['ok'] ?? true), 'Revoked license denies access');
    $assert->assertSame('license_not_active', (string) ($licenseDenied['deny_reason'] ?? ''), 'License revoke deny reason recorded');

    $repository->assignLicense($tenantCode, $planCode, $actor, gmdate('Y-m-d H:i:s', strtotime('-1 hour')), 1);
    $expired = $repository->expireDueLicenses($actor);
    $assert->assertTrue((int) ($expired['expired'] ?? 0) >= 1, 'Expired license job marks due licenses');

    $expiredDenied = $client->assertAccess($tenantCode, $subtenantCode);
    $assert->assertSame(false, (bool) ($expiredDenied['ok'] ?? true), 'Expired license denies access');
    $assert->assertSame('license_not_active', (string) ($expiredDenied['deny_reason'] ?? ''), 'Expired license deny reason recorded');

    $events = $repository->listEvents($tenantCode, 100);
    $audit = $repository->listAudit($tenantCode, 100);
    $assert->assertTrue(count($events) >= 5, 'Lifecycle events recorded');
    $assert->assertTrue(count($audit) >= 5, 'Lifecycle audit recorded');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in tenant lifecycle check');
} finally {
    if ($previousEnforcement === null) {
        unset($_ENV['TENANT_LICENSING_ENFORCEMENT']);
    } else {
        $_ENV['TENANT_LICENSING_ENFORCEMENT'] = $previousEnforcement;
    }
    if ($previousInternalUrl === null) {
        unset($_ENV['TENANT_LICENSING_INTERNAL_URL']);
    } else {
        $_ENV['TENANT_LICENSING_INTERNAL_URL'] = $previousInternalUrl;
    }

    try {
        cleanupLifecycleData($tenantCode, $planCode);
    } catch (Throwable) {
    }
}

$assert->summary();
exit($assert->hasFailed() ? 1 : 0);
