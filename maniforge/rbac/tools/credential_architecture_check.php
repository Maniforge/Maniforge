<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Rbac\Security\TenantResolver;
use App\Maniforge\Rbac\Support\PublicUserPayload;
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

$resolver = new TenantResolver();
assertCheck(
    !$resolver->requiresTenantAtEdge('/api/v1/admin/users'),
    'Admin routes do not require tenant at edge'
);
assertCheck(
    $resolver->requiresTenantAtEdge('/api/v1/auth/login'),
    'Login requires tenant at edge'
);

$edge = $resolver->resolve([], '/api/v1/me', []);
assertCheck(($edge['scope_source'] ?? '') === 'session', 'Me route uses session scope source');

$stmt = Connection::get()->query("SHOW TABLES LIKE 'maniforge_action_tokens'");
assertCheck(is_array($stmt->fetch()), 'maniforge_action_tokens table exists');

$stmt = Connection::get()->query("SHOW TABLES LIKE 'maniforge_entity_meta'");
assertCheck(is_array($stmt->fetch()), 'maniforge_entity_meta table exists');

$publicUser = PublicUserPayload::fromUser(['id' => 1, 'login' => 'hidden', 'phone' => '+79001112233']);
assertCheck(!isset($publicUser['login']), 'Public API user payload has no login field');

$registration = new RegistrationService();
assertCheck(
    $registration->resolvePhoneFromInput(['login' => 'demo-admin', 'password' => 'x']) === '',
    'Login field is not accepted as phone credential'
);

$auth = new AuthService();
$loginOnly = $auth->login(
    ['tenant_id' => 'default', 'subtenant_id' => 'default'],
    ['login' => 'demo-admin', 'password' => 'wrong-password-xyz'],
    ['REMOTE_ADDR' => '127.0.0.1']
);
assertCheck(($loginOnly['ok'] ?? false) === false, 'Auth rejects login-only credentials');
assertCheck((int) ($loginOnly['status'] ?? 0) === 422, 'Login-only returns 422 (phone required)');

$licensing = new TenantLicensingRepository();
$probeTenant = 'cred_probe_' . substr(bin2hex(random_bytes(3)), 0, 6);
try {
    $licensing->createTenant($probeTenant, 'Credential probe', 'credential_check', []);
    $licensing->createSubtenant($probeTenant, 'main', 'Main', 'credential_check', []);
    $licensing->assignLicense($probeTenant, 'starter', 'credential_check', gmdate('Y-m-d H:i:s', strtotime('+7 days')), 5);
    $phone = '+7920' . random_int(1000000, 9999999);
    $reg = $registration->register([
        'password' => 'CredentialProbe!123',
        'phone' => $phone,
        'organization_name' => 'Credential Probe Org',
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    assertCheck(($reg['ok'] ?? false) === true, 'Phone-first registration succeeds in probe tenant');
    $tenantId = (string) ($reg['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($reg['tenant']['subtenant_id'] ?? 'main');
    $badPass = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phone, 'password' => 'WrongPassword!000'],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    assertCheck((int) ($badPass['status'] ?? 0) === 401, 'Wrong password returns 401');
    $goodPass = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phone, 'password' => 'CredentialProbe!123'],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    assertCheck(($goodPass['ok'] ?? false) === true, 'Phone login succeeds with correct password');
} catch (Throwable $e) {
    assertCheck(false, 'Credential probe tenant: ' . $e->getMessage());
} finally {
    try {
        $pdo = Connection::get();
        if (isset($phone) && $phone !== '') {
            $pdo->prepare('DELETE FROM maniforge_entity_meta WHERE type = :type AND meta = :meta')
                ->execute([':type' => 'phone', ':meta' => $phone]);
        }
        if (isset($tenantId) && $tenantId !== '') {
            foreach ([
                'DELETE FROM maniforge_users WHERE tenant_id = :t',
                'DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :t',
                'DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :t',
                'DELETE FROM maniforge_tl_tenants WHERE code = :t',
            ] as $sql) {
                $pdo->prepare($sql)->execute([':t' => $tenantId]);
            }
        }
        $pdo->prepare('DELETE FROM maniforge_tl_tenants WHERE code = :t')->execute([':t' => $probeTenant]);
    } catch (Throwable) {
    }
}

fwrite(STDOUT, "\nSummary: ok={$ok}, fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
