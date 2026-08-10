<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

function hasFlag(array $argv, string $flag): bool
{
    foreach ($argv as $arg) {
        if ($arg === $flag) {
            return true;
        }
    }

    return false;
}

function usage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/http_smoke.php [--help]\n");
    fwrite(STDOUT, "Required env vars:\n");
    fwrite(STDOUT, "  RBAC_SMOKE_BASE_URL  Example: http://127.0.0.1:8080/rbac\n");
    fwrite(STDOUT, "  RBAC_SMOKE_LOGIN     Admin login (used to resolve phone if RBAC_SMOKE_PHONE unset)\n");
    fwrite(STDOUT, "  RBAC_SMOKE_PHONE     Admin phone for smoke auth (default: +70000000000 from create_admin)\n");
    fwrite(STDOUT, "  RBAC_SMOKE_PASSWORD  Admin password for smoke auth\n");
    fwrite(STDOUT, "Optional env vars:\n");
    fwrite(STDOUT, "  RBAC_SMOKE_TENANT_ID\n");
    fwrite(STDOUT, "  RBAC_SMOKE_SUBTENANT_ID\n");
}

if (hasFlag($argv, '--help')) {
    usage();
    exit(0);
}

final class Smoke
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
function httpRequest(string $method, string $url, array $headers = [], ?array $payload = null, array &$cookies = []): array
{
    $requestHeaders = $headers;
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $requestHeaders[] = 'Cookie: ' . implode('; ', $pairs);
    }
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
    foreach ($responseHeaders as $headerLine) {
        if (!str_starts_with(strtolower((string) $headerLine), 'set-cookie:')) {
            continue;
        }
        $cookiePart = trim(substr((string) $headerLine, strlen('Set-Cookie:')));
        $pair = explode(';', $cookiePart, 2)[0] ?? '';
        $eqPos = strpos($pair, '=');
        if ($eqPos === false) {
            continue;
        }
        $name = trim(substr($pair, 0, $eqPos));
        $value = trim(substr($pair, $eqPos + 1));
        if ($name !== '') {
            $cookies[$name] = $value;
        }
    }

    $decoded = json_decode($raw, true);
    $body = is_array($decoded) ? $decoded : [];

    return [
        'status' => $status,
        'body' => $body,
        'raw' => $raw,
    ];
}

$baseUrl = rtrim((string) ($_ENV['RBAC_SMOKE_BASE_URL'] ?? ''), '/');
$login = (string) ($_ENV['RBAC_SMOKE_LOGIN'] ?? '');
$password = (string) ($_ENV['RBAC_SMOKE_PASSWORD'] ?? '');
$phone = trim((string) ($_ENV['RBAC_SMOKE_PHONE'] ?? ''));
if ($phone === '') {
    $phone = '+70000000000';
}

if ($baseUrl === '' || $login === '' || $password === '') {
    fwrite(STDERR, "[ERROR] Missing required env vars for HTTP smoke.\n");
    usage();
    exit(2);
}

$tenantId = trim((string) ($_ENV['RBAC_SMOKE_TENANT_ID'] ?? ''));
$subtenantId = trim((string) ($_ENV['RBAC_SMOKE_SUBTENANT_ID'] ?? ''));
$tenantHeaders = [];
if ($tenantId !== '') {
    $tenantHeaders[] = 'X-Tenant-ID: ' . $tenantId;
}
if ($subtenantId !== '') {
    $tenantHeaders[] = 'X-Subtenant-ID: ' . $subtenantId;
}

$smoke = new Smoke();
$cookies = [];

try {
    $loginResponse = httpRequest(
        'POST',
        $baseUrl . '/api/v1/auth/login',
        $tenantHeaders,
        ['phone' => $phone, 'password' => $password],
        $cookies
    );
    $smoke->assertStatus($loginResponse, 200, 'Login returns 200');
    $smoke->assertTrue((bool) ($loginResponse['body']['ok'] ?? false), 'Login response ok=true');

    $session = $loginResponse['body']['session'] ?? [];
    $accessToken = (string) ($session['access_token'] ?? '');
    $csrfToken = (string) ($loginResponse['body']['csrf_token'] ?? '');
    $smoke->assertTrue($accessToken !== '', 'Access token received');
    $smoke->assertTrue($csrfToken !== '', 'CSRF token received');

    if ($accessToken === '' || $csrfToken === '') {
        throw new RuntimeException('Cannot continue smoke checks without access/csrf token');
    }

    $authHeaders = array_merge($tenantHeaders, [
        'Authorization: Bearer ' . $accessToken,
        'X-CSRF-Token: ' . $csrfToken,
    ]);

    $unauthorizedUsers = httpRequest(
        'GET',
        $baseUrl . '/api/v1/admin/users',
        $tenantHeaders,
        null,
        $cookies
    );
    $smoke->assertStatus($unauthorizedUsers, 401, 'Admin users without bearer returns 401');

    $missingCsrfReauth = httpRequest(
        'POST',
        $baseUrl . '/api/v1/auth/reauth',
        array_merge($tenantHeaders, ['Authorization: Bearer ' . $accessToken]),
        ['password' => $password],
        $cookies
    );
    $smoke->assertStatus($missingCsrfReauth, 403, 'Reauth without CSRF returns 403');

    $reauthResponse = httpRequest(
        'POST',
        $baseUrl . '/api/v1/auth/reauth',
        $authHeaders,
        ['password' => $password],
        $cookies
    );
    $smoke->assertStatus($reauthResponse, 200, 'Reauth returns 200');
    $smoke->assertTrue((bool) ($reauthResponse['body']['ok'] ?? false), 'Reauth response ok=true');
    $actionToken = (string) ($reauthResponse['body']['credentials']['action']['action_token'] ?? '');
    $smoke->assertTrue($actionToken !== '', 'Action token received after reauth');

    $sessionOnlyHeaders = [
        'Authorization: Bearer ' . $accessToken,
    ];

    $usersResponse = httpRequest(
        'GET',
        $baseUrl . '/api/v1/admin/users',
        $sessionOnlyHeaders,
        null,
        $cookies
    );
    $smoke->assertStatus($usersResponse, 200, 'Admin users returns 200');
    $smoke->assertTrue((bool) ($usersResponse['body']['ok'] ?? false), 'Admin users response ok=true');
    $users = $usersResponse['body']['items'] ?? [];
    $smoke->assertTrue(is_array($users), 'Admin users items is array');
    $smoke->assertTrue($users !== [], 'Admin users has at least one user');

    if (!is_array($users) || $users === []) {
        throw new RuntimeException('No users in scope for batch-status dry_run smoke');
    }

    $firstUser = $users[0];
    $targetUserId = (int) ($firstUser['id'] ?? 0);
    $targetStatus = (string) ($firstUser['status'] ?? 'active');
    $smoke->assertTrue($targetUserId > 0, 'First user has valid id');

    $actionHeaders = array_merge($authHeaders, ['X-Action-Token: ' . $actionToken]);

    $batchStatusResponse = httpRequest(
        'POST',
        $baseUrl . '/api/v1/admin/users/batch-status',
        $actionHeaders,
        [
            'reason' => 'http_smoke',
            'dry_run' => true,
            'items' => [
                [
                    'user_id' => $targetUserId,
                    'status' => $targetStatus,
                ],
            ],
        ],
        $cookies
    );
    $smoke->assertStatus($batchStatusResponse, 200, 'Batch status dry_run returns 200');
    $smoke->assertTrue((bool) ($batchStatusResponse['body']['ok'] ?? false), 'Batch status dry_run ok=true');
    $smoke->assertTrue((bool) ($batchStatusResponse['body']['dry_run'] ?? false), 'Batch status dry_run flag=true');

    $batchStatusInvalid = httpRequest(
        'POST',
        $baseUrl . '/api/v1/admin/users/batch-status',
        $actionHeaders,
        [
            'reason' => 'http_smoke_invalid',
            'items' => [
                [
                    'user_id' => $targetUserId,
                    'status' => 'not-a-status',
                ],
            ],
        ],
        $cookies
    );
    $smoke->assertStatus($batchStatusInvalid, 422, 'Batch status invalid item returns 422');

    $policiesGet = httpRequest(
        'GET',
        $baseUrl . '/api/v1/admin/policies',
        $sessionOnlyHeaders,
        null,
        $cookies
    );
    $smoke->assertStatus($policiesGet, 200, 'Policies GET returns 200');
    $smoke->assertTrue((bool) ($policiesGet['body']['ok'] ?? false), 'Policies GET ok=true');

    $rules = $policiesGet['body']['rules'] ?? [];
    $allowedIps = $rules['allowed_ips'] ?? [];
    if (!is_array($allowedIps)) {
        $allowedIps = [];
    }

    $policiesPost = httpRequest(
        'POST',
        $baseUrl . '/api/v1/admin/policies',
        $actionHeaders,
        [
            'reason' => 'http_smoke_noop',
            'allowed_ips' => $allowedIps,
            'allowed_hour_start_utc' => (int) ($rules['allowed_hour_start_utc'] ?? 0),
            'allowed_hour_end_utc' => (int) ($rules['allowed_hour_end_utc'] ?? 23),
            'require_step_up' => (bool) ($rules['require_step_up'] ?? true),
        ],
        $cookies
    );
    $smoke->assertStatus($policiesPost, 200, 'Policies POST returns 200');
    $smoke->assertTrue((bool) ($policiesPost['body']['ok'] ?? false), 'Policies POST ok=true');

    $policiesInvalid = httpRequest(
        'POST',
        $baseUrl . '/api/v1/admin/policies',
        $actionHeaders,
        [
            'reason' => 'http_smoke_invalid_ip',
            'allowed_ips' => ['not-an-ip'],
            'allowed_hour_start_utc' => 0,
            'allowed_hour_end_utc' => 23,
            'require_step_up' => true,
        ],
        $cookies
    );
    $smoke->assertStatus($policiesInvalid, 422, 'Policies invalid IP returns 422');

    $assignMissingReason = httpRequest(
        'POST',
        $baseUrl . '/api/v1/admin/user-roles/assign',
        $actionHeaders,
        [
            'user_id' => $targetUserId,
            'role_code' => 'user',
        ],
        $cookies
    );
    $smoke->assertStatus($assignMissingReason, 422, 'User role assign missing reason returns 422');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $smoke->assertTrue(false, 'Unhandled exception in HTTP smoke');
}

$smoke->summary();
exit($smoke->hasFailures() ? 1 : 0);
