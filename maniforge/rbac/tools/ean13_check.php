<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Inventory\Security\InventoryService;
use App\Maniforge\Inventory\Support\MovementTypes;
use App\Maniforge\Products\Security\ProductService;
use App\Maniforge\Products\Support\Ean13;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Warehouses\Security\StockService;
use App\Maniforge\Wms\Security\WmsService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ok = 0;
$fail = 0;

function eanAssert(bool $c, string $m): void
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

/** @return string Valid EAN-13 for tests */
function sampleEan13(): string
{
    $base = '46000000000';
    for ($d = 0; $d <= 9; $d++) {
        $candidate = $base . (string) $d;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $n = (int) $candidate[$i];
            $sum += ($i % 2 === 0) ? $n : $n * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $candidate . $check;
    }

    return '4600000000008';
}

try {
    $valid = sampleEan13();
    eanAssert(Ean13::isValidCheckDigit($valid), 'EAN-13 check digit helper');
    eanAssert((Ean13::normalize($valid)['ok'] ?? false) === true, 'Normalize valid EAN');
    eanAssert((Ean13::normalize('invalid')['ok'] ?? true) === false, 'Reject invalid');

    $regSvc = new RegistrationService();
    $auth = new AuthService();
    $products = new ProductService();
    $stocks = new StockService();
    $wms = new WmsService();
    $inv = new InventoryService();

    $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
    $phone = '+7925' . random_int(1000000, 9999999);
    $reg = $regSvc->register([
        'password' => 'Ean13!123456',
        'phone' => $phone,
        'organization_name' => 'EAN13 ' . $suffix,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ]);
    if (($reg['ok'] ?? false) !== true) {
        fwrite(STDERR, 'Register failed: ' . ($reg['error'] ?? json_encode($reg)) . "\n");
        exit(1);
    }
    eanAssert(true, 'Register');

    $tenantId = (string) ($reg['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($reg['tenant']['subtenant_id'] ?? 'main');
    $login = $auth->login(
        ['tenant_id' => $tenantId, 'subtenant_id' => $subtenantId],
        ['phone' => $phone, 'password' => 'Ean13!123456'],
        ['REMOTE_ADDR' => '127.0.0.1']
    );
    if (($login['ok'] ?? false) !== true) {
        fwrite(STDERR, 'Login failed: ' . ($login['error'] ?? json_encode($login)) . "\n");
        exit(1);
    }
    $session = $login['session'] ?? $login['credentials']['session'] ?? [];
    $session['tenant_id'] = $tenantId;
    $session['subtenant_id'] = $subtenantId;
    $session['user_id'] = (int) ($session['user_id'] ?? $reg['user']['id'] ?? 0);

    $ean = sampleEan13();
    $prod = $products->createProduct($session, [
        'code' => 'sku-ean-' . $suffix,
        'name' => 'Product EAN',
        'barcode_ean13' => $ean,
    ]);
    eanAssert(($prod['ok'] ?? false) === true, 'Create product with EAN-13');
    eanAssert(($prod['product']['barcode_ean13'] ?? '') === $ean, 'Product returns barcode');

    $byBc = $products->getProductByBarcode($session, $ean);
    eanAssert(($byBc['ok'] ?? false) === true && ($byBc['kind'] ?? '') === 'product', 'Lookup by barcode API');

    $scan = $wms->scan($session, $ean);
    eanAssert(($scan['ok'] ?? false) === true && ($scan['kind'] ?? '') === 'product', 'WMS scan resolves EAN');

    $zone = $stocks->createStock($session, [
        'code' => 'z-ean-' . $suffix,
        'name' => 'Zone',
        'type' => 'zone',
        'parent_id' => (int) (($stocks->createStock($session, [
            'code' => 'w-ean-' . $suffix,
            'name' => 'W',
            'type' => 'warehouse',
        ]))['stock']['id'] ?? 0),
    ]);
    $zoneId = (int) ($zone['stock']['id'] ?? 0);

    $rcv = $wms->postMovementByScan($session, [
        'movement_type' => MovementTypes::RECEIPT,
        'stock_id' => $zoneId,
        'scan' => $ean,
        'qty' => '5',
    ]);
    eanAssert(($rcv['ok'] ?? false) === true, 'Receipt by EAN-13 scan qty=5');

    $bal = $inv->listBalances($session, [
        'product_id' => (int) ($prod['product']['id'] ?? 0),
        'stock_id' => $zoneId,
    ]);
    eanAssert(bccomp((string) ($bal['items'][0]['qty'] ?? '0'), '5', 6) === 0, 'Balance 5 after EAN receipt');

    fwrite(STDOUT, "\nEAN-13 check: {$ok} OK, {$fail} FAIL\n");
    exit($fail > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
