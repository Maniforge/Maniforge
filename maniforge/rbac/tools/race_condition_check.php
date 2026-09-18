<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\RateLimitRepository;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function printRaceUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/race_condition_check.php [--help]\n");
    fwrite(STDOUT, "  php maniforge/rbac/tools/race_condition_check.php --worker rate-limit <bucket_key>\n");
    fwrite(STDOUT, "  php maniforge/rbac/tools/race_condition_check.php --worker license-assign <tenant> <plan> <actor>\n");
    fwrite(STDOUT, "  php maniforge/rbac/tools/race_condition_check.php --worker invite-register <invite_token> <phone>\n");
}

if (in_array('--help', $argv, true)) {
    printRaceUsage();
    exit(0);
}

if (($argv[1] ?? '') === '--worker') {
    $worker = (string) ($argv[2] ?? '');

    if ($worker === 'rate-limit') {
        $bucketKey = (string) ($argv[3] ?? '');
        $count = (new RateLimitRepository())->increment($bucketKey, 60);
        fwrite(STDOUT, (string) $count);
        exit(0);
    }

    if ($worker === 'license-assign') {
        $tenant = (string) ($argv[3] ?? '');
        $plan = (string) ($argv[4] ?? '');
        $actor = (string) ($argv[5] ?? 'race_worker');
        $result = (new TenantLicensingRepository())->assignLicense(
            $tenant,
            $plan,
            $actor,
            gmdate('Y-m-d H:i:s', strtotime('+30 days')),
            10
        );
        fwrite(STDOUT, ($result['ok'] ?? false) ? 'ok' : 'fail');
        exit(0);
    }

    if ($worker === 'invite-register') {
        $inviteToken = (string) ($argv[3] ?? '');
        $phone = (string) ($argv[4] ?? '');
        $result = (new RegistrationService())->register([
            'password' => 'RaceCondition!123',
            'phone' => $phone,
            'invite_token' => $inviteToken,
            'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
        ]);
        fwrite(STDOUT, ($result['ok'] ?? false) ? 'ok' : 'fail:' . (int) ($result['status'] ?? 0));
        exit(0);
    }

    fwrite(STDERR, "Unknown worker: {$worker}\n");
    exit(2);
}

final class RaceAsserts
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

    public function summary(): void
    {
        fwrite(STDOUT, "\nRace condition checks passed: {$this->passed}\n");
        fwrite(STDOUT, "Race condition checks failed: {$this->failed}\n");
    }

    public function hasFailed(): bool
    {
        return $this->failed > 0;
    }
}

/**
 * @return list<string>
 */
function runParallelWorkers(string $command, int $count): array
{
    $processes = [];

    $projectRoot = dirname(__DIR__, 3);

    for ($i = 0; $i < $count; $i++) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($command, $descriptors, $pipes, $projectRoot);
        if (!is_resource($proc)) {
            continue;
        }
        fclose($pipes[0]);
        $processes[] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    $outputs = [];
    foreach ($processes as $entry) {
        $output = stream_get_contents($entry['stdout']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        proc_close($entry['proc']);
        $outputs[] = trim((string) $output);
    }

    return $outputs;
}

/**
 * @param list<string> $commands
 * @return list<string>
 */
function runParallelCommands(array $commands): array
{
    $processes = [];
    $projectRoot = dirname(__DIR__, 3);

    foreach ($commands as $command) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($command, $descriptors, $pipes, $projectRoot);
        if (!is_resource($proc)) {
            continue;
        }
        fclose($pipes[0]);
        $processes[] = ['proc' => $proc, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    $outputs = [];
    foreach ($processes as $entry) {
        $output = stream_get_contents($entry['stdout']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        proc_close($entry['proc']);
        $outputs[] = trim((string) $output);
    }

    return $outputs;
}

function cleanupRaceTenant(string $tenantId, ?string $planCode = null): void
{
    $pdo = Connection::get();
    foreach ([
        'DELETE FROM maniforge_ver_changes WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_registration_invites WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_entity_meta WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_user_roles WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_sessions WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_refresh_tokens WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_users WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_tenant_access_cache WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_quota_usage WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_events WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_audit_log WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_tenants WHERE code = :tenant_id',
    ] as $sql) {
        try {
            $pdo->prepare($sql)->execute([':tenant_id' => $tenantId]);
        } catch (Throwable) {
        }
    }

    if ($planCode !== null) {
        try {
            $pdo->prepare('DELETE FROM maniforge_tl_license_plans WHERE code = :plan_code')
                ->execute([':plan_code' => $planCode]);
        } catch (Throwable) {
        }
    }
}

$assert = new RaceAsserts();
$suffix = substr(bin2hex(random_bytes(6)), 0, 8);
$licenseTenant = 'rc_lic_' . $suffix;
$inviteTenant = '';
$planCode = 'rc_plan_' . $suffix;
$phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$scriptPath = __FILE__;
$rateBucket = 'rc_' . substr(hash('sha256', $suffix), 0, 32);

try {
    Connection::get();
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$licensing = new TenantLicensingRepository();
$registration = new RegistrationService();
$pdo = Connection::get();

try {
    $rateCmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath)
        . ' --worker rate-limit ' . escapeshellarg($rateBucket);
    $rateOutputs = runParallelWorkers($rateCmd, 12);
    $rateCounts = array_values(array_filter(array_map(static fn (string $line): int => (int) $line, $rateOutputs)));
    sort($rateCounts);
    $assert->assertTrue($rateCounts !== [], 'Rate limit workers produced output');
    $assert->assertTrue(max($rateCounts) === count($rateCounts), 'Rate limit increments are atomic (max equals worker count)');

    $stmt = $pdo->prepare('SELECT request_count FROM maniforge_rate_limits WHERE bucket_key = :bucket_key LIMIT 1');
    $stmt->execute([':bucket_key' => $rateBucket]);
    $row = $stmt->fetch();
    $assert->assertTrue(is_array($row), 'Rate limit bucket persisted');
    $assert->assertTrue((int) ($row['request_count'] ?? 0) === count($rateCounts), 'Final DB count matches worker count');

    $licensing->createTenant($licenseTenant, 'Race License Tenant', 'race_condition_check');
    $licensing->createSubtenant($licenseTenant, 'main', 'Main', 'race_condition_check');
    $licensing->upsertPlan($planCode, 'Race Plan', 'active', ['rbac' => true], ['max_users' => 50], 'race_condition_check');

    $licenseCmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath)
        . ' --worker license-assign ' . escapeshellarg($licenseTenant) . ' '
        . escapeshellarg($planCode) . ' race_worker';
    $licenseOutputs = runParallelWorkers($licenseCmd, 8);
    $licenseOk = count(array_filter($licenseOutputs, static fn (string $line): bool => $line === 'ok'));
    $assert->assertTrue($licenseOk >= 1, 'At least one parallel license assign succeeds');

    $activeStmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt FROM maniforge_tl_tenant_licenses WHERE tenant_code = :tenant_code AND status = 'active'"
    );
    $activeStmt->execute([':tenant_code' => $licenseTenant]);
    $activeCount = (int) (($activeStmt->fetch()['cnt'] ?? 0));
    $assert->assertTrue($activeCount === 1, 'Only one active license after parallel assign');

    $adminRegister = $registration->register([
        'password' => 'RaceCondition!123',
        'phone' => '+7900' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        'email' => 'rc_admin_' . $suffix . '@example.test',
        'organization_name' => 'Race Invite Org',
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    $assert->assertTrue((bool) ($adminRegister['ok'] ?? false), 'Bootstrap tenant for invite race');
    $inviteTenant = (string) ($adminRegister['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($adminRegister['tenant']['subtenant_id'] ?? 'main');
    $adminUserId = (int) ($adminRegister['user']['id'] ?? 0);

    $invite = $registration->createUserInvite($inviteTenant, $subtenantId, $adminUserId, 'user');
    $assert->assertTrue((bool) ($invite['ok'] ?? false), 'Invite prepared for race test');
    $inviteToken = (string) ($invite['invite_token'] ?? '');

    $inviteCmdA = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath)
        . ' --worker invite-register ' . escapeshellarg($inviteToken)
        . ' ' . escapeshellarg('+7905' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT));
    $inviteCmdB = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath)
        . ' --worker invite-register ' . escapeshellarg($inviteToken)
        . ' ' . escapeshellarg('+7906' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT));

    $inviteOutputs = runParallelCommands([$inviteCmdA, $inviteCmdB]);
    $inviteOk = count(array_filter($inviteOutputs, static fn (string $line): bool => $line === 'ok'));
    $assert->assertTrue($inviteOk >= 1, 'At least one parallel invite registration succeeds');

    $assert->assertTrue($inviteOk <= 1, 'At most one parallel invite registration succeeds (atomic claim)');
    if ($inviteOk === 1) {
        fwrite(STDOUT, "[OK] Invite consume race: exactly one parallel registration succeeded\n");
    }

    $usersStmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt FROM maniforge_users WHERE tenant_id = :tenant_id AND id != :admin_id'
    );
    $usersStmt->execute([':tenant_id' => $inviteTenant, ':admin_id' => $adminUserId]);
    $invitedUserCount = (int) (($usersStmt->fetch()['cnt'] ?? 0));
    $assert->assertTrue($invitedUserCount >= 1, 'At least one invited user created from raced invite');
    $assert->assertTrue($invitedUserCount <= 1, 'At most one user created from one invite token');
    if ($invitedUserCount === 1) {
        fwrite(STDOUT, "[OK] Single invited user after parallel registration attempt\n");
    }

    fwrite(STDOUT, "[INFO] Login brute-force counter uses read-then-update without FOR UPDATE — parallel failed logins may under-count (documented limitation).\n");
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in race condition check');
} finally {
    try {
        $pdo->prepare('DELETE FROM maniforge_rate_limits WHERE bucket_key = :bucket_key')
            ->execute([':bucket_key' => $rateBucket]);
    } catch (Throwable) {
    }
    cleanupRaceTenant($licenseTenant, $planCode);
    if ($inviteTenant !== '') {
        cleanupRaceTenant($inviteTenant);
    }
}

$assert->summary();
exit($assert->hasFailed() ? 1 : 0);
