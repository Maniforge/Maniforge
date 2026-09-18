<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\PdSubjectRequestRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\PasswordService;
use App\Maniforge\Rbac\Security\PdBootstrapService;
use App\Maniforge\Rbac\Security\PersonalDataService;
use App\Maniforge\Rbac\Security\SessionService;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

final class PdJourneyAsserts
{
    public int $passed = 0;
    public int $failed = 0;

    public function ok(bool $cond, string $msg): void
    {
        if ($cond) {
            $this->passed++;
            fwrite(STDOUT, "[OK] {$msg}\n");
            return;
        }
        $this->failed++;
        fwrite(STDERR, "[FAIL] {$msg}\n");
    }
}

$assert = new PdJourneyAsserts();
$tenantId = 'pd_j_' . substr(bin2hex(random_bytes(4)), 0, 8);
$subtenantId = 'main';
$actor = 'pd_compliance_journey';
$login = 'pd_user_' . substr(bin2hex(random_bytes(3)), 0, 6);
$adminLogin = 'pd_admin_' . substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'PdJourney!12345';
$phone = '+7900' . random_int(1000000, 9999999);

$licensing = new TenantLicensingRepository();
$auth = new AuthService();
$sessions = new SessionService();
$users = new UserRepository();
$passwords = new PasswordService();
$pd = new PersonalDataService();
$bootstrap = new PdBootstrapService();

try {
    Connection::get()->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, 'DB unavailable: ' . $e->getMessage() . "\n");
    exit(2);
}

$licensing->createTenant($tenantId, 'PD Journey Tenant', $actor, []);
$licensing->createSubtenant($tenantId, $subtenantId, 'Main', $actor, []);
$licensing->assignLicense($tenantId, 'starter', $actor, gmdate('Y-m-d H:i:s', strtotime('+30 days')), 10);
$licensing->mergeTenantMetadata($tenantId, ['dpa_signed_at' => gmdate('Y-m-d H:i:s'), 'dpa_source' => 'journey'], $actor);
$bootstrap->seedTenant($tenantId, 'PD Journey Tenant');
(new \App\Maniforge\Rbac\Repository\PdOperatorProfileRepository())->upsert($tenantId, [
    'operator_name' => 'PD Journey Tenant',
    'privacy_policy_url' => 'https://example.com/privacy',
    'dpo_email' => 'dpo@example.com',
    'data_storage_region' => 'RU',
]);

$notice = $pd->buildPrivacyNotice($tenantId);
$assert->ok(($notice['ok'] ?? false) === true, 'privacy notice published');

$user = $users->createUser($tenantId, $subtenantId, $login, null, $phone, $passwords->hash($password), false, 'active');
$userId = (int) ($user['id'] ?? 0);
$pd->recordRegistrationConsents($userId, $tenantId, $subtenantId, [['purpose_code' => 'account', 'policy_version' => '1.0']], ['REMOTE_ADDR' => '127.0.0.1']);
$assert->ok($userId > 0, 'user created with consent');
$adminPhone = '+7901' . random_int(1000000, 9999999);
$admin = $users->createUser($tenantId, $subtenantId, $adminLogin, null, $adminPhone, $passwords->hash($password), false, 'active');
$adminId = (int) ($admin['id'] ?? 0);
$pdo = Connection::get();
$role = $pdo->query("SELECT id FROM maniforge_roles WHERE code = 'tenant_admin' LIMIT 1")->fetch();
if (is_array($role)) {
    $pdo->prepare(
        'INSERT IGNORE INTO maniforge_user_roles (user_id, role_id, tenant_id, subtenant_id, assigned_by)
         VALUES (:uid, :rid, :t, :s, :assigned_by)'
    )->execute([
        ':uid' => $adminId,
        ':rid' => $role['id'],
        ':t' => $tenantId,
        ':s' => $subtenantId,
        ':assigned_by' => $adminId,
    ]);
}

$loginRes = $auth->login(
    ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
    ['phone' => $phone, 'password' => $password],
    ['REMOTE_ADDR' => '127.0.0.1']
);
$assert->ok(($loginRes['ok'] ?? false) === true, 'user login');
$token = (string) (($loginRes['session'] ?? $loginRes['credentials']['session'] ?? [])['access_token'] ?? '');
$userSession = $sessions->authenticate($token);
$assert->ok(is_array($userSession), 'user session');

$req = $pd->createSubjectRequest($userSession, 'access', ['note' => 'journey test']);
$assert->ok(($req['ok'] ?? false) === true, 'subject request created');
$requestId = (int) (($req['request'] ?? [])['id'] ?? 0);

$adminLoginRes = $auth->login(
    ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
    ['phone' => $adminPhone, 'password' => $password],
    ['REMOTE_ADDR' => '127.0.0.1']
);
$adminSession = $sessions->authenticate((string) (($adminLoginRes['session'] ?? [])['access_token'] ?? ''));
$resolve = $pd->resolveSubjectRequest($adminSession, $requestId, 'completed', 'journey done');
$assert->ok(($resolve['ok'] ?? false) === true, 'admin resolves subject request');

$repo = new PdSubjectRequestRepository();
$row = $repo->findById($requestId);
$assert->ok(is_array($row) && ($row['status'] ?? '') === 'completed', 'request status completed');

$pdo->prepare('DELETE FROM maniforge_pd_subject_requests WHERE tenant_id = :t')->execute([':t' => $tenantId]);
$pdo->prepare('DELETE FROM maniforge_pd_consents WHERE tenant_id = :t')->execute([':t' => $tenantId]);
$pdo->prepare('DELETE FROM maniforge_pd_processing_purposes WHERE tenant_id = :t')->execute([':t' => $tenantId]);
$pdo->prepare('DELETE FROM maniforge_pd_operator_profiles WHERE tenant_id = :t')->execute([':t' => $tenantId]);
$pdo->prepare('DELETE FROM maniforge_users WHERE tenant_id = :t')->execute([':t' => $tenantId]);
$pdo->prepare('DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :t')->execute([':t' => $tenantId]);
$pdo->prepare('DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :t')->execute([':t' => $tenantId]);
$pdo->prepare('DELETE FROM maniforge_tl_tenants WHERE code = :t')->execute([':t' => $tenantId]);

fwrite(STDOUT, "\nPD compliance journey passed: {$assert->passed}, failed: {$assert->failed}\n");
exit($assert->failed > 0 ? 1 : 0);
