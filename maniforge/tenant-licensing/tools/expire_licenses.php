<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$actor = 'license-expiry-job';
$result = (new TenantLicensingRepository())->expireDueLicenses($actor);

fwrite(
    STDOUT,
    sprintf(
        "Expired=%d, scanned=%d\n",
        (int) ($result['expired'] ?? 0),
        (int) ($result['total_scanned'] ?? 0)
    )
);
exit(0);
