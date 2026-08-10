<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\EntityMetaRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Security\PasswordService;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function demoEnv(string $key, string $default): string
{
    $value = trim((string) ($_ENV[$key] ?? ''));

    return $value === '' ? $default : $value;
}

function ensureDemoUser(
    UserRepository $users,
    PasswordService $passwords,
    string $tenantCode,
    string $subtenantCode,
    string $login,
    string $email,
    string $phone,
    string $password,
    string $roleCode,
    bool $mfaRequired
): array {
    $existing = $users->findByLogin($tenantCode, $subtenantCode, $login);
    if ($existing === null) {
        $user = $users->createUser(
            $tenantCode,
            $subtenantCode,
            $login,
            $email,
            $phone,
            $passwords->hash($password),
            $mfaRequired,
            'active'
        );
    } else {
        $user = $users->updateUserInScope(
            (int) $existing['id'],
            $tenantCode,
            $subtenantCode,
            [
                'email' => $email,
                'phone' => $phone,
                'password_hash' => $passwords->hash($password),
                'mfa_required' => $mfaRequired,
                'status' => 'active',
            ]
        ) ?? $existing;
    }

    assignRole((int) $user['id'], $tenantCode, $subtenantCode, $roleCode);
    (new EntityMetaRepository())->rebindPhoneForUser(
        $phone,
        (int) $user['id'],
        $tenantCode,
        $subtenantCode,
    );

    return $user;
}

function assignRole(int $userId, string $tenantCode, string $subtenantCode, string $roleCode): void
{
    $pdo = Connection::get();
    $roleStmt = $pdo->prepare('SELECT id FROM maniforge_roles WHERE code = :code LIMIT 1');
    $roleStmt->execute([':code' => $roleCode]);
    $role = $roleStmt->fetch();
    if (!is_array($role)) {
        throw new RuntimeException("Role not found: {$roleCode}");
    }

    $exists = $pdo->prepare(
        'SELECT id
         FROM maniforge_user_roles
         WHERE user_id = :user_id
           AND role_id = :role_id
           AND tenant_id = :tenant_id
           AND subtenant_id = :subtenant_id
           AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
         LIMIT 1'
    );
    $exists->execute([
        ':user_id' => $userId,
        ':role_id' => (int) $role['id'],
        ':tenant_id' => $tenantCode,
        ':subtenant_id' => $subtenantCode,
    ]);
    if (is_array($exists->fetch())) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO maniforge_user_roles (user_id, role_id, tenant_id, subtenant_id, assigned_by)
         VALUES (:user_id, :role_id, :tenant_id, :subtenant_id, :assigned_by)'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':role_id' => (int) $role['id'],
        ':tenant_id' => $tenantCode,
        ':subtenant_id' => $subtenantCode,
        ':assigned_by' => $userId,
    ]);
}

$tenantCode = strtolower(demoEnv('MANIFORGE_DEMO_TENANT', 'demo'));
$subtenantCode = strtolower(demoEnv('MANIFORGE_DEMO_SUBTENANT', 'main'));
$planCode = strtolower(demoEnv('MANIFORGE_DEMO_PLAN', 'starter'));
$adminLogin = demoEnv('MANIFORGE_DEMO_ADMIN_LOGIN', 'demo-admin');
$adminPassword = demoEnv('MANIFORGE_DEMO_ADMIN_PASSWORD', 'DemoAdmin!12345');
$userLogin = demoEnv('MANIFORGE_DEMO_USER_LOGIN', 'demo-user');
$userPassword = demoEnv('MANIFORGE_DEMO_USER_PASSWORD', 'DemoUser!12345');
$seatsMax = max(2, (int) demoEnv('MANIFORGE_DEMO_SEATS_MAX', '25'));
$actor = 'demo_seed';

try {
    $licensing = new TenantLicensingRepository();
    $users = new UserRepository();
    $passwords = new PasswordService();

    $tenant = $licensing->createTenant($tenantCode, 'Demo Tenant', $actor, ['source' => 'demo_seed']);
    if (($tenant['ok'] ?? false) !== true && (int) ($tenant['status'] ?? 0) !== 409) {
        throw new RuntimeException((string) ($tenant['error'] ?? 'tenant create failed'));
    }
    if ((int) ($tenant['status'] ?? 0) === 409) {
        $licensing->updateTenant($tenantCode, ['name' => 'Demo Tenant', 'status' => 'active'], $actor);
    }

    $subtenant = $licensing->createSubtenant($tenantCode, $subtenantCode, 'Demo Workspace', $actor, ['source' => 'demo_seed']);
    if (($subtenant['ok'] ?? false) !== true && (int) ($subtenant['status'] ?? 0) !== 409) {
        throw new RuntimeException((string) ($subtenant['error'] ?? 'subtenant create failed'));
    }
    if ((int) ($subtenant['status'] ?? 0) === 409) {
        $licensing->updateSubtenant($tenantCode, $subtenantCode, ['name' => 'Demo Workspace', 'status' => 'active'], $actor);
    }

    $licensing->upsertPlan(
        $planCode,
        ucfirst($planCode),
        'active',
        ['rbac' => true, 'admin_api' => true, 'tenant_admin' => true],
        ['max_users' => $seatsMax, 'max_sessions' => $seatsMax * 10],
        $actor
    );

    foreach ([
        'free' => ['name' => 'Free', 'max_users' => 10, 'max_sessions' => 50, 'max_subtenants' => 0, 'max_tenants' => 1],
        'starter' => ['name' => 'Starter', 'max_users' => 25, 'max_sessions' => 250, 'max_subtenants' => 1, 'max_tenants' => 1],
        'business' => ['name' => 'Business', 'max_users' => 250, 'max_sessions' => 2500, 'max_subtenants' => 10, 'max_tenants' => 1],
        'enterprise' => ['name' => 'Enterprise', 'max_users' => 10000, 'max_sessions' => 50000, 'max_subtenants' => 100, 'max_tenants' => 1],
        'operator' => ['name' => 'Operator', 'max_users' => 500, 'max_sessions' => 5000, 'max_subtenants' => 50, 'max_tenants' => 25],
    ] as $code => $meta) {
        $licensing->upsertPlan(
            $code,
            $meta['name'],
            'active',
            ['rbac' => true, 'admin_api' => true, 'tenant_admin' => true],
            [
                'max_users' => $meta['max_users'],
                'max_sessions' => $meta['max_sessions'],
                'max_subtenants' => $meta['max_subtenants'],
                'max_tenants' => $meta['max_tenants'],
            ],
            $actor
        );
    }

    $licensing->assignLicense($tenantCode, $planCode, $actor, gmdate('Y-m-d H:i:s', strtotime('+90 days')), $seatsMax);

    $admin = ensureDemoUser(
        $users,
        $passwords,
        $tenantCode,
        $subtenantCode,
        $adminLogin,
        $adminLogin . '@example.test',
        '+79000000001',
        $adminPassword,
        'tenant_admin',
        true
    );
    $user = ensureDemoUser(
        $users,
        $passwords,
        $tenantCode,
        $subtenantCode,
        $userLogin,
        $userLogin . '@example.test',
        '+79000000002',
        $userPassword,
        'user',
        false
    );

    fwrite(STDOUT, "Demo seed ready.\n");
    fwrite(STDOUT, "Tenant scope: {$tenantCode} / {$subtenantCode}\n");
    fwrite(STDOUT, "Plan/license: {$planCode}, seats_max={$seatsMax}\n");
    fwrite(STDOUT, "Tenant admin: {$adminLogin} / {$adminPassword} (user_id={$admin['id']})\n");
    fwrite(STDOUT, "Demo user: {$userLogin} / {$userPassword} (user_id={$user['id']})\n");
    fwrite(STDOUT, "Use headers: X-Tenant-ID={$tenantCode}, X-Subtenant-ID={$subtenantCode}\n");

    // Пример кодов principal/managed (имена условные; подходят и для MSP, и для интегратора).
    $agencyCode = 'agency-demo';
    $clientCode = 'client-demo';
    $agencySub = 'main';
    $clientSub = 'main';

    foreach ([
        [$agencyCode, 'Agency Demo', $agencySub, 'Agency Workspace'],
        [$clientCode, 'Client Demo', $clientSub, 'Client Workspace'],
    ] as [$code, $name, $subCode, $subName]) {
        $t = $licensing->createTenant($code, $name, $actor, ['source' => 'demo_seed', 'kind' => $code === $agencyCode ? 'agency' : 'client']);
        if (($t['ok'] ?? false) !== true && (int) ($t['status'] ?? 0) !== 409) {
            throw new RuntimeException((string) ($t['error'] ?? "tenant {$code} create failed"));
        }
        if ((int) ($t['status'] ?? 0) === 409) {
            $licensing->updateTenant($code, ['name' => $name, 'status' => 'active'], $actor);
        }
        $st = $licensing->createSubtenant($code, $subCode, $subName, $actor, ['source' => 'demo_seed']);
        if (($st['ok'] ?? false) !== true && (int) ($st['status'] ?? 0) !== 409) {
            throw new RuntimeException((string) ($st['error'] ?? "subtenant {$code}/{$subCode} create failed"));
        }
        if ((int) ($st['status'] ?? 0) === 409) {
            $licensing->updateSubtenant($code, $subCode, ['name' => $subName, 'status' => 'active'], $actor);
        }
        $planForTenant = $code === $agencyCode ? 'operator' : 'business';
        $licensing->assignLicense($code, $planForTenant, $actor, gmdate('Y-m-d H:i:s', strtotime('+90 days')), $seatsMax);
    }

    $grant = $licensing->createManagedTenantGrant(
        $agencyCode,
        $clientCode,
        'operator',
        $actor,
        ['note_152fz' => 'demo cross-tenant delegation', 'contract' => 'DEMO-MSP-001']
    );
    if (($grant['ok'] ?? false) !== true && (int) ($grant['status'] ?? 0) !== 409) {
        throw new RuntimeException((string) ($grant['error'] ?? 'agency grant failed'));
    }

    $agencyAdmin = ensureDemoUser(
        $users,
        $passwords,
        $agencyCode,
        $agencySub,
        'agency-admin',
        'agency-admin@example.test',
        '+79000000003',
        $adminPassword,
        'tenant_admin',
        true
    );
    $clientAdmin = ensureDemoUser(
        $users,
        $passwords,
        $clientCode,
        $clientSub,
        'client-admin',
        'client-admin@example.test',
        '+79000000004',
        $userPassword,
        'tenant_admin',
        false
    );

    fwrite(STDOUT, "\nTenant delegation demo (example codes):\n");
    fwrite(STDOUT, "  Principal: {$agencyCode} / {$agencySub} — agency-admin / {$adminPassword} (user_id={$agencyAdmin['id']})\n");
    fwrite(STDOUT, "  Managed: {$clientCode} / {$clientSub} — client-admin / {$userPassword} (user_id={$clientAdmin['id']})\n");
    fwrite(STDOUT, "  Grant: {$agencyCode} -> {$clientCode} (operator)\n");
    fwrite(STDOUT, "  UI: /operator — login as agency-admin, then switch to client-demo/main\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Demo seed failed: " . $e->getMessage() . "\n");
    exit(1);
}
