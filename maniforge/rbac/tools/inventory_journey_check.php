<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Inventory\Security\InventoryService;
use App\Maniforge\Inventory\Support\MovementTypes;
use App\Maniforge\Products\Security\ProductService;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Warehouses\Security\StockService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$ok = 0;
$fail = 0;
$tenantId = '';

function invAssert(bool $cond, string $msg): void
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
    $products = new ProductService();
    $inventory = new InventoryService();

    $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    $phone = '+7922' . random_int(1000000, 9999999);
    $password = 'InvJourney!12345';

    $reg = $registration->register([
        'password' => $password,
        'phone' => $phone,
        'organization_name' => 'Inventory Journey ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    invAssert(($reg['ok'] ?? false) === true, 'Register tenant');
    $tenantId = (string) ($reg['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($reg['tenant']['subtenant_id'] ?? 'main');

    $login = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phone, 'password' => $password],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    invAssert(($login['ok'] ?? false) === true, 'Login');
    $session = $login['session'] ?? $login['credentials']['session'] ?? [];
    $session['user_id'] = (int) ($session['user_id'] ?? $reg['user']['id'] ?? 0);

    $wh = $stocks->createStock($session, [
        'code' => 'wh-inv-' . $suffix,
        'name' => 'WH Inv',
        'type' => 'warehouse',
    ]);
    invAssert(($wh['ok'] ?? false) === true, 'Create warehouse');
    $whId = (int) (($wh['stock']['id'] ?? 0));

    $zone = $stocks->createStock($session, [
        'code' => 'zone-inv-' . $suffix,
        'name' => 'Zone Inv',
        'type' => 'zone',
        'parent_id' => $whId,
    ]);
    invAssert(($zone['ok'] ?? false) === true, 'Create zone');
    $zoneId = (int) (($zone['stock']['id'] ?? 0));

    $prod = $products->createProduct($session, [
        'code' => 'sku-inv-' . $suffix,
        'name' => 'SKU Inv',
        'unit' => 'pcs',
    ]);
    invAssert(($prod['ok'] ?? false) === true, 'Create product');
    $productId = (int) (($prod['product']['id'] ?? 0));

    $receipt = $inventory->postMovement($session, [
        'movement_type' => MovementTypes::RECEIPT,
        'product_id' => $productId,
        'stock_id' => $zoneId,
        'qty' => '100',
        'doc_number' => 'rcv-' . $suffix,
    ]);
    invAssert(($receipt['ok'] ?? false) === true, 'Receipt +100');
    invAssert(($receipt['movement']['movement_type'] ?? '') === MovementTypes::RECEIPT, 'Movement type receipt');

    $bal = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    invAssert(bccomp((string) (($bal['items'][0]['qty'] ?? '0')), '100', 6) === 0, 'Balance qty 100');

    $issue = $inventory->postMovement($session, [
        'movement_type' => MovementTypes::ISSUE,
        'product_id' => $productId,
        'stock_id' => $zoneId,
        'qty' => '30',
    ]);
    invAssert(($issue['ok'] ?? false) === true, 'Issue -30');
    $bal2 = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    invAssert(bccomp((string) (($bal2['items'][0]['qty'] ?? '0')), '70', 6) === 0, 'Balance qty 70 after issue');

    $zone2 = $stocks->createStock($session, [
        'code' => 'zone2-inv-' . $suffix,
        'name' => 'Zone B',
        'type' => 'zone',
        'parent_id' => $whId,
    ]);
    invAssert(($zone2['ok'] ?? false) === true, 'Create second zone for transfer');
    $zone2Id = (int) (($zone2['stock']['id'] ?? 0));

    $xfer = $inventory->postMovement($session, [
        'movement_type' => MovementTypes::TRANSFER,
        'product_id' => $productId,
        'from_stock_id' => $zoneId,
        'to_stock_id' => $zone2Id,
        'qty' => '20',
    ]);
    invAssert(($xfer['ok'] ?? false) === true, 'Transfer 20 zone→zone');
    $balZone = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    invAssert(bccomp((string) (($balZone['items'][0]['qty'] ?? '0')), '50', 6) === 0, 'Zone A balance 50');
    $balZone2 = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zone2Id]);
    invAssert(bccomp((string) (($balZone2['items'][0]['qty'] ?? '0')), '20', 6) === 0, 'Zone B balance 20');

    $over = $inventory->postMovement($session, [
        'movement_type' => MovementTypes::ISSUE,
        'product_id' => $productId,
        'stock_id' => $zoneId,
        'qty' => '999',
    ]);
    invAssert(($over['ok'] ?? true) === false, 'Reject issue over balance');
    invAssert(($over['code'] ?? '') === 'insufficient_qty', 'insufficient_qty code');

    $adj = $inventory->postMovement($session, [
        'movement_type' => MovementTypes::ADJUSTMENT,
        'product_id' => $productId,
        'stock_id' => $zone2Id,
        'qty_after' => '25',
    ]);
    invAssert(($adj['ok'] ?? false) === true, 'Adjustment zone B to 25');

    $movList = $inventory->listMovements($session, ['limit' => 10]);
    invAssert(count($movList['items'] ?? []) >= 4, 'List movements');

    $draft = $inventory->postMovement($session, [
        'movement_type' => MovementTypes::RECEIPT,
        'product_id' => $productId,
        'stock_id' => $zoneId,
        'qty' => '5',
        'status' => 'draft',
        'doc_number' => 'draft-' . $suffix,
    ]);
    invAssert(($draft['ok'] ?? false) === true, 'Create movement draft');
    invAssert(($draft['movement']['status'] ?? '') === 'draft', 'Draft status');
    $draftId = (int) ($draft['movement']['id'] ?? 0);

    $balDraft = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    invAssert(bccomp((string) (($balDraft['items'][0]['qty'] ?? '0')), '50', 6) === 0, 'Draft does not change balance');

    $postedDraft = $inventory->postDraftMovement($session, $draftId);
    invAssert(($postedDraft['ok'] ?? false) === true, 'Post draft movement');
    $balAfterDraft = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    invAssert(bccomp((string) (($balAfterDraft['items'][0]['qty'] ?? '0')), '55', 6) === 0, 'Balance +5 after draft post');

    $lot = $inventory->registerLot($session, [
        'product_id' => $productId,
        'batch_code' => 'B-J-' . $suffix,
        'lot_code' => 'L-J-' . $suffix,
    ]);
    invAssert(($lot['ok'] ?? false) === true, 'Register lot');
    $lotId = (int) (($lot['lot']['id'] ?? 0));

    $order = $inventory->createOrder($session, [
        'order_number' => 'so-' . $suffix,
        'stock_id' => $zoneId,
        'lines' => [['product_id' => $productId, 'qty' => '10']],
    ]);
    invAssert(($order['ok'] ?? false) === true, 'Create warehouse order');
    $orderId = (int) (($order['order']['id'] ?? 0));

    $confirmed = $inventory->confirmOrder($session, $orderId);
    invAssert(($confirmed['ok'] ?? false) === true, 'Confirm order reserves');
    $balReserved = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    invAssert(bccomp((string) (($balReserved['items'][0]['qty_available'] ?? '0')), '45', 6) === 0, 'Available 45 after order reserve');

    $fulfilled = $inventory->fulfillOrder($session, $orderId);
    invAssert(($fulfilled['ok'] ?? false) === true, 'Fulfill order issue');
    $balFulfilled = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    invAssert(bccomp((string) (($balFulfilled['items'][0]['qty'] ?? '0')), '45', 6) === 0, 'On hand 45 after fulfill');

    $prodDetail = $products->getProduct($session, $productId, ['include' => 'balances']);
    invAssert(isset($prodDetail['product']['balances']), 'Product include=balances');

} catch (Throwable $e) {
    invAssert(false, 'Exception: ' . $e->getMessage());
} finally {
    if ($tenantId !== '') {
        $pdo = \App\Database\Connection::get();
        foreach ([
            'DELETE FROM maniforge_inv_order_lines WHERE order_id IN (SELECT id FROM maniforge_inv_orders WHERE tenant_id = :t)',
            'DELETE FROM maniforge_inv_orders WHERE tenant_id = :t',
            'DELETE FROM maniforge_inv_reserves WHERE tenant_id = :t',
            'DELETE FROM maniforge_inv_lots WHERE tenant_id = :t',
            'DELETE FROM maniforge_inv_movement_lines WHERE movement_id IN (SELECT id FROM maniforge_inv_movements WHERE tenant_id = :t)',
            'DELETE FROM maniforge_inv_movements WHERE tenant_id = :t',
            'DELETE FROM maniforge_inv_balances WHERE tenant_id = :t',
            'DELETE FROM maniforge_entity_meta WHERE tenant_id = :t',
            'DELETE FROM maniforge_products WHERE tenant_id = :t',
            'DELETE FROM maniforge_wh_stocks WHERE tenant_id = :t',
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

fwrite(STDOUT, "\nInventory journey: ok={$ok}, fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
