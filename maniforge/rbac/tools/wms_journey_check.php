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
use App\Maniforge\Wms\Support\QrSsccGenerator;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$ok = 0;
$fail = 0;

function wmsAssert(bool $cond, string $msg): void
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
    $wms = new WmsService();
    $inventory = new InventoryService();

    $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    $phone = '+7923' . random_int(1000000, 9999999);
    $password = 'WmsJourney!12345';

    $reg = $registration->register([
        'password' => $password,
        'phone' => $phone,
        'organization_name' => 'WMS Journey ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    wmsAssert(($reg['ok'] ?? false) === true, 'Register tenant');

    $tenantId = (string) ($reg['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($reg['tenant']['subtenant_id'] ?? 'main');

    $login = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phone, 'password' => $password],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    wmsAssert(($login['ok'] ?? false) === true, 'Login');
    $session = $login['session'] ?? $login['credentials']['session'] ?? [];
    $session['user_id'] = (int) ($session['user_id'] ?? $reg['user']['id'] ?? 0);

    $wh = $stocks->createStock($session, [
        'code' => 'wh-wms-' . $suffix,
        'name' => 'WH WMS',
        'type' => 'warehouse',
    ]);
    wmsAssert(($wh['ok'] ?? false) === true, 'Create warehouse');
    $whId = (int) ($wh['stock']['id'] ?? 0);

    $zone = $stocks->createStock($session, [
        'code' => 'zone-wms-' . $suffix,
        'name' => 'Zone WMS',
        'type' => 'zone',
        'parent_id' => $whId,
    ]);
    wmsAssert(($zone['ok'] ?? false) === true, 'Create zone');
    $zoneId = (int) ($zone['stock']['id'] ?? 0);

    $prod = $products->createProduct($session, [
        'code' => 'sku-wms-' . $suffix,
        'name' => 'SKU WMS',
        'unit' => 'pcs',
    ]);
    wmsAssert(($prod['ok'] ?? false) === true, 'Create product');
    $productId = (int) ($prod['product']['id'] ?? 0);

    $kizIds = [];
    for ($i = 1; $i <= 3; $i++) {
        $code = '(01)0460000000000' . $i . '(21)SN' . $suffix . $i . '(93)ABCD';
        $m = $wms->registerMarking($session, [
            'product_id' => $productId,
            'code' => $code,
            'code_type' => 'kiz',
        ]);
        wmsAssert(($m['ok'] ?? false) === true, "Register KIZ {$i}");
        $kizIds[] = (int) ($m['marking']['id'] ?? 0);
    }

    $group = $wms->createPack($session, [
        'unit_type' => PackUnitTypes::GROUP,
        'code' => 'gu-' . $suffix,
    ]);
    wmsAssert(($group['ok'] ?? false) === true, 'Create group pack');
    $groupId = (int) ($group['pack']['id'] ?? 0);

    foreach ($kizIds as $kid) {
        $add = $wms->addMarkingToPack($session, $groupId, ['marking_code_id' => $kid]);
        wmsAssert(($add['ok'] ?? false) === true, 'Add KIZ to group');
    }

    $sealGroup = $wms->sealPack($session, $groupId);
    wmsAssert(($sealGroup['ok'] ?? false) === true, 'Seal group');
    wmsAssert(($sealGroup['pack']['status'] ?? '') === 'sealed', 'Group status sealed');

    $pallet = $wms->createPack($session, [
        'unit_type' => PackUnitTypes::PALLET,
        'code' => 'plt-' . $suffix,
        'stock_id' => $zoneId,
    ]);
    wmsAssert(($pallet['ok'] ?? false) === true, 'Create pallet');
    $palletId = (int) ($pallet['pack']['id'] ?? 0);
    wmsAssert(!empty($pallet['pack']['sscc']), 'Pallet has SSCC');

    $addChild = $wms->addChildPack($session, $palletId, ['child_pack_unit_id' => $groupId]);
    wmsAssert(($addChild['ok'] ?? false) === true, 'Add group to pallet');

    $sealPallet = $wms->sealPack($session, $palletId);
    wmsAssert(($sealPallet['ok'] ?? false) === true, 'Seal pallet');
    $qr = (string) ($sealPallet['pack']['qr_payload'] ?? '');
    wmsAssert($qr !== '', 'Pallet QR payload');

    $scanSscc = $wms->scan($session, (string) ($sealPallet['pack']['sscc'] ?? ''));
    wmsAssert(($scanSscc['ok'] ?? false) === true && ($scanSscc['kind'] ?? '') === 'pack', 'Scan by SSCC');

    $scanQr = $wms->scan($session, $qr);
    wmsAssert(($scanQr['ok'] ?? false) === true, 'Scan by QR JSON');

    $mov = $wms->postMovementByScan($session, [
        'movement_type' => MovementTypes::RECEIPT,
        'stock_id' => $zoneId,
        'pack_unit_id' => $palletId,
        'doc_number' => 'wms-rcv-' . $suffix,
    ]);
    wmsAssert(($mov['ok'] ?? false) === true, 'Receipt by pallet scan');
    wmsAssert(count($mov['movement']['lines'] ?? []) >= 3, 'Movement lines from 3 KIZ');

    $bal = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    wmsAssert(bccomp((string) (($bal['items'][0]['qty'] ?? '0')), '3', 6) === 0, 'Balance qty 3 after pallet receipt');

    $issueKiz = $wms->postMovementByScan($session, [
        'movement_type' => MovementTypes::ISSUE,
        'stock_id' => $zoneId,
        'scan' => '(01)04600000000001(21)SN' . $suffix . '1(93)ABCD',
        'doc_number' => 'wms-iss-' . $suffix,
    ]);
    wmsAssert(($issueKiz['ok'] ?? false) === true, 'Issue single KIZ scan');

    $bal2 = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    wmsAssert(bccomp((string) (($bal2['items'][0]['qty'] ?? '0')), '2', 6) === 0, 'Balance qty 2 after KIZ issue');

    $issueMovId = (int) (($issueKiz['movement']['id'] ?? 0));
    $rev = $inventory->reverseMovement($session, $issueMovId, ['doc_number' => 'rev-' . $suffix]);
    wmsAssert(($rev['ok'] ?? false) === true, 'Reverse issue movement');
    $bal3 = $inventory->listBalances($session, ['product_id' => $productId, 'stock_id' => $zoneId]);
    wmsAssert(bccomp((string) (($bal3['items'][0]['qty'] ?? '0')), '3', 6) === 0, 'Balance qty 3 after reverse');

    $listPacks = $wms->listPacks($session, ['unit_type' => PackUnitTypes::PALLET]);
    wmsAssert(count($listPacks['items'] ?? []) >= 1, 'List pallet packs');

    $dis = $wms->disaggregatePack($session, $groupId);
    wmsAssert(($dis['ok'] ?? false) === true, 'Disaggregate group pack');
    wmsAssert(($dis['pack']['status'] ?? '') === 'disaggregated', 'Group disaggregated status');

    fwrite(STDOUT, "\nWMS journey: {$ok} OK, {$fail} FAIL\n");
    exit($fail > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
