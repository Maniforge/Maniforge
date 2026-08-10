<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\PdPurposeRepository;
use App\Maniforge\Rbac\Security\PiiFieldCodec;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$fail = 0;
$ok = 0;

function line(bool $pass, string $message): void
{
    global $fail, $ok;
    if ($pass) {
        $ok++;
        fwrite(STDOUT, "[OK] {$message}\n");
        return;
    }
    $fail++;
    fwrite(STDERR, "[FAIL] {$message}\n");
}

try {
    $pdo = Connection::get();
    line(true, 'DB connection');
} catch (Throwable $e) {
    line(false, 'DB connection: ' . $e->getMessage());
    fwrite(STDOUT, "\nSummary: ok={$ok}, fail={$fail}\n");
    exit(2);
}

$tables = [
    'maniforge_pd_operator_profiles',
    'maniforge_pd_processing_purposes',
    'maniforge_pd_consents',
    'maniforge_pd_subject_requests',
];

foreach ($tables as $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute([':t' => $table]);
    $exists = (int) ($stmt->fetch()['c'] ?? 0) > 0;
    line($exists, "Table {$table}");
}

$permissions = [
    'me.personal_data.read',
    'me.consent.read',
    'me.consent.manage',
    'me.personal_data.request',
    'admin.pd.operator.read',
    'admin.pd.operator.write',
    'admin.pd.purposes.read',
    'admin.pd.purposes.write',
    'admin.pd.requests.read',
    'admin.pd.requests.handle',
];

foreach ($permissions as $code) {
    $stmt = $pdo->prepare('SELECT id FROM maniforge_permissions WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    line(is_array($stmt->fetch()), "Permission {$code}");
}

$tenantMode = strtolower((string) ($_ENV['TENANCY_MODE'] ?? 'single'));
$defaultTenant = strtolower(trim((string) ($_ENV['DEFAULT_TENANT_ID'] ?? 'default')));
$profileStmt = $pdo->prepare('SELECT tenant_id FROM maniforge_pd_operator_profiles WHERE tenant_id = :t LIMIT 1');
$profileStmt->execute([':t' => $defaultTenant]);
$hasProfile = is_array($profileStmt->fetch());

if (!$hasProfile && $tenantMode === 'single') {
    fwrite(STDOUT, "[WARN] Нет operator profile для tenant {$defaultTenant} — настройте PUT /admin/personal-data/operator-profile\n");
}

$purposes = (new PdPurposeRepository())->listActive($defaultTenant);
if ($purposes === []) {
    fwrite(STDOUT, "[WARN] Нет активных целей обработки для tenant {$defaultTenant}\n");
} else {
    line(true, 'Active purposes for default tenant: ' . count($purposes));
}

$consentRequired = filter_var($_ENV['RBAC_PD_REGISTER_CONSENT_REQUIRED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
if ($consentRequired && $purposes === []) {
    line(false, 'RBAC_PD_REGISTER_CONSENT_REQUIRED=true, но purposes не настроены');
}

$pii = new PiiFieldCodec();
if ($pii->isEnabled()) {
    line($pii->hasValidKey(), 'PII encryption enabled with valid RBAC_PII_ENCRYPTION_KEY');
    $plainStmt = $pdo->query('SELECT COUNT(*) AS c FROM maniforge_users WHERE pii_enc_version = 0');
    $plainCount = (int) (($plainStmt->fetch()['c'] ?? 0));
    if ($plainCount > 0) {
        fwrite(STDOUT, "[WARN] {$plainCount} users still plaintext — run php maniforge/rbac/tools/pd_migrate_pii_encryption.php\n");
    } else {
        line(true, 'All users use PII encryption');
    }
} elseif ($pii->isRequiredInProduction()) {
    line(false, 'Production requires RBAC_PII_ENCRYPTION_ENABLED=true');
}

fwrite(STDOUT, "\nSummary: ok={$ok}, fail={$fail}\n");
exit($fail > 0 ? 2 : 0);
