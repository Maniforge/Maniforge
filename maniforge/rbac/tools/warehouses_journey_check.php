<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\ProjectService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Warehouses\Repository\StockRepository;
use App\Maniforge\Warehouses\Security\StockService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$ok = 0;
$fail = 0;
$tenantId = '';

function whAssert(bool $cond, string $msg): void
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

try {
    $registration = new RegistrationService();
    $auth = new AuthService();
    $stocks = new StockService();
    $projects = new ProjectService();
    $repo = new StockRepository();

    $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    $phone = '+7920' . random_int(1000000, 9999999);
    $password = 'WhJourney!12345';

    $reg = $registration->register([
        'password' => $password,
        'phone' => $phone,
        'organization_name' => 'WH Journey ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    whAssert(($reg['ok'] ?? false) === true, 'Register tenant for warehouses journey');
    $tenantId = (string) ($reg['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($reg['tenant']['subtenant_id'] ?? 'main');

    $login = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phone, 'password' => $password],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    whAssert(($login['ok'] ?? false) === true, 'Login tenant admin');
    $session = $login['session'] ?? $login['credentials']['session'] ?? [];
    $session['user_id'] = (int) ($reg['user']['id'] ?? 0);
    $session['tenant_id'] = $tenantId;
    $session['subtenant_id'] = $subtenantId;
    whAssert(isset($session['project_id']) && (int) $session['project_id'] > 0, 'Session has default project_id');

    $types = $stocks->listTypes();
    whAssert(count($types['items'] ?? []) >= 5, 'Stock types catalog seeded');

    $wh = $stocks->createStock($session, [
        'code' => 'wh-main-' . $suffix,
        'name' => 'Main Warehouse',
        'type' => 'warehouse',
        'data' => ['city' => 'Moscow', 'address' => 'Test st. 1'],
    ]);
    whAssert(($wh['ok'] ?? false) === true, 'Create root warehouse');
    $whId = (int) (($wh['stock']['id'] ?? 0));

    $zone = $stocks->createStock($session, [
        'code' => 'zone-a-' . $suffix,
        'name' => 'Zone A',
        'type' => 'zone',
        'parent_id' => $whId,
    ]);
    whAssert(($zone['ok'] ?? false) === true, 'Create zone under warehouse');
    $zoneId = (int) (($zone['stock']['id'] ?? 0));

    $proj = $projects->createProject($session, [
        'code' => 'wh-proj-' . $suffix,
        'name' => 'Project with warehouse',
        'warehouse_id' => $whId,
    ]);
    whAssert(($proj['ok'] ?? false) === true, 'Create project with warehouse_id');
    whAssert((int) ($proj['project']['warehouse_id'] ?? 0) === $whId, 'Project stores warehouse_id');
    whAssert(($proj['project']['warehouse']['code'] ?? '') === 'wh-main-' . $suffix, 'Project embeds warehouse summary');

    $badProj = $projects->createProject($session, [
        'code' => 'wh-proj-bad-' . $suffix,
        'name' => 'Bad warehouse bind',
        'warehouse_id' => $zoneId,
    ]);
    whAssert(($badProj['ok'] ?? true) === false, 'Reject project warehouse_id on non-warehouse node');

    $bad = $stocks->createStock($session, [
        'name' => 'Bad cell',
        'type' => 'cell',
        'parent_id' => $whId,
    ]);
    whAssert(($bad['ok'] ?? true) === false, 'Reject cell directly under warehouse');

    $tree = $stocks->tree($session, []);
    whAssert(count($tree['tree'] ?? []) >= 1, 'Tree has root nodes');
    whAssert((int) ($tree['flat_count'] ?? 0) >= 2, 'Tree flat count includes nodes');

    $moveBad = $stocks->updateStock($session, $whId, ['parent_id' => $zoneId]);
    whAssert(($moveBad['ok'] ?? true) === false, 'Reject cycle parent move');

    $archiveWhBlocked = $stocks->archiveStock($session, $whId);
    whAssert(($archiveWhBlocked['ok'] ?? true) === false, 'Cannot archive warehouse with active children');
    whAssert(($archiveWhBlocked['code'] ?? '') === 'has_active_children', 'Archive blocked code has_active_children');

    $archiveZone = $stocks->archiveStock($session, $zoneId);
    whAssert(($archiveZone['ok'] ?? false) === true, 'Archive leaf zone');

    $archiveWh = $stocks->archiveStock($session, $whId);
    whAssert(($archiveWh['ok'] ?? false) === true, 'Archive warehouse after children removed');

    $ext = $stocks->bindExternal($session, $whId, [
        'type' => 'wildberries_fbo',
        'external_id' => 'WB-WH-' . $suffix,
    ]);
    whAssert(($ext['ok'] ?? false) === true, 'Bind external meta on stock');

    $freshWh = $stocks->createStock($session, [
        'code' => 'wh-audit-' . $suffix,
        'name' => 'Audit probe',
        'type' => 'warehouse',
    ]);
    whAssert(($freshWh['ok'] ?? false) === true, 'Create stock for audit probe');
    $auditStockId = (int) (($freshWh['stock']['id'] ?? 0));
    whAssert(isset($freshWh['stock']['created_by_user']['id']), 'Stock response includes created_by_user');
    whAssert(
        (int) ($freshWh['stock']['created_by_user']['id'] ?? 0) === (int) $session['user_id'],
        'created_by_user matches session actor'
    );

    $audit = $stocks->stockAudit($session, $auditStockId, ['limit' => 10]);
    whAssert(($audit['ok'] ?? false) === true, 'Stock audit trail readable');
    whAssert(count($audit['items'] ?? []) >= 1, 'Audit trail has create event');
    whAssert(
        ($audit['items'][0]['event_type'] ?? '') === 'warehouses.stock.created',
        'First audit event is stock.created'
    );
    whAssert(isset($audit['items'][0]['actor_user']['id']), 'Audit item includes actor_user');

    $pdo = \App\Database\Connection::get();
    $grantStmt = $pdo->prepare(
        "SELECT 1 FROM maniforge_tl_tenant_grants
         WHERE status = 'active' AND principal_tenant_code = 'agency-demo' AND managed_tenant_code = 'client-demo'
         LIMIT 1"
    );
    $grantStmt->execute();
    if ($grantStmt->fetch() !== false) {
        $managedLogin = $auth->login(
            ['tenant_id' => 'client-demo', 'subtenant_id' => 'main'],
            ['phone' => '+79000000004', 'password' => 'DemoUser!12345'],
            ['REMOTE_ADDR' => '127.0.0.1']
        );
        whAssert(($managedLogin['ok'] ?? false) === true, 'Login client-admin in client-demo for delegation share');
        if (($managedLogin['ok'] ?? false) === true) {
            $managedSession = $managedLogin['session'] ?? $managedLogin['credentials']['session'] ?? [];
            $managedSession['user_id'] = (int) ($managedSession['user_id'] ?? $managedLogin['user']['id'] ?? 0);
            $shareCode = 'wh-deleg-' . $suffix;
            $shared = $stocks->createStock($managedSession, [
                'code' => $shareCode,
                'name' => 'Shared to principal',
                'type' => 'warehouse',
                'share_with_principal' => true,
            ]);
            whAssert(($shared['ok'] ?? false) === true, 'Create managed stock with share_with_principal');
            $sharedId = (int) (($shared['stock']['id'] ?? 0));

            $principalLogin = $auth->login(
                ['tenant_id' => 'agency-demo', 'subtenant_id' => 'main'],
                ['phone' => '+79000000003', 'password' => 'DemoAdmin!12345'],
                ['REMOTE_ADDR' => '127.0.0.1']
            );
            if (($principalLogin['ok'] ?? false) === true) {
                $principalSession = $principalLogin['session'] ?? $principalLogin['credentials']['session'] ?? [];
                $principalSession['user_id'] = (int) ($principalSession['user_id'] ?? $principalLogin['user']['id'] ?? 0);
                $peers = $stocks->listGrantPeers($principalSession);
                whAssert(($peers['ok'] ?? false) === true, 'Grant peers for admin');
                whAssert(in_array('client-demo', $peers['items'] ?? [], true), 'Principal sees client-demo peer');

                $listed = $stocks->listStocks($principalSession, []);
                $codes = array_map(static fn (array $r): string => (string) ($r['code'] ?? ''), $listed['items'] ?? []);
                whAssert(in_array($shareCode, $codes, true), 'Principal reads managed stock via delegation_share');
                $got = $stocks->getStock($principalSession, $sharedId);
                whAssert(($got['ok'] ?? false) === true, 'Principal GET shared stock by id');
                whAssert(($got['stock']['is_delegated_view'] ?? false) === true, 'Delegated view flag on cross-tenant stock');

                $pdo->prepare('DELETE FROM maniforge_wh_stocks WHERE id = :id')->execute([':id' => $sharedId]);
            }
        }
    }

} catch (Throwable $e) {
    whAssert(false, 'Exception: ' . $e->getMessage());
} finally {
    if ($tenantId !== '') {
        $pdo = \App\Database\Connection::get();
        foreach ([
            'DELETE FROM maniforge_entity_meta WHERE tenant_id = :t',
            'DELETE FROM maniforge_user_project_memberships WHERE tenant_id = :t',
            'DELETE FROM maniforge_projects WHERE tenant_id = :t',
            'DELETE FROM maniforge_wh_stocks WHERE tenant_id = :t',
            'DELETE FROM maniforge_ver_changes WHERE tenant_id = :t',
            'DELETE FROM maniforge_audit_log WHERE tenant_id = :t',
            'DELETE FROM maniforge_users WHERE tenant_id = :t',
            'DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :t',
            'DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :t',
            'DELETE FROM maniforge_tl_tenants WHERE code = :t',
        ] as $sql) {
            try {
                $pdo->prepare($sql)->execute([':t' => $tenantId]);
            } catch (Throwable) {
            }
        }
    }
}

fwrite(STDOUT, "\nWarehouses journey: ok={$ok}, fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
