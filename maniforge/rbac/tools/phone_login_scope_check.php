<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\SessionRepository;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\PasswordService;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$ok = 0;
$fail = 0;

function assertCheck(bool $cond, string $msg): void
{
    global $ok, $fail;
    if ($cond) {
        $ok++;
        fwrite(STDOUT, "[OK] {$msg}\n");
        return;
    }
    $fail++;
    fwrite(STDERR, "[FAIL] {$msg}\n");
}

$pdo = Connection::get();
$passwords = new PasswordService();
$auth = new AuthService();
$licensing = new TenantLicensingRepository();
$plainPassword = 'PhoneScopeTest!' . bin2hex(random_bytes(4));
$passwordHash = $passwords->hash($plainPassword);
$sharedPhone = '+79' . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);

$tenantA = 'ph_' . substr(bin2hex(random_bytes(4)), 0, 8);
$tenantB = 'ph_' . substr(bin2hex(random_bytes(4)), 0, 8);
$subtenantId = 'main';
$planCode = strtolower(trim((string) ($_ENV['RBAC_REGISTRATION_PLAN'] ?? 'starter')));
$actor = 'phone_login_scope_check';

$licensing->upsertPlan(
    $planCode,
    ucfirst($planCode),
    'active',
    ['rbac' => true, 'admin_api' => true, 'tenant_admin' => true],
    ['max_users' => 25, 'max_sessions' => 250],
    $actor
);

foreach ([$tenantA => 'Phone scope A', $tenantB => 'Phone scope B'] as $tenantCode => $tenantName) {
    $tenant = $licensing->createTenant($tenantCode, $tenantName, $actor);
    if (($tenant['ok'] ?? false) !== true && (int) ($tenant['status'] ?? 0) !== 409) {
        throw new RuntimeException((string) ($tenant['error'] ?? 'tenant create failed'));
    }
    $subtenant = $licensing->createSubtenant($tenantCode, $subtenantId, $tenantName, $actor);
    if (($subtenant['ok'] ?? false) !== true && (int) ($subtenant['status'] ?? 0) !== 409) {
        throw new RuntimeException((string) ($subtenant['error'] ?? 'subtenant create failed'));
    }
    $licensing->assignLicense($tenantCode, $planCode, $actor, gmdate('Y-m-d H:i:s', strtotime('+30 days')), 10);
}

$insertUser = $pdo->prepare(
    'INSERT INTO maniforge_users (
        tenant_id, subtenant_id, login, email, phone, password_hash, mfa_required, security_version, status, updated_at
    ) VALUES (
        :tenant_id, :subtenant_id, :login, :email, :phone, :password_hash, 0, 1, "active", :updated_at
    )'
);

$olderUpdatedAt = gmdate('Y-m-d H:i:s', time() - 7200);
$newerUpdatedAt = gmdate('Y-m-d H:i:s', time() - 3600);

$insertUser->execute([
    ':tenant_id' => $tenantA,
    ':subtenant_id' => $subtenantId,
    ':login' => 'user_a_' . substr(bin2hex(random_bytes(3)), 0, 5),
    ':email' => null,
    ':phone' => $sharedPhone,
    ':password_hash' => $passwordHash,
    ':updated_at' => $olderUpdatedAt,
]);
$userAId = (int) $pdo->lastInsertId();

$insertUser->execute([
    ':tenant_id' => $tenantB,
    ':subtenant_id' => $subtenantId,
    ':login' => 'user_b_' . substr(bin2hex(random_bytes(3)), 0, 5),
    ':email' => null,
    ':phone' => $sharedPhone,
    ':password_hash' => $passwordHash,
    ':updated_at' => $newerUpdatedAt,
]);
$userBId = (int) $pdo->lastInsertId();

$insertSession = $pdo->prepare(
    'INSERT INTO maniforge_sessions (
        id, user_id, tenant_id, subtenant_id, session_secret_hash, ip_hash, user_agent_hash,
        aal, last_activity_at, expires_at, security_version_snapshot, created_at
    ) VALUES (
        :id, :user_id, :tenant_id, :subtenant_id, :session_secret_hash, :ip_hash, :user_agent_hash,
        :aal, :last_activity_at, :expires_at, 1, :created_at
    )'
);

$olderSessionAt = gmdate('Y-m-d H:i:s', time() - 86400);
$newerSessionAt = gmdate('Y-m-d H:i:s', time() - 60);
$dummyHash = hash('sha256', 'phone-login-scope-check');

$insertSession->execute([
    ':id' => bin2hex(random_bytes(16)),
    ':user_id' => $userAId,
    ':tenant_id' => $tenantA,
    ':subtenant_id' => $subtenantId,
    ':session_secret_hash' => hash('sha256', $dummyHash . 'a'),
    ':ip_hash' => $dummyHash,
    ':user_agent_hash' => $dummyHash,
    ':aal' => 'AAL1',
    ':last_activity_at' => $olderSessionAt,
    ':expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ':created_at' => $olderSessionAt,
]);

$insertSession->execute([
    ':id' => bin2hex(random_bytes(16)),
    ':user_id' => $userBId,
    ':tenant_id' => $tenantB,
    ':subtenant_id' => $subtenantId,
    ':session_secret_hash' => hash('sha256', $dummyHash . 'b'),
    ':ip_hash' => $dummyHash,
    ':user_agent_hash' => $dummyHash,
    ':aal' => 'AAL1',
    ':last_activity_at' => $newerSessionAt,
    ':expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ':created_at' => $newerSessionAt,
]);

try {
    $login = $auth->login([], ['phone' => $sharedPhone, 'password' => $plainPassword], ['REMOTE_ADDR' => '127.0.0.1']);
    assertCheck(($login['ok'] ?? false) === true, 'Phone login succeeds with ambiguous phone');
    assertCheck((int) ($login['status'] ?? 0) !== 409, 'Phone login does not return ambiguous_phone 409');
    assertCheck((int) ($login['user']['id'] ?? 0) === $userBId, 'Login picks organization with latest session activity');

    $deleteSessions = $pdo->prepare('DELETE FROM maniforge_sessions WHERE user_id = :user_id');
    $deleteSessions->execute([':user_id' => $userAId]);
    $deleteSessions->execute([':user_id' => $userBId]);

    $loginFallback = $auth->login([], ['phone' => $sharedPhone, 'password' => $plainPassword], ['REMOTE_ADDR' => '127.0.0.1']);
    assertCheck(($loginFallback['ok'] ?? false) === true, 'Phone login succeeds without session history');
    assertCheck(
        (int) ($loginFallback['user']['id'] ?? 0) === $userBId,
        'Fallback picks user with latest updated_at when no login logs'
    );

    $keyA = SessionRepository::userScopeKey($userAId, $tenantA, $subtenantId);
    $keyB = SessionRepository::userScopeKey($userBId, $tenantB, $subtenantId);
    assertCheck($keyA !== '' && $keyB !== '', 'Scope keys are built for session lookup');
} catch (Throwable $e) {
    assertCheck(false, 'Unhandled exception: ' . $e->getMessage());
} finally {
    try {
        foreach ([$userAId, $userBId] as $userId) {
            $pdo->prepare('DELETE FROM maniforge_sessions WHERE user_id = :user_id')
                ->execute([':user_id' => $userId]);
            $pdo->prepare('DELETE FROM maniforge_users WHERE id = :id')
                ->execute([':id' => $userId]);
        }
        $cleanupAudit = $pdo->prepare(
            'DELETE FROM maniforge_audit_log WHERE tenant_id IN (:ta, :tb)'
        );
        $cleanupAudit->execute([':ta' => $tenantA, ':tb' => $tenantB]);
        $cleanupEvents = $pdo->prepare(
            'DELETE FROM maniforge_security_events WHERE tenant_id IN (:ta, :tb)'
        );
        $cleanupEvents->execute([':ta' => $tenantA, ':tb' => $tenantB]);
    } catch (Throwable) {
    }
}

fwrite(STDOUT, "\nSummary: ok={$ok}, fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
