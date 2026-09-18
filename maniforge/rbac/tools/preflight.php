<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Security\PiiFieldCodec;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function preflightHasFlag(array $argv, string $flag): bool
{
    foreach ($argv as $arg) {
        if ($arg === $flag) {
            return true;
        }
    }

    return false;
}

function printPreflightUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/preflight.php [--help]\n");
    fwrite(STDOUT, "  --help  Show this help message\n");
}

if (preflightHasFlag($argv, '--help')) {
    printPreflightUsage();
    exit(0);
}

final class PreflightReport
{
    private int $ok = 0;
    private int $warn = 0;
    private int $fail = 0;

    public function ok(string $message): void
    {
        $this->ok++;
        fwrite(STDOUT, "[OK] {$message}\n");
    }

    public function warn(string $message): void
    {
        $this->warn++;
        fwrite(STDOUT, "[WARN] {$message}\n");
    }

    public function fail(string $message): void
    {
        $this->fail++;
        fwrite(STDERR, "[FAIL] {$message}\n");
    }

    public function hasFailures(): bool
    {
        return $this->fail > 0;
    }

    public function printSummary(): void
    {
        fwrite(STDOUT, "\nSummary: ok={$this->ok}, warn={$this->warn}, fail={$this->fail}\n");
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function loadAppliedMigrations(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT * FROM maniforge_migrations');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $applied = [];
        foreach ($rows as $row) {
            $version = (string) ($row['version'] ?? '');
            if ($version !== '') {
                $applied[$version] = $row;
            }
        }

        return $applied;
    } catch (Throwable) {
        return [];
    }
}

/**
 * @return string[]
 */
function loadMigrationFiles(string $migrationsDir): array
{
    $files = glob($migrationsDir . '/*.sql');
    if ($files === false || $files === []) {
        return [];
    }

    sort($files);
    return array_map('basename', $files);
}

$report = new PreflightReport();

try {
    $pdo = Connection::get();
    $report->ok('DB connection established');
} catch (Throwable $e) {
    $report->fail('DB connection failed: ' . $e->getMessage());
    $report->warn('Проверьте DB_* в .env и доступ пользователя к MySQL');
    $report->printSummary();
    exit(2);
}

$migrationsDir = dirname(__DIR__) . '/migrations';
$migrationFiles = loadMigrationFiles($migrationsDir);
if ($migrationFiles === []) {
    $report->fail('No migration files found in maniforge/rbac/migrations');
    $report->printSummary();
    exit(2);
}
$report->ok('Migration files detected: ' . count($migrationFiles));

$applied = loadAppliedMigrations($pdo);
if ($applied === []) {
    $report->warn('maniforge_migrations empty or unavailable; migrations may not be tracked');
}

$missingMigrations = [];
foreach ($migrationFiles as $file) {
    if (!isset($applied[$file])) {
        $missingMigrations[] = $file;
        continue;
    }

    $path = $migrationsDir . '/' . $file;
    $storedChecksum = (string) ($applied[$file]['checksum'] ?? '');
    if ($storedChecksum !== '' && is_file($path)) {
        $actualChecksum = hash_file('sha256', $path);
        if ($actualChecksum !== false && !hash_equals($storedChecksum, $actualChecksum)) {
            $report->fail("Migration checksum mismatch: {$file}");
        }
    }

    if ((int) ($applied[$file]['dirty'] ?? 0) === 1) {
        $report->fail("Dirty migration marker found: {$file}");
    }
}
if ($missingMigrations === []) {
    $report->ok('All SQL migrations are marked as applied');
} else {
    $report->fail('Missing applied migrations: ' . implode(', ', $missingMigrations));
}

$requiredTables = [
    'maniforge_users',
    'maniforge_roles',
    'maniforge_permissions',
    'maniforge_user_roles',
    'maniforge_sessions',
    'maniforge_audit_log',
    'maniforge_security_events',
    'maniforge_policy_rules',
    'maniforge_rate_limits',
    'maniforge_tl_tenants',
    'maniforge_tl_subtenants',
    'maniforge_tl_license_plans',
    'maniforge_tl_tenant_licenses',
    'maniforge_tl_quota_usage',
    'maniforge_tl_audit_log',
    'maniforge_tl_events',
    'maniforge_tenant_access_cache',
    'maniforge_action_tokens',
    'maniforge_tl_tenant_grants',
    'maniforge_projects',
    'maniforge_scope_variables',
    'maniforge_user_project_memberships',
    'maniforge_pd_operator_profiles',
    'maniforge_pd_processing_purposes',
    'maniforge_pd_consents',
    'maniforge_pd_subject_requests',
    'maniforge_entity_meta',
    'maniforge_wh_stock_types',
    'maniforge_wh_stocks',
    'maniforge_products',
    'maniforge_inv_balances',
    'maniforge_wms_pack_units',
    'maniforge_wms_marking_codes',
    'maniforge_wms_pack_contents',
    'maniforge_inv_movements',
    'maniforge_inv_movement_lines',
    'maniforge_inv_reserves',
    'maniforge_inv_orders',
    'maniforge_inv_order_lines',
    'maniforge_inv_lots',
];

foreach ($requiredTables as $table) {
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS total
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        $row = $stmt->fetch();
        $exists = ((int) ($row['total'] ?? 0)) > 0;
        if ($exists) {
            $report->ok("Table exists: {$table}");
        } else {
            $report->fail("Table missing: {$table}");
        }
    } catch (Throwable $e) {
        $report->fail("Failed to check table {$table}: " . $e->getMessage());
    }
}

$requiredPermissions = [
    'admin.users.read',
    'admin.user_roles.bulk',
    'admin.sessions.bulk',
    'admin.users.status.bulk',
    'admin.policies.read',
    'admin.policies.update',
    'projects.read',
    'scope_variables.read',
    'me.personal_data.read',
    'admin.pd.operator.read',
    'admin.pd.requests.handle',
    'admin.audit.export',
    'warehouses.read',
    'warehouses.write',
    'warehouses.delete',
    'warehouses.types.read',
    'warehouses.audit.read',
    'products.read',
    'products.write',
    'products.delete',
    'inventory.read',
    'inventory.write',
    'wms.read',
    'wms.write',
];

foreach ($requiredPermissions as $permission) {
    try {
        $stmt = $pdo->prepare('SELECT id FROM maniforge_permissions WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $permission]);
        if (is_array($stmt->fetch())) {
            $report->ok("Permission exists: {$permission}");
        } else {
            $report->fail("Permission missing: {$permission}");
        }
    } catch (Throwable $e) {
        $report->fail("Failed to check permission {$permission}: " . $e->getMessage());
    }
}

$piiCodec = new PiiFieldCodec();
if ($piiCodec->isRequiredInProduction()) {
    if (!$piiCodec->isEnabled() || !$piiCodec->hasValidKey()) {
        $report->fail('Production guard: enable RBAC_PII_ENCRYPTION_ENABLED and RBAC_PII_ENCRYPTION_KEY');
    } else {
        $report->ok('Production guard: PII encryption configured');
    }
}

$appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? 'local'));
if (in_array($appEnv, ['prod', 'production'], true)) {
    $debug = filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if ($debug) {
        $report->fail('Production guard: APP_DEBUG must be false');
    } else {
        $report->ok('Production guard: APP_DEBUG=false');
    }

    $dbUser = (string) ($_ENV['DB_USER'] ?? 'root');
    $dbPass = (string) ($_ENV['DB_PASS'] ?? '');
    if ($dbUser === 'root' || $dbPass === '') {
        $report->fail('Production guard: use explicit non-root DB credentials with password');
    } else {
        $report->ok('Production guard: DB credentials are explicit');
    }

    $consoleUser = trim((string) ($_ENV['RBAC_ADMIN_CONSOLE_USER'] ?? ''));
    $consolePassword = (string) ($_ENV['RBAC_ADMIN_CONSOLE_PASSWORD'] ?? '');
    $consolePasswordHash = (string) ($_ENV['RBAC_ADMIN_CONSOLE_PASSWORD_HASH'] ?? '');
    if ($consoleUser === '' || ($consolePassword === '' && $consolePasswordHash === '')) {
        $report->fail('Production guard: admin console Basic Auth credentials are required');
    } else {
        $report->ok('Production guard: admin console Basic Auth is configured');
    }

    $tlAdmin = trim((string) ($_ENV['TENANT_LICENSING_ADMIN_TOKEN'] ?? ''));
    $tlInternal = trim((string) ($_ENV['TENANT_LICENSING_INTERNAL_TOKEN'] ?? ''));
    $rbacInternal = trim((string) ($_ENV['RBAC_INTERNAL_TOKEN'] ?? ''));
    if ($tlAdmin === '') {
        $report->fail('Production guard: TENANT_LICENSING_ADMIN_TOKEN is required');
    } else {
        $report->ok('Production guard: TENANT_LICENSING_ADMIN_TOKEN is set');
    }
    if ($tlInternal === '') {
        $report->fail('Production guard: TENANT_LICENSING_INTERNAL_TOKEN is required');
    } else {
        $report->ok('Production guard: TENANT_LICENSING_INTERNAL_TOKEN is set');
    }
    if ($rbacInternal === '') {
        $report->fail('Production guard: RBAC_INTERNAL_TOKEN is required');
    } else {
        $report->ok('Production guard: RBAC_INTERNAL_TOKEN is set');
    }

    $enforcement = strtolower((string) ($_ENV['TENANT_LICENSING_ENFORCEMENT'] ?? ''));
    if ($enforcement !== 'strict') {
        $report->fail('Production guard: TENANT_LICENSING_ENFORCEMENT must be strict');
    } else {
        $report->ok('Production guard: TENANT_LICENSING_ENFORCEMENT=strict');
    }

    $tenancy = strtolower((string) ($_ENV['TENANCY_MODE'] ?? ''));
    if ($tenancy !== 'multi') {
        $report->warn('Production guard: TENANCY_MODE is not multi');
    } else {
        $report->ok('Production guard: TENANCY_MODE=multi');
    }

    $processorName = trim((string) ($_ENV['RBAC_PLATFORM_PROCESSOR_NAME'] ?? ''));
    if ($processorName === '') {
        $report->fail('Production guard: RBAC_PLATFORM_PROCESSOR_NAME is required (platform as PD processor)');
    } else {
        $report->ok('Production guard: platform processor identity is set');
    }

    if (!filter_var($_ENV['RBAC_PD_REGISTER_CONSENT_REQUIRED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $report->warn('Production guard: RBAC_PD_REGISTER_CONSENT_REQUIRED=false (recommended true)');
    } else {
        $report->ok('Production guard: RBAC_PD_REGISTER_CONSENT_REQUIRED=true');
    }
} else {
    $report->ok("Production guard skipped for APP_ENV={$appEnv}");
}

try {
    $stmt = $pdo->query('SELECT COUNT(*) AS total FROM maniforge_users');
    $row = $stmt->fetch();
    $userCount = (int) ($row['total'] ?? 0);
    if ($userCount > 0) {
        $report->ok("Users present in DB: {$userCount}");
    } else {
        $report->warn('No users found; create admin with maniforge/rbac/tools/create_admin.php');
    }
} catch (Throwable $e) {
    $report->warn('Could not count users: ' . $e->getMessage());
}

try {
    $stmt = $pdo->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'maniforge_users'
           AND index_name = 'uk_users_phone_scope'"
    );
    $row = $stmt->fetch();
    if ((int) ($row['total'] ?? 0) > 0) {
        $report->ok('Phone uniqueness: uk_users_phone_scope (per tenant/subtenant, migration 018)');
    } else {
        $report->warn('Phone index uk_users_phone_scope missing; run migrations through 018');
    }
} catch (Throwable $e) {
    $report->warn('Could not verify phone index: ' . $e->getMessage());
}

$report->printSummary();
exit($report->hasFailures() ? 1 : 0);
