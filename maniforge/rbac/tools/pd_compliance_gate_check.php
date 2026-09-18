<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Rbac\Security\PlatformProcessorConfig;
use App\Maniforge\Rbac\Security\TenantPdComplianceService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$ok = 0;
$fail = 0;
$warn = 0;

function gateOk(string $msg): void
{
    global $ok;
    $ok++;
    fwrite(STDOUT, "[OK] {$msg}\n");
}

function gateFail(string $msg): void
{
    global $fail;
    $fail++;
    fwrite(STDERR, "[FAIL] {$msg}\n");
}

function gateWarn(string $msg): void
{
    global $warn;
    $warn++;
    fwrite(STDOUT, "[WARN] {$msg}\n");
}

$env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'local')));
$isProd = in_array($env, ['prod', 'production'], true);

if ((new PlatformProcessorConfig())->toNoticePayload() === null) {
    if ($isProd) {
        gateFail('RBAC_PLATFORM_PROCESSOR_NAME not set (required in production)');
    } else {
        gateWarn('RBAC_PLATFORM_PROCESSOR_NAME not set (recommended before production)');
    }
} else {
    gateOk('Platform processor identity configured');
}

if ($isProd && !filter_var($_ENV['RBAC_PII_ENCRYPTION_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    gateFail('RBAC_PII_ENCRYPTION_ENABLED must be true in production');
} else {
    gateOk('PII encryption env checked');
}

if ($isProd && !filter_var($_ENV['RBAC_PD_REGISTER_CONSENT_REQUIRED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    gateWarn('RBAC_PD_REGISTER_CONSENT_REQUIRED=false in production (recommended true)');
} else {
    gateOk('Registration consent policy checked');
}

$demoTenant = strtolower(trim((string) ($_ENV['DEFAULT_TENANT_ID'] ?? 'default')));
$status = (new TenantPdComplianceService())->buildStatus($demoTenant);
if (($status['operator_ready'] ?? false) === true) {
    gateOk("Operator profile ready for tenant {$demoTenant}");
} else {
    gateWarn('Operator profile incomplete for ' . $demoTenant . ': ' . implode(', ', $status['operator_missing'] ?? []));
}

fwrite(STDOUT, "\nSummary: ok={$ok}, warn={$warn}, fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
