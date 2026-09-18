<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\ContextService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Rbac\Security\UserOrganizationService;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$ok = 0;
$fail = 0;

function omAssert(bool $c, string $m): void
{
    global $ok, $fail;
    if ($c) {
        $ok++;
        fwrite(STDOUT, "[OK] {$m}\n");
        return;
    }
    $fail++;
    fwrite(STDERR, "[FAIL] {$m}\n");
}

$registration = new RegistrationService();
$organizations = new UserOrganizationService();
$auth = new AuthService();
$contexts = new ContextService();
$licensing = new TenantLicensingRepository();
$pdo = Connection::get();

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$phone = '+7921' . random_int(1000000, 9999999);
$password = 'OrgMember!12345';
$plan = strtolower(trim((string) ($_ENV['RBAC_REGISTRATION_PLAN'] ?? 'starter')));

try {
    $regA = $registration->register([
        'password' => $password,
        'phone' => $phone,
        'organization_name' => 'Org A ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    omAssert(($regA['ok'] ?? false) === true, 'Register company A');
    $tenantA = (string) ($regA['tenant']['tenant_id'] ?? '');
    $subA = (string) ($regA['tenant']['subtenant_id'] ?? 'main');
    $adminA = (int) ($regA['user']['id'] ?? 0);

    $tenantB = 't-b-' . $suffix;
    $licensing->createTenant($tenantB, 'Org B ' . $suffix, 'org_membership_check');
    $licensing->createSubtenant($tenantB, 'main', 'Main', 'org_membership_check');
    $licensing->assignLicense($tenantB, $plan, 'org_membership_check', gmdate('Y-m-d H:i:s', strtotime('+30 days')), 10);

    $loginA = $auth->login(
        ['tenant_id' => $tenantA, 'subtenant_id' => $subA],
        ['phone' => $phone, 'password' => $password],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    omAssert(($loginA['ok'] ?? false) === true, 'Login company A');

    $sessionA = $loginA['session'] ?? $loginA['credentials']['session'] ?? [];
    $sessionA['user_id'] = $adminA;
    $sessionA['tenant_id'] = $tenantA;
    $sessionA['subtenant_id'] = $subA;

    $inviteB = $registration->createUserInvite($tenantB, 'main', $adminA, 'moderator');
    omAssert(($inviteB['ok'] ?? false) === true, 'Company B creates invite');
    $token = (string) ($inviteB['invite_token'] ?? '');

    $attachReg = $registration->register([
        'password' => $password,
        'phone' => $phone,
        'invite_token' => $token,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    omAssert(($attachReg['ok'] ?? false) === true, 'Existing phone joins B via invite+password');
    omAssert(($attachReg['attached'] ?? false) === true, 'Register flags attached=true');
    omAssert((string) ($attachReg['tenant']['tenant_id'] ?? '') === $tenantB, 'Attached to tenant B');

    $ctx = $contexts->contextsForSession($sessionA);
    $tenantIds = array_map(
        static fn (array $r): string => (string) ($r['tenant_id'] ?? ''),
        $ctx['organizations'] ?? []
    );
    omAssert(in_array($tenantB, $tenantIds, true), 'Contexts list includes company B after attach');

    $loginB = $auth->login(
        ['tenant_id' => $tenantB, 'subtenant_id' => 'main'],
        ['phone' => $phone, 'password' => $password],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    omAssert(($loginB['ok'] ?? false) === true, 'Login into company B with same phone');

    $acceptDup = $organizations->acceptInvite($sessionA, $token);
    omAssert(($acceptDup['ok'] ?? true) === false, 'Cannot accept consumed invite');

    $inviteB2 = $registration->createUserInvite($tenantB, 'main', $adminA, 'user');
    $token2 = (string) ($inviteB2['invite_token'] ?? '');
    $accept = $organizations->acceptInvite($sessionA, $token2);
    omAssert(($accept['ok'] ?? true) === false, 'Already member — accept-invite blocked');

} catch (Throwable $e) {
    omAssert(false, 'Exception: ' . $e->getMessage());
} finally {
    foreach ([$tenantA ?? '', $tenantB ?? ''] as $t) {
        if ($t === '') {
            continue;
        }
        foreach ([
            'DELETE FROM maniforge_entity_meta WHERE tenant_id = :t',
            'DELETE FROM maniforge_user_roles WHERE tenant_id = :t',
            'DELETE FROM maniforge_users WHERE tenant_id = :t',
            'DELETE FROM maniforge_registration_invites WHERE tenant_id = :t',
            'DELETE FROM maniforge_audit_log WHERE tenant_id = :t',
            'DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :t',
            'DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :t',
            'DELETE FROM maniforge_tl_tenants WHERE code = :t',
        ] as $sql) {
            try {
                $pdo->prepare($sql)->execute([':t' => $t]);
            } catch (Throwable) {
            }
        }
    }
    try {
        $pdo->prepare('DELETE FROM maniforge_entity_meta WHERE meta = :phone AND type = :type')
            ->execute([':phone' => $phone, ':type' => 'phone']);
    } catch (Throwable) {
    }
}

fwrite(STDOUT, "\nOrganization membership: ok={$ok}, fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
