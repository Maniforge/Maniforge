<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Rbac\Security\PdBootstrapService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$tenantId = strtolower(trim((string) ($argv[1] ?? ($_ENV['DEFAULT_TENANT_ID'] ?? 'default'))));
$operatorName = trim((string) ($argv[2] ?? 'Maniforge Demo Operator'));

(new PdBootstrapService())->seedTenant($tenantId, $operatorName);
fwrite(STDOUT, "[OK] Operator profile and default purposes seeded\n");

fwrite(STDOUT, "152-ФЗ bootstrap complete for tenant {$tenantId}\n");
