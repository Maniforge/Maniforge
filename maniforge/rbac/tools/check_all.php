<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function checkAllHasFlag(array $argv, string $flag): bool
{
    foreach ($argv as $arg) {
        if ($arg === $flag) {
            return true;
        }
    }

    return false;
}

function printCheckAllUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/check_all.php [--help]\n");
    fwrite(STDOUT, "Runs preflight, credential_architecture_check, entity_meta_check, integration_check,\n");
    fwrite(STDOUT, "tenant_lifecycle_check, delegation_check, phone_login_scope_check,\n");
    fwrite(STDOUT, "new_user_journey_check, organization_membership_check, warehouses_journey_check, products_journey_check, inventory_journey_check, wms_journey_check, race_condition_check, pd_compliance_check, pd_compliance_journey_check.\n");
}

if (checkAllHasFlag($argv, '--help')) {
    printCheckAllUsage();
    exit(0);
}

$projectRoot = dirname(__DIR__, 3);
$phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';

/**
 * @return int
 */
function runScript(string $phpBinary, string $scriptPath, string $title): int
{
    fwrite(STDOUT, "\n=== {$title} ===\n");
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath);
    passthru($command, $exitCode);
    fwrite(STDOUT, "=== {$title} exit: {$exitCode} ===\n");
    return (int) $exitCode;
}

$preflightPath = $projectRoot . '/maniforge/rbac/tools/preflight.php';
$credentialPath = $projectRoot . '/maniforge/rbac/tools/credential_architecture_check.php';
$entityMetaPath = $projectRoot . '/maniforge/rbac/tools/entity_meta_check.php';
$integrationPath = $projectRoot . '/maniforge/rbac/tools/integration_check.php';
$phoneScopePath = $projectRoot . '/maniforge/rbac/tools/phone_login_scope_check.php';
$tenantLifecyclePath = $projectRoot . '/maniforge/rbac/tools/tenant_lifecycle_check.php';
$delegationPath = $projectRoot . '/maniforge/rbac/tools/delegation_check.php';
$newUserJourneyPath = $projectRoot . '/maniforge/rbac/tools/new_user_journey_check.php';
$orgMembershipPath = $projectRoot . '/maniforge/rbac/tools/organization_membership_check.php';
$warehousesJourneyPath = $projectRoot . '/maniforge/rbac/tools/warehouses_journey_check.php';
$productsJourneyPath = $projectRoot . '/maniforge/rbac/tools/products_journey_check.php';
$inventoryJourneyPath = $projectRoot . '/maniforge/rbac/tools/inventory_journey_check.php';
$wmsJourneyPath = $projectRoot . '/maniforge/rbac/tools/wms_journey_check.php';
$supplyChainGrowthPath = $projectRoot . '/maniforge/rbac/tools/supply_chain_growth_check.php';
$ean13CheckPath = $projectRoot . '/maniforge/rbac/tools/ean13_check.php';
$raceConditionPath = $projectRoot . '/maniforge/rbac/tools/race_condition_check.php';
$pdCompliancePath = $projectRoot . '/maniforge/rbac/tools/pd_compliance_check.php';
$pdJourneyPath = $projectRoot . '/maniforge/rbac/tools/pd_compliance_journey_check.php';
$pdGatePath = $projectRoot . '/maniforge/rbac/tools/pd_compliance_gate_check.php';

$preflightExit = runScript($phpBinary, $preflightPath, 'Preflight');
if ($preflightExit !== 0) {
    fwrite(STDERR, "check_all stopped: preflight failed.\n");
    exit($preflightExit);
}

$credentialExit = runScript($phpBinary, $credentialPath, 'Credential Architecture Check');
if ($credentialExit !== 0) {
    fwrite(STDERR, "check_all failed: credential architecture check returned non-zero.\n");
    exit($credentialExit);
}

$entityMetaExit = runScript($phpBinary, $entityMetaPath, 'Entity Meta Check');
if ($entityMetaExit !== 0) {
    fwrite(STDERR, "check_all failed: entity meta check returned non-zero.\n");
    exit($entityMetaExit);
}

$integrationExit = runScript($phpBinary, $integrationPath, 'Integration Check');
if ($integrationExit !== 0) {
    fwrite(STDERR, "check_all failed: integration check returned non-zero.\n");
    exit($integrationExit);
}

$tenantLifecycleExit = runScript($phpBinary, $tenantLifecyclePath, 'Tenant Lifecycle Check');
if ($tenantLifecycleExit !== 0) {
    fwrite(STDERR, "check_all failed: tenant lifecycle check returned non-zero.\n");
    exit($tenantLifecycleExit);
}

$delegationExit = runScript($phpBinary, $delegationPath, 'Delegation Check');
if ($delegationExit !== 0) {
    fwrite(STDERR, "check_all failed: delegation check returned non-zero.\n");
    exit($delegationExit);
}

$phoneScopeExit = runScript($phpBinary, $phoneScopePath, 'Phone Login Scope Check');
if ($phoneScopeExit !== 0) {
    fwrite(STDERR, "check_all failed: phone login scope check returned non-zero.\n");
    exit($phoneScopeExit);
}

$newUserExit = runScript($phpBinary, $newUserJourneyPath, 'New User Journey Check');
if ($newUserExit !== 0) {
    fwrite(STDERR, "check_all failed: new user journey check returned non-zero.\n");
    exit($newUserExit);
}

$orgMembershipExit = runScript($phpBinary, $orgMembershipPath, 'Organization Membership Check');
if ($orgMembershipExit !== 0) {
    fwrite(STDERR, "check_all failed: organization membership check returned non-zero.\n");
    exit($orgMembershipExit);
}

$warehousesExit = runScript($phpBinary, $warehousesJourneyPath, 'Warehouses Journey Check');
if ($warehousesExit !== 0) {
    fwrite(STDERR, "check_all failed: warehouses journey check returned non-zero.\n");
    exit($warehousesExit);
}

$productsExit = runScript($phpBinary, $productsJourneyPath, 'Products Journey Check');
if ($productsExit !== 0) {
    fwrite(STDERR, "check_all failed: products journey check returned non-zero.\n");
    exit($productsExit);
}

$inventoryExit = runScript($phpBinary, $inventoryJourneyPath, 'Inventory Journey Check');
if ($inventoryExit !== 0) {
    fwrite(STDERR, "check_all failed: inventory journey check returned non-zero.\n");
    exit($inventoryExit);
}

$wmsExit = runScript($phpBinary, $wmsJourneyPath, 'WMS Journey Check');
if ($wmsExit !== 0) {
    fwrite(STDERR, "check_all failed: wms journey check returned non-zero.\n");
    exit($wmsExit);
}

$scGrowthExit = runScript($phpBinary, $supplyChainGrowthPath, 'Supply Chain Growth Check');
if ($scGrowthExit !== 0) {
    fwrite(STDERR, "check_all failed: supply chain growth check returned non-zero.\n");
    exit($scGrowthExit);
}

$ean13Exit = runScript($phpBinary, $ean13CheckPath, 'EAN-13 Barcode Check');
if ($ean13Exit !== 0) {
    fwrite(STDERR, "check_all failed: ean13 check returned non-zero.\n");
    exit($ean13Exit);
}

$raceExit = runScript($phpBinary, $raceConditionPath, 'Race Condition Check');
if ($raceExit !== 0) {
    fwrite(STDERR, "check_all failed: race condition check returned non-zero.\n");
    exit($raceExit);
}

$pdComplianceExit = runScript($phpBinary, $pdCompliancePath, 'PD Compliance Check');
if ($pdComplianceExit !== 0) {
    fwrite(STDERR, "check_all failed: PD compliance check returned non-zero.\n");
    exit($pdComplianceExit);
}

$pdJourneyExit = runScript($phpBinary, $pdJourneyPath, 'PD Compliance Journey Check');
if ($pdJourneyExit !== 0) {
    fwrite(STDERR, "check_all failed: PD compliance journey check returned non-zero.\n");
    exit($pdJourneyExit);
}

$pdGateExit = runScript($phpBinary, $pdGatePath, 'PD Compliance Gate Check');
if ($pdGateExit !== 0) {
    fwrite(STDERR, "check_all failed: PD compliance gate check returned non-zero.\n");
    exit($pdGateExit);
}

fwrite(STDOUT, "\nAll checks passed.\n");
exit(0);
