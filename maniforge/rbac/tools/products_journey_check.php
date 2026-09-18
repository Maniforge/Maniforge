<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Products\Security\ProductService;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\RegistrationService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$ok = 0;
$fail = 0;
$tenantId = '';

function prAssert(bool $cond, string $msg): void
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
    $products = new ProductService();

    $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    $phone = '+7921' . random_int(1000000, 9999999);
    $password = 'PrJourney!12345';

    $reg = $registration->register([
        'password' => $password,
        'phone' => $phone,
        'organization_name' => 'Products Journey ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    prAssert(($reg['ok'] ?? false) === true, 'Register tenant for products journey');
    $tenantId = (string) ($reg['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($reg['tenant']['subtenant_id'] ?? 'main');

    $login = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phone, 'password' => $password],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    prAssert(($login['ok'] ?? false) === true, 'Login tenant admin');
    $session = $login['session'] ?? $login['credentials']['session'] ?? [];
    $session['user_id'] = (int) ($session['user_id'] ?? $reg['user']['id'] ?? 0);
    prAssert(isset($session['project_id']) && (int) $session['project_id'] > 0, 'Session has project_id');

    $created = $products->createProduct($session, [
        'code' => 'sku-main-' . $suffix,
        'name' => 'Product Alpha',
        'unit' => 'pcs',
        'attributes' => ['brand' => 'Maniforge'],
    ]);
    prAssert(($created['ok'] ?? false) === true, 'Create product in project scope');
    $productId = (int) (($created['product']['id'] ?? 0));
    prAssert((string) ($created['product']['scope_visibility'] ?? '') === 'project', 'Default scope_visibility project');

    $dup = $products->createProduct($session, [
        'code' => 'sku-main-' . $suffix,
        'name' => 'Duplicate',
    ]);
    prAssert(($dup['ok'] ?? true) === false, 'Reject duplicate code in scope');

    $listed = $products->listProducts($session, []);
    $codes = array_map(static fn (array $r): string => (string) ($r['code'] ?? ''), $listed['items'] ?? []);
    prAssert(in_array('sku-main-' . $suffix, $codes, true), 'List includes created product');

    $patched = $products->updateProduct($session, $productId, ['name' => 'Product Alpha Updated']);
    prAssert(($patched['ok'] ?? false) === true, 'PATCH product name');
    prAssert(($patched['product']['name'] ?? '') === 'Product Alpha Updated', 'Name updated');

    $ext = $products->bindExternal($session, $productId, [
        'type' => 'wildberries',
        'external_id' => 'WB-SKU-' . $suffix,
    ]);
    prAssert(($ext['ok'] ?? false) === true, 'Bind external meta on product');

    $archived = $products->archiveProduct($session, $productId);
    prAssert(($archived['ok'] ?? false) === true, 'Archive product');
    prAssert(($archived['product']['status'] ?? '') === 'archived', 'Status archived');

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
        prAssert(($managedLogin['ok'] ?? false) === true, 'Login client-admin for product delegation');
        if (($managedLogin['ok'] ?? false) === true) {
            $managedSession = $managedLogin['session'] ?? $managedLogin['credentials']['session'] ?? [];
            $managedSession['user_id'] = (int) ($managedSession['user_id'] ?? $managedLogin['user']['id'] ?? 0);
            $shareCode = 'sku-deleg-' . $suffix;
            $shared = $products->createProduct($managedSession, [
                'code' => $shareCode,
                'name' => 'Shared product',
                'share_with_principal' => true,
            ]);
            prAssert(($shared['ok'] ?? false) === true, 'Create managed product with share_with_principal');
            $sharedId = (int) (($shared['product']['id'] ?? 0));

            $principalLogin = $auth->login(
                ['tenant_id' => 'agency-demo', 'subtenant_id' => 'main'],
                ['phone' => '+79000000003', 'password' => 'DemoAdmin!12345'],
                ['REMOTE_ADDR' => '127.0.0.1']
            );
            if (($principalLogin['ok'] ?? false) === true) {
                $principalSession = $principalLogin['session'] ?? $principalLogin['credentials']['session'] ?? [];
                $principalSession['user_id'] = (int) ($principalSession['user_id'] ?? $principalLogin['user']['id'] ?? 0);
                $peers = $products->listGrantPeers($principalSession);
                prAssert(($peers['ok'] ?? false) === true, 'Product grant peers');
                $listP = $products->listProducts($principalSession, ['status' => 'active']);
                $pCodes = array_map(static fn (array $r): string => (string) ($r['code'] ?? ''), $listP['items'] ?? []);
                prAssert(in_array($shareCode, $pCodes, true), 'Principal reads shared product');
                $got = $products->getProduct($principalSession, $sharedId);
                prAssert(($got['product']['is_delegated_view'] ?? false) === true, 'Delegated view flag on product');

                $pdo->prepare('DELETE FROM maniforge_products WHERE id = :id')->execute([':id' => $sharedId]);
            }
        }
    }

} catch (Throwable $e) {
    prAssert(false, 'Exception: ' . $e->getMessage());
} finally {
    if ($tenantId !== '') {
        $pdo = \App\Database\Connection::get();
        foreach ([
            'DELETE FROM maniforge_entity_meta WHERE tenant_id = :t',
            'DELETE FROM maniforge_products WHERE tenant_id = :t',
            'DELETE FROM maniforge_user_project_memberships WHERE tenant_id = :t',
            'DELETE FROM maniforge_projects WHERE tenant_id = :t',
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

fwrite(STDOUT, "\nProducts journey: ok={$ok}, fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
