<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\PolicyRuleRepository;
use App\Maniforge\Rbac\Repository\RateLimitRepository;
use App\Maniforge\Rbac\Repository\RoleRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Security\PolicyEngine;
use App\Maniforge\Rbac\Security\RoleAdminService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function printUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/integration_check.php [--skip-preflight] [--help]\n");
    fwrite(STDOUT, "  --skip-preflight  Skip preflight stage and run integration checks directly\n");
    fwrite(STDOUT, "  --help            Show this help message\n");
}

/**
 * @param string[] $argv
 */
function hasCliFlag(array $argv, string $flag): bool
{
    foreach ($argv as $arg) {
        if ($arg === $flag) {
            return true;
        }
    }

    return false;
}

final class IntegrationAsserts
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

    public function hasFailed(): bool
    {
        return $this->failed > 0;
    }

    public function summary(): void
    {
        fwrite(STDOUT, "\nChecks passed: {$this->passed}\n");
        fwrite(STDOUT, "Checks failed: {$this->failed}\n");
    }
}

$assert = new IntegrationAsserts();
$showHelp = hasCliFlag($argv, '--help');
$skipPreflight = hasCliFlag($argv, '--skip-preflight');

if ($showHelp) {
    printUsage();
    exit(0);
}

if (!$skipPreflight) {
    $projectRoot = dirname(__DIR__, 3);
    $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $preflightScript = $projectRoot . '/maniforge/rbac/tools/preflight.php';
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($preflightScript);

    fwrite(STDOUT, "Running preflight before integration checks...\n");
    passthru($command, $preflightExitCode);
    if ($preflightExitCode !== 0) {
        fwrite(STDERR, "Preflight failed with code {$preflightExitCode}. Integration checks aborted.\n");
        exit($preflightExitCode);
    }
}

try {
    $pdo = Connection::get();
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] DB connection failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Check DB_* variables in .env and run migrations before integration check.\n");
    exit(2);
}

$users = new UserRepository();
$roles = new RoleRepository();
$roleAdmin = new RoleAdminService($roles);
$rules = new PolicyRuleRepository();
$policyEngine = new PolicyEngine($rules);
$rateLimits = new RateLimitRepository();

$tenantId = 'it_' . substr(bin2hex(random_bytes(6)), 0, 10);
$subtenantId = 'default';
$actorUserId = 1;

try {
    $requiredPermissions = [
        'admin.user_roles.bulk',
        'admin.users.status.bulk',
        'admin.policies.read',
        'admin.policies.update',
    ];
    foreach ($requiredPermissions as $permissionCode) {
        $stmt = $pdo->prepare('SELECT id FROM maniforge_permissions WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $permissionCode]);
        $assert->assertTrue(is_array($stmt->fetch()), "Permission exists: {$permissionCode}");
    }

    $_ENV['RBAC_ADMIN_ALLOWED_IPS'] = '127.0.0.1';
    $_ENV['RBAC_ADMIN_ALLOWED_HOUR_START_UTC'] = '0';
    $_ENV['RBAC_ADMIN_ALLOWED_HOUR_END_UTC'] = '23';
    $_ENV['RBAC_ADMIN_REQUIRE_STEP_UP'] = 'true';

    $fallback = $policyEngine->getEffectiveAdminRules($tenantId, $subtenantId);
    $assert->assertSame('env', (string) ($fallback['source'] ?? ''), 'Policy source is env when DB rule is missing');
    $assert->assertSame(true, (bool) ($fallback['require_step_up'] ?? false), 'Fallback require_step_up is true');

    $rules->upsertForScope($tenantId, $subtenantId, ['10.10.10.10'], 8, 18, false, $actorUserId);
    $effective = $policyEngine->getEffectiveAdminRules($tenantId, $subtenantId);
    $assert->assertSame('db', (string) ($effective['source'] ?? ''), 'Policy source is db when scoped rule exists');
    $assert->assertSame(['10.10.10.10'], $effective['allowed_ips'] ?? [], 'DB policy stores allowed_ips');
    $assert->assertSame(8, (int) ($effective['allowed_hour_start_utc'] ?? -1), 'DB policy stores hour start');
    $assert->assertSame(18, (int) ($effective['allowed_hour_end_utc'] ?? -1), 'DB policy stores hour end');
    $assert->assertSame(false, $policyEngine->requiresStepUp($tenantId, $subtenantId), 'DB policy controls require_step_up');

    $rateLimitKey = hash('sha256', $tenantId . random_bytes(8));
    $assert->assertSame(1, $rateLimits->increment($rateLimitKey, 60), 'DB rate limit first hit count');
    $assert->assertSame(2, $rateLimits->increment($rateLimitKey, 60), 'DB rate limit second hit count');

    $insertUser = $pdo->prepare(
        'INSERT INTO maniforge_users (
            tenant_id, subtenant_id, login, email, phone, password_hash, mfa_required, security_version, status
        ) VALUES (
            :tenant_id, :subtenant_id, :login, :email, :phone, :password_hash, 0, 1, "active"
        )'
    );

    $userA = 'it_user_a_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $userB = 'it_user_b_' . substr(bin2hex(random_bytes(4)), 0, 6);
    $passwordHash = '$2y$10$abcdefghijklmnopqrstuvabcdefghijklmnopqrstuvabcdefghijklmn';

    $insertUser->execute([
        ':tenant_id' => $tenantId,
        ':subtenant_id' => $subtenantId,
        ':login' => $userA,
        ':email' => $userA . '@example.test',
        ':phone' => '+7900' . substr(bin2hex(random_bytes(2)), 0, 7),
        ':password_hash' => $passwordHash,
    ]);
    $userAId = (int) $pdo->lastInsertId();

    $insertUser->execute([
        ':tenant_id' => $tenantId,
        ':subtenant_id' => $subtenantId,
        ':login' => $userB,
        ':email' => $userB . '@example.test',
        ':phone' => '+7901' . substr(bin2hex(random_bytes(2)), 0, 7),
        ':password_hash' => $passwordHash,
    ]);
    $userBId = (int) $pdo->lastInsertId();
    $missingId = $userBId + 1000000;

    $selfEscalationGuard = $roleAdmin->guardRoleMutation(
        $userAId,
        $userAId,
        'tenant_admin',
        'assign',
        $tenantId,
        $subtenantId
    );
    $assert->assertSame(false, (bool) ($selfEscalationGuard['ok'] ?? true), 'Guard blocks privileged self-escalation');

    $assert->assertSame(true, $roles->assignRoleByCode($userBId, $tenantId, $subtenantId, 'user', $userAId), 'Seed role for batch revoke');
    $roleBatchItems = [
        ['user_id' => $userAId, 'role_code' => 'support_operator', 'action' => 'assign'],
        ['user_id' => $userBId, 'role_code' => 'user', 'action' => 'revoke'],
        ['user_id' => $userAId, 'role_code' => 'moderator', 'action' => 'revoke'],
    ];

    $roleDryRunSummary = $roleAdmin->simulateBatchSummary($tenantId, $subtenantId, $roleBatchItems);
    $assert->assertSame(1, (int) ($roleDryRunSummary['assigned'] ?? -1), 'Role batch dry-run assigned count');
    $assert->assertSame(1, (int) ($roleDryRunSummary['revoked'] ?? -1), 'Role batch dry-run revoked count');
    $assert->assertSame(1, (int) ($roleDryRunSummary['skipped'] ?? -1), 'Role batch dry-run skipped count');
    $assert->assertSame(3, (int) ($roleDryRunSummary['total'] ?? -1), 'Role batch dry-run total count');

    $roleApplySummary = $roles->applyRoleMutationsBatch($tenantId, $subtenantId, $userAId, $roleBatchItems);
    $assert->assertSame(1, (int) ($roleApplySummary['assigned'] ?? -1), 'Role batch apply assigned count');
    $assert->assertSame(1, (int) ($roleApplySummary['revoked'] ?? -1), 'Role batch apply revoked count');
    $assert->assertSame(1, (int) ($roleApplySummary['skipped'] ?? -1), 'Role batch apply skipped count');
    $assert->assertSame(true, $roles->hasRoleInScope($userAId, $tenantId, $subtenantId, 'support_operator'), 'Role batch assigned support_operator');
    $assert->assertSame(false, $roles->hasRoleInScope($userBId, $tenantId, $subtenantId, 'user'), 'Role batch revoked user role');

    $roleIdempotentSummary = $roles->applyRoleMutationsBatch($tenantId, $subtenantId, $userAId, $roleBatchItems);
    $assert->assertSame(0, (int) ($roleIdempotentSummary['assigned'] ?? -1), 'Idempotent role batch assigned count');
    $assert->assertSame(0, (int) ($roleIdempotentSummary['revoked'] ?? -1), 'Idempotent role batch revoked count');
    $assert->assertSame(3, (int) ($roleIdempotentSummary['skipped'] ?? -1), 'Idempotent role batch skipped count');

    $assert->assertSame(true, $roles->assignRoleByCode($userAId, $tenantId, $subtenantId, 'tenant_admin', $userAId), 'Seed actor tenant_admin role');
    $assert->assertSame(true, $roles->assignRoleByCode($userBId, $tenantId, $subtenantId, 'tenant_admin', $userAId), 'Seed target tenant_admin role');
    $selfDemotionGuard = $roleAdmin->guardRoleMutation(
        $userAId,
        $userAId,
        'tenant_admin',
        'revoke',
        $tenantId,
        $subtenantId
    );
    $assert->assertSame(false, (bool) ($selfDemotionGuard['ok'] ?? true), 'Guard blocks privileged self-demotion');

    $secondAdminGuard = $roleAdmin->guardRoleMutation(
        $userAId,
        $userBId,
        'tenant_admin',
        'revoke',
        $tenantId,
        $subtenantId
    );
    $assert->assertSame(true, (bool) ($secondAdminGuard['ok'] ?? false), 'Guard allows revoking non-last scope admin');

    $assert->assertSame(true, $roles->assignRoleByCode($userBId, $tenantId, $subtenantId, 'subtenant_admin', $userAId), 'Seed only subtenant_admin role');
    $lastAdminGuard = $roleAdmin->guardRoleMutation(
        $userAId,
        $userBId,
        'subtenant_admin',
        'revoke',
        $tenantId,
        $subtenantId
    );
    $assert->assertSame(false, (bool) ($lastAdminGuard['ok'] ?? true), 'Guard blocks removing last scope admin');

    $summary = $users->applyStatusBatchInScope($tenantId, $subtenantId, [
        ['user_id' => $userAId, 'status' => 'locked'],
        ['user_id' => $userBId, 'status' => 'disabled'],
        ['user_id' => $missingId, 'status' => 'active'],
    ]);
    $assert->assertSame(2, (int) ($summary['changed'] ?? -1), 'Batch status changed count');
    $assert->assertSame(0, (int) ($summary['skipped'] ?? -1), 'Batch status skipped count');
    $assert->assertSame(1, (int) ($summary['not_found'] ?? -1), 'Batch status not_found count');
    $assert->assertSame(3, (int) ($summary['total'] ?? -1), 'Batch status total count');

    $assert->assertSame('locked', $users->findStatusInScope($userAId, $tenantId, $subtenantId), 'User A status updated');
    $assert->assertSame('disabled', $users->findStatusInScope($userBId, $tenantId, $subtenantId), 'User B status updated');

    $idempotentSummary = $users->applyStatusBatchInScope($tenantId, $subtenantId, [
        ['user_id' => $userAId, 'status' => 'locked'],
        ['user_id' => $userBId, 'status' => 'disabled'],
        ['user_id' => $missingId, 'status' => 'active'],
    ]);
    $assert->assertSame(0, (int) ($idempotentSummary['changed'] ?? -1), 'Idempotent batch changed count');
    $assert->assertSame(2, (int) ($idempotentSummary['skipped'] ?? -1), 'Idempotent batch skipped count');
    $assert->assertSame(1, (int) ($idempotentSummary['not_found'] ?? -1), 'Idempotent batch not_found count');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in integration check');
} finally {
    try {
        if (isset($rateLimitKey)) {
            $cleanupRateLimits = $pdo->prepare('DELETE FROM maniforge_rate_limits WHERE bucket_key = :bucket_key');
            $cleanupRateLimits->execute([':bucket_key' => $rateLimitKey]);
        }
        $cleanupUsers = $pdo->prepare('DELETE FROM maniforge_users WHERE tenant_id = :tenant_id');
        $cleanupUsers->execute([':tenant_id' => $tenantId]);
    } catch (Throwable) {
    }
    try {
        $cleanupRules = $pdo->prepare('DELETE FROM maniforge_policy_rules WHERE tenant_id = :tenant_id');
        $cleanupRules->execute([':tenant_id' => $tenantId]);
    } catch (Throwable) {
    }
    try {
        $cleanupEvents = $pdo->prepare('DELETE FROM maniforge_security_events WHERE tenant_id = :tenant_id');
        $cleanupEvents->execute([':tenant_id' => $tenantId]);
    } catch (Throwable) {
    }
}

$assert->summary();
exit($assert->hasFailed() ? 1 : 0);
