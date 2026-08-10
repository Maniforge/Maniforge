<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Inventory\Security\InventoryService;
use App\Maniforge\Inventory\Support\MovementTypes;
use App\Maniforge\Products\Security\ProductService;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Warehouses\Security\StockService;
use App\Maniforge\Wms\Security\WmsService;
use App\Maniforge\Wms\Support\PackUnitTypes;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ok = 0;
$fail = 0;

function scAssert(bool $c, string $m): void
{
    global $ok, $fail;
    if ($c) {
        $ok++;
        fwrite(STDOUT, "[OK] {$m}\n");
    } else {
        $fail++;
        fwrite(STDERR, "[FAIL] {$m}\n");
    }
}

try {
    $regSvc = new RegistrationService();
    $auth = new AuthService();
    $stocks = new StockService();
    $products = new ProductService();
    $inv = new InventoryService();
    $wms = new WmsService();

    $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    $phone = '+7924' . random_int(1000000, 9999999);
    $reg = $regSvc->register([
        'password' => 'ScGrowth!12345',
        'phone' => $phone,
        'organization_name' => 'SC Growth ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    scAssert(($reg['ok'] ?? false) === true, 'Register');

    $tenantId = (string) ($reg['tenant']['tenant_id'] ?? '');
    $login = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => 'main'],
        ['phone' => $phone, 'password' => 'ScGrowth!12345'],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    $session = $login['session'] ?? $login['credentials']['session'] ?? [];
    $session['user_id'] = (int) ($session['user_id'] ?? $reg['user']['id'] ?? 0);

    $ov = $inv->supplyChainOverview($session);
    scAssert(($ov['ok'] ?? false) === true && isset($ov['overview']['products_active']), 'Supply chain overview');

    $wh = $stocks->createStock($session, ['code' => 'wh-sc-' . $suffix, 'name' => 'WH', 'type' => 'warehouse']);
    $whId = (int) ($wh['stock']['id'] ?? 0);
    $z1 = $stocks->createStock($session, ['code' => 'z1-' . $suffix, 'name' => 'Z1', 'type' => 'zone', 'parent_id' => $whId]);
    $z2 = $stocks->createStock($session, ['code' => 'z2-' . $suffix, 'name' => 'Z2', 'type' => 'zone', 'parent_id' => $whId]);
    $z1Id = (int) ($z1['stock']['id'] ?? 0);
    $z2Id = (int) ($z2['stock']['id'] ?? 0);

    $prod = $products->createProduct($session, ['code' => 'sku-sc-' . $suffix, 'name' => 'SKU', 'unit' => 'pcs']);
    $pid = (int) ($prod['product']['id'] ?? 0);

    $inv->postMovement($session, [
        'movement_type' => MovementTypes::RECEIPT,
        'product_id' => $pid,
        'stock_id' => $z1Id,
        'qty' => '50',
        'batch_code' => 'B-' . $suffix,
        'lot_code' => 'L-' . $suffix,
    ]);
    scAssert(true, 'Receipt with batch/lot');

    $bal = $inv->listBalances($session, ['product_id' => $pid, 'stock_id' => $z1Id]);
    scAssert(isset($bal['items'][0]['qty_available']), 'Balance has qty_available');
    scAssert(bccomp((string) ($bal['items'][0]['qty'] ?? '0'), '50', 6) === 0, 'On hand 50');

    $res = $inv->createReserve($session, [
        'product_id' => $pid,
        'stock_id' => $z1Id,
        'qty' => '30',
        'ref_code' => 'ord-' . $suffix,
    ]);
    scAssert(($res['ok'] ?? false) === true, 'Create reserve 30');

    $bal2 = $inv->listBalances($session, ['product_id' => $pid, 'stock_id' => $z1Id]);
    scAssert(bccomp((string) ($bal2['items'][0]['qty_available'] ?? '0'), '20', 6) === 0, 'Available 20 after reserve');

    $blocked = $inv->postMovement($session, [
        'movement_type' => MovementTypes::ISSUE,
        'product_id' => $pid,
        'stock_id' => $z1Id,
        'qty' => '25',
    ]);
    scAssert(($blocked['ok'] ?? true) === false, 'Issue blocked by reserve');

    $sum = $inv->balancesSummary($session, ['product_id' => $pid]);
    scAssert(count($sum['items'] ?? []) >= 1, 'Product balance summary');

    $kiz = $wms->registerMarking($session, [
        'product_id' => $pid,
        'code' => '(01)0460000000999(21)SC' . $suffix,
    ]);
    $kizId = (int) ($kiz['marking']['id'] ?? 0);

    $wms->postMovementByScan($session, [
        'movement_type' => MovementTypes::RECEIPT,
        'stock_id' => $z1Id,
        'scan' => '(01)0460000000999(21)SC' . $suffix,
    ]);
    scAssert(true, 'KIZ receipt');

    $trace = $wms->traceMarking($session, $kizId);
    scAssert(count($trace['movements'] ?? []) >= 1, 'Marking trace has movements');

    $xfer = $wms->postMovementByScan($session, [
        'movement_type' => MovementTypes::TRANSFER,
        'from_stock_id' => $z1Id,
        'to_stock_id' => $z2Id,
        'scan' => '(01)0460000000999(21)SC' . $suffix,
    ]);
    scAssert(($xfer['ok'] ?? false) === true, 'KIZ transfer by scan');

    $balZ2 = $inv->listBalances($session, ['product_id' => $pid, 'stock_id' => $z2Id]);
    scAssert(bccomp((string) ($balZ2['items'][0]['qty'] ?? '0'), '1', 6) === 0, 'Zone2 has KIZ unit');

    fwrite(STDOUT, "\nSupply chain growth: {$ok} OK, {$fail} FAIL\n");
    exit($fail > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
