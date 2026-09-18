<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function tlSmokeHasFlag(array $argv, string $flag): bool
{
    foreach ($argv as $arg) {
        if ($arg === $flag) {
            return true;
        }
    }

    return false;
}

function tlSmokeUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/tenant-licensing/tools/http_smoke.php [--help]\n");
    fwrite(STDOUT, "Required env vars:\n");
    fwrite(STDOUT, "  TL_SMOKE_BASE_URL  Example: http://127.0.0.1:8092/tenant-licensing\n");
    fwrite(STDOUT, "Optional env vars:\n");
    fwrite(STDOUT, "  TL_SMOKE_ADMIN_TOKEN   Bearer token (required when TENANT_LICENSING_ADMIN_TOKEN is set)\n");
    fwrite(STDOUT, "  TL_SMOKE_INTERNAL_TOKEN  Bearer token for internal access-state checks\n");
}

if (tlSmokeHasFlag($argv, '--help')) {
    tlSmokeUsage();
    exit(0);
}

final class TlSmoke
{
    private int $ok = 0;
    private int $fail = 0;

    public function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            $this->ok++;
            fwrite(STDOUT, "[OK] {$message}\n");
            return;
        }

        $this->fail++;
        fwrite(STDERR, "[FAIL] {$message}\n");
    }

    public function assertStatus(array $response, int $status, string $message): void
    {
        $actual = (int) ($response['status'] ?? 0);
        $this->assertTrue($actual === $status, "{$message} (expected={$status}, actual={$actual})");
    }

    public function summary(): void
    {
        fwrite(STDOUT, "\nSummary: ok={$this->ok}, fail={$this->fail}\n");
    }

    public function hasFailures(): bool
    {
        return $this->fail > 0;
    }
}

/**
 * @return array{status:int, body:array, raw:string}
 */
function tlHttpRequest(string $method, string $url, array $headers = [], ?array $payload = null): array
{
    $requestHeaders = $headers;
    $content = '';
    if ($payload !== null) {
        $content = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $requestHeaders[] = 'Content-Type: application/json';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $requestHeaders),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException("HTTP request failed: {$method} {$url}");
    }

    $status = 0;
    $responseHeaders = $http_response_header ?? [];
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $matches) === 1) {
        $status = (int) $matches[1];
    }

    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : [];

    return [
        'status' => $status,
        'body' => $body,
        'raw' => $raw,
    ];
}

$baseUrl = rtrim((string) ($_ENV['TL_SMOKE_BASE_URL'] ?? ''), '/');
if ($baseUrl === '') {
    fwrite(STDERR, "[ERROR] Missing TL_SMOKE_BASE_URL.\n");
    tlSmokeUsage();
    exit(2);
}

$adminToken = trim((string) ($_ENV['TL_SMOKE_ADMIN_TOKEN'] ?? ''));
$internalToken = trim((string) ($_ENV['TL_SMOKE_INTERNAL_TOKEN'] ?? ''));
$adminHeaders = $adminToken !== '' ? ['Authorization: Bearer ' . $adminToken] : [];
$internalHeaders = $internalToken !== '' ? ['Authorization: Bearer ' . $internalToken] : [];

$suffix = substr(bin2hex(random_bytes(6)), 0, 10);
$tenantCode = 'tl_smoke_' . $suffix;
$subtenantCode = 'main';
$planCode = 'tl_plan_' . $suffix;

$smoke = new TlSmoke();

try {
    $health = tlHttpRequest('GET', $baseUrl . '/health');
    $smoke->assertStatus($health, 200, 'Health returns 200');
    $smoke->assertTrue((bool) ($health['body']['ok'] ?? false), 'Health ok=true');

    $unauthorized = tlHttpRequest('GET', $baseUrl . '/api/v1/tenants');
    if ($adminToken !== '') {
        $smoke->assertStatus($unauthorized, 403, 'Tenants without bearer returns 403 when token configured');
    } else {
        $smoke->assertStatus($unauthorized, 200, 'Tenants without bearer allowed in local/test profile');
    }

    $createTenant = tlHttpRequest(
        'POST',
        $baseUrl . '/api/v1/tenants',
        $adminHeaders,
        ['code' => $tenantCode, 'name' => 'TL HTTP Smoke Tenant', 'metadata' => ['source' => 'http_smoke']]
    );
    $smoke->assertTrue(in_array((int) $createTenant['status'], [200, 201], true), 'Create tenant returns 2xx');
    $smoke->assertTrue((bool) ($createTenant['body']['ok'] ?? false), 'Create tenant ok=true');

    $createSubtenant = tlHttpRequest(
        'POST',
        $baseUrl . '/api/v1/tenants/' . rawurlencode($tenantCode) . '/subtenants',
        $adminHeaders,
        ['code' => $subtenantCode, 'name' => 'Main Workspace']
    );
    $smoke->assertTrue(in_array((int) $createSubtenant['status'], [200, 201], true), 'Create subtenant returns 2xx');
    $smoke->assertTrue((bool) ($createSubtenant['body']['ok'] ?? false), 'Create subtenant ok=true');

    $upsertPlan = tlHttpRequest(
        'POST',
        $baseUrl . '/api/v1/plans',
        $adminHeaders,
        [
            'code' => $planCode,
            'name' => 'TL HTTP Smoke Plan',
            'status' => 'active',
            'features' => ['rbac' => true, 'admin_api' => true],
            'limits' => ['max_users' => 5, 'max_sessions' => 20],
        ]
    );
    $smoke->assertTrue(in_array((int) $upsertPlan['status'], [200, 201], true), 'Upsert plan returns 2xx');
    $smoke->assertTrue((bool) ($upsertPlan['body']['ok'] ?? false), 'Upsert plan ok=true');

    $assignLicense = tlHttpRequest(
        'POST',
        $baseUrl . '/api/v1/licenses/assign',
        $adminHeaders,
        [
            'tenant_code' => $tenantCode,
            'plan_code' => $planCode,
            'seats_max' => 5,
            'expires_at' => gmdate('Y-m-d H:i:s', strtotime('+1 day')),
        ]
    );
    $smoke->assertStatus($assignLicense, 200, 'Assign license returns 200');
    $smoke->assertTrue((bool) ($assignLicense['body']['ok'] ?? false), 'Assign license ok=true');

    $entitlements = tlHttpRequest(
        'GET',
        $baseUrl . '/api/v1/tenants/' . rawurlencode($tenantCode) . '/entitlements',
        $adminHeaders
    );
    $smoke->assertStatus($entitlements, 200, 'Entitlements returns 200');
    $smoke->assertTrue((bool) ($entitlements['body']['ok'] ?? false), 'Entitlements ok=true');

    $quota = tlHttpRequest(
        'GET',
        $baseUrl . '/api/v1/tenants/' . rawurlencode($tenantCode) . '/quota?metric=users',
        $adminHeaders
    );
    $smoke->assertStatus($quota, 200, 'Quota returns 200');
    $smoke->assertTrue((bool) ($quota['body']['ok'] ?? false), 'Quota ok=true');

    $accessState = tlHttpRequest(
        'GET',
        $baseUrl . '/internal/v1/tenants/' . rawurlencode($tenantCode) . '/subtenants/' . rawurlencode($subtenantCode) . '/access-state',
        $internalHeaders
    );
    $smoke->assertStatus($accessState, 200, 'Access-state returns 200');
    $smoke->assertTrue((bool) ($accessState['body']['ok'] ?? false), 'Access-state ok=true');
    $smoke->assertTrue((bool) ($accessState['body']['license_active'] ?? false), 'Access-state license_active=true');

    $updateSubtenant = tlHttpRequest(
        'PATCH',
        $baseUrl . '/api/v1/tenants/' . rawurlencode($tenantCode) . '/subtenants/' . rawurlencode($subtenantCode),
        $adminHeaders,
        ['name' => 'Main Workspace', 'status' => 'suspended']
    );
    $smoke->assertStatus($updateSubtenant, 200, 'Suspend subtenant returns 200');
    $smoke->assertTrue((bool) ($updateSubtenant['body']['ok'] ?? false), 'Suspend subtenant ok=true');

    $suspendedState = tlHttpRequest(
        'GET',
        $baseUrl . '/internal/v1/tenants/' . rawurlencode($tenantCode) . '/subtenants/' . rawurlencode($subtenantCode) . '/access-state',
        $internalHeaders
    );
    $smoke->assertStatus($suspendedState, 200, 'Suspended access-state returns 200');
    $smoke->assertTrue((bool) ($suspendedState['body']['ok'] ?? false), 'Suspended access-state ok=true');
    $smoke->assertTrue((bool) ($suspendedState['body']['subtenant_active'] ?? true) === false, 'Suspended subtenant inactive');

    $audit = tlHttpRequest(
        'GET',
        $baseUrl . '/api/v1/audit?tenant_code=' . rawurlencode($tenantCode) . '&limit=20',
        $adminHeaders
    );
    $smoke->assertStatus($audit, 200, 'Audit returns 200');
    $smoke->assertTrue((bool) ($audit['body']['ok'] ?? false), 'Audit ok=true');

    $events = tlHttpRequest(
        'GET',
        $baseUrl . '/api/v1/events?tenant_code=' . rawurlencode($tenantCode) . '&limit=20',
        $adminHeaders
    );
    $smoke->assertStatus($events, 200, 'Events returns 200');
    $smoke->assertTrue((bool) ($events['body']['ok'] ?? false), 'Events ok=true');

    $revoke = tlHttpRequest(
        'POST',
        $baseUrl . '/api/v1/licenses/revoke',
        $adminHeaders,
        ['tenant_code' => $tenantCode, 'reason' => 'http_smoke_cleanup']
    );
    $smoke->assertStatus($revoke, 200, 'Revoke license returns 200');
    $smoke->assertTrue((bool) ($revoke['body']['ok'] ?? false), 'Revoke license ok=true');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $smoke->assertTrue(false, 'Unhandled exception in Tenant Licensing HTTP smoke');
}

$smoke->summary();
exit($smoke->hasFailures() ? 1 : 0);
