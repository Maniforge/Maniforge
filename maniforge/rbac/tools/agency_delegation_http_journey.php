<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';
require_once __DIR__ . '/journey_http_common.php';

use App\Database\Connection;

function agencyDelegationUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/agency_delegation_http_journey.php [--help]\n");
    fwrite(STDOUT, "Requires demo_seed (agency-demo / client-demo). Env:\n");
    fwrite(STDOUT, "  JOURNEY_BASE_URL=http://127.0.0.1:8093/rbac\n");
    fwrite(STDOUT, "  Go: make rbac-delegation-journey (runs bin/maniforge-agency-demo-seed first)\n");
    fwrite(STDOUT, "  MANIFORGE_DEMO_ADMIN_PASSWORD=DemoAdmin!12345\n");
}

if (in_array('--help', $argv, true)) {
    agencyDelegationUsage();
    exit(0);
}

$rbacBase = rtrim(journeyEnv('JOURNEY_BASE_URL', journeyEnv('NEW_USER_BASE_URL', 'http://127.0.0.1:8093/rbac')), '/');
$principalCode = 'agency-demo';
$managedCode = 'client-demo';
$principalSub = 'main';
$managedSub = 'main';
$adminLogin = 'agency-admin';
$adminPassword = journeyEnv('MANIFORGE_DEMO_ADMIN_PASSWORD', 'DemoAdmin!12345');
$adminPhone = '+79000000003';

$assert = new JourneyHttpAsserts();
$cookies = [];

try {
    $seedBin = dirname(__DIR__, 3) . '/bin/maniforge-agency-demo-seed';
    if (is_file($seedBin) && is_executable($seedBin)) {
        fwrite(STDOUT, "Running maniforge-agency-demo-seed (PostgreSQL)...\n");
        passthru(escapeshellarg($seedBin), $seedExit);
        if ($seedExit !== 0) {
            throw new RuntimeException('maniforge-agency-demo-seed failed');
        }
    } else {
        Connection::get();
        $tenantStmt = Connection::get()->prepare('SELECT code FROM maniforge_tl_tenants WHERE code = :code LIMIT 1');
        $tenantStmt->execute([':code' => $principalCode]);
        if (!is_array($tenantStmt->fetch())) {
            fwrite(STDOUT, "Missing {$principalCode}, running demo_seed.php (MySQL)...\n");
            $seedPath = __DIR__ . '/demo_seed.php';
            passthru(escapeshellarg(PHP_BINARY !== '' ? PHP_BINARY : 'php') . ' ' . escapeshellarg($seedPath), $seedExit);
            if ($seedExit !== 0) {
                throw new RuntimeException('demo_seed.php failed');
            }
        }

        $userStmt = Connection::get()->prepare(
            'SELECT phone FROM maniforge_users WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id AND login = :login LIMIT 1'
        );
        $userStmt->execute([
            ':tenant_id' => $principalCode,
            ':subtenant_id' => $principalSub,
            ':login' => $adminLogin,
        ]);
        $userRow = $userStmt->fetch();
        if (is_array($userRow) && trim((string) ($userRow['phone'] ?? '')) !== '') {
            $adminPhone = trim((string) $userRow['phone']);
        }
    }

    $principalHeaders = [
        'X-Tenant-ID: ' . $principalCode,
        'X-Subtenant-ID: ' . $principalSub,
    ];

    $health = journeyHttp('GET', $rbacBase . '/health', [], null, $cookies);
    $assert->assertStatus($health, 200, 'RBAC health');

    $login = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $principalHeaders, [
        'phone' => $adminPhone,
        'password' => $adminPassword,
        'tenant_id' => $principalCode,
        'subtenant_id' => $principalSub,
    ], $cookies);
    $assert->assertStatus($login, 200, 'Agency admin login by phone');
    [$accessToken, $authHeaders] = journeyAuthFromLogin($login['body'], $principalHeaders);
    $assert->assertTrue($accessToken !== '', 'Access token received');
    $sessionHeaders = array_merge($principalHeaders, ['Authorization: Bearer ' . $accessToken]);

    $meBefore = journeyHttp('GET', $rbacBase . '/api/v1/me', $sessionHeaders, null, $cookies);
    $assert->assertStatus($meBefore, 200, 'GET /me in principal tenant');
    $assert->assertSame($principalCode, (string) ($meBefore['body']['session']['tenant_id'] ?? ''), 'Session starts in agency-demo');

    $contexts = journeyHttp('GET', $rbacBase . '/api/v1/me/contexts', $sessionHeaders, null, $cookies);
    $assert->assertStatus($contexts, 200, 'GET /me/contexts');
    $hasDelegated = false;
    foreach ($contexts['body']['delegated'] ?? [] as $entry) {
        if (
            is_array($entry)
            && (string) ($entry['tenant_id'] ?? '') === $managedCode
            && (string) ($entry['subtenant_id'] ?? '') === $managedSub
        ) {
            $hasDelegated = true;
            $assert->assertSame('operator', (string) ($entry['grant_level'] ?? ''), 'Delegated grant_level is operator');
        }
    }
    $assert->assertTrue($hasDelegated, 'Delegated contexts include client-demo/main');

    $switch = journeyHttp('POST', $rbacBase . '/api/v1/auth/switch-context', $authHeaders, [
        'tenant_id' => $managedCode,
        'subtenant_id' => $managedSub,
    ], $cookies);
    $assert->assertStatus($switch, 200, 'POST switch-context to client-demo');
    $assert->assertTrue((bool) ($switch['body']['ok'] ?? false), 'Switch-context ok=true');

    $managedHeaders = [
        'Authorization: Bearer ' . $accessToken,
    ];

    $meAfter = journeyHttp('GET', $rbacBase . '/api/v1/me', $managedHeaders, null, $cookies);
    $assert->assertStatus($meAfter, 200, 'GET /me after context switch');
    $assert->assertSame($managedCode, (string) ($meAfter['body']['session']['tenant_id'] ?? ''), 'Session tenant is client-demo');
    $assert->assertSame('delegated', (string) ($meAfter['body']['session']['kind'] ?? ''), 'Session kind is delegated');
    $assert->assertTrue((bool) ($meAfter['body']['session']['delegated'] ?? false), 'Session delegated flag is true');

    $projects = journeyHttp('GET', $rbacBase . '/api/v1/projects', $managedHeaders, null, $cookies);
    $projectStatus = (int) ($projects['status'] ?? 0);
    $assert->assertTrue(
        in_array($projectStatus, [200, 403], true),
        'List projects in managed tenant (200 or 403 for operator policy)'
    );
    if ($projectStatus === 200) {
        $assert->assertTrue((bool) ($projects['body']['ok'] ?? false), 'Projects list ok=true');
    }

    $access = journeyHttp('GET', $rbacBase . '/api/v1/me/access', $managedHeaders, null, $cookies);
    $assert->assertStatus($access, 200, 'GET /me/access in managed tenant');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in agency delegation HTTP journey');
}

$assert->summary('Agency delegation HTTP journey');
exit($assert->hasFailures() ? 1 : 0);
