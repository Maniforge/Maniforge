<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

function httpJourneyUsage(): void
{
    fwrite(STDOUT, "Usage: php maniforge/rbac/tools/new_user_http_journey.php [--help]\n");
    fwrite(STDOUT, "Env (optional):\n");
    fwrite(STDOUT, "  NEW_USER_BASE_URL=http://127.0.0.1:8092/rbac\n");
    fwrite(STDOUT, "  NEW_USER_TL_URL=http://127.0.0.1:8092/tenant-licensing\n");
    fwrite(STDOUT, "  NEW_USER_VER_URL=http://127.0.0.1:8092/versioning\n");
}

if (in_array('--help', $argv, true)) {
    httpJourneyUsage();
    exit(0);
}

final class HttpJourneyAsserts
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

    public function assert2xx(array $response, string $message): void
    {
        $actual = (int) ($response['status'] ?? 0);
        $this->assertTrue($actual >= 200 && $actual < 300, "{$message} (actual={$actual}, body=" . substr($response['raw'] ?? '', 0, 200) . ')');
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertTrue(
            $expected === $actual,
            $message . ' (expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true) . ')'
        );
    }

    public function summary(): void
    {
        fwrite(STDOUT, "\nHTTP journey summary: ok={$this->ok}, fail={$this->fail}\n");
    }

    public function hasFailures(): bool
    {
        return $this->fail > 0;
    }
}

/**
 * @return array{status:int, body:array, raw:string}
 */
function journeyHttp(string $method, string $url, array $headers = [], ?array $payload = null, array &$cookies = []): array
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
            'timeout' => 30,
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

    return ['status' => $status, 'body' => $body, 'raw' => $raw];
}

$rbacBase = rtrim((string) (getenv('NEW_USER_BASE_URL') ?: ($_ENV['NEW_USER_BASE_URL'] ?? 'http://127.0.0.1:8092/rbac')), '/');
$tlBase = rtrim((string) (getenv('NEW_USER_TL_URL') ?: ($_ENV['NEW_USER_TL_URL'] ?? 'http://127.0.0.1:8092/tenant-licensing')), '/');
$verBase = rtrim((string) (getenv('NEW_USER_VER_URL') ?: ($_ENV['NEW_USER_VER_URL'] ?? 'http://127.0.0.1:8092/versioning')), '/');

$suffix = substr(bin2hex(random_bytes(6)), 0, 8);
$adminLogin = 'http_nu_' . $suffix;
$memberLogin = 'http_mem_' . $suffix;
$password = 'HttpNewUserJourney!123';
$phoneAdmin = '+7900' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
$phoneMember = '+7901' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
$projectCode = 'http_proj_' . $suffix;
$tenantId = '';
$subtenantId = 'main';

$assert = new HttpJourneyAsserts();
$cookies = [];

try {
    $rbacHealth = journeyHttp('GET', $rbacBase . '/health', [], null, $cookies);
    $assert->assertStatus($rbacHealth, 200, 'RBAC health');
    $tlHealth = journeyHttp('GET', $tlBase . '/health', [], null, $cookies);
    $assert->assertStatus($tlHealth, 200, 'Tenant Licensing health');
    $verHealth = journeyHttp('GET', $verBase . '/health', [], null, $cookies);
    $assert->assertStatus($verHealth, 200, 'Versioning health');

    $register = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => $phoneAdmin,
        'email' => $adminLogin . '@example.test',
        'organization_name' => 'HTTP Journey Org',
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($register, 201, 'Register new tenant');
    $assert->assertTrue((bool) ($register['body']['ok'] ?? false), 'Register ok=true');
    $assert->assertTrue(!array_key_exists('login', $register['body']['user'] ?? []), 'Register response omits login');
    $assert->assertSame($phoneAdmin, (string) ($register['body']['user']['phone'] ?? ''), 'Register returns phone');

    $dupRegister = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => $phoneAdmin,
        'email' => 'dup_' . $adminLogin . '@example.test',
        'organization_name' => 'Duplicate HTTP Org',
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($dupRegister, 409, 'Duplicate phone registration blocked');
    $assert->assertSame(
        'phone_already_registered',
        (string) ($dupRegister['body']['code'] ?? ''),
        'Duplicate phone error code'
    );
    $tenantId = (string) ($register['body']['tenant']['tenant_id'] ?? '');
    $subtenantId = (string) ($register['body']['tenant']['subtenant_id'] ?? 'main');
    $assert->assertTrue($tenantId !== '', 'Tenant id in register response');

    $tenantHeaders = [
        'X-Tenant-ID: ' . $tenantId,
        'X-Subtenant-ID: ' . $subtenantId,
    ];

    $loginOnly = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'login' => $adminLogin,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($loginOnly, 422, 'Login by login field rejected');

    $badLogin = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $phoneAdmin,
        'password' => 'WrongPassword!000',
    ], $cookies);
    $assert->assertStatus($badLogin, 401, 'Wrong password returns 401');

    $login = journeyHttp('POST', $rbacBase . '/api/v1/auth/login', $tenantHeaders, [
        'phone' => $phoneAdmin,
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($login, 200, 'Login by phone');
    $session = $login['body']['session'] ?? $login['body']['credentials']['session'] ?? [];
    $accessToken = (string) ($session['access_token'] ?? '');
    $csrfToken = (string) ($login['body']['csrf_token'] ?? '');
    $assert->assertTrue($accessToken !== '', 'Access token received');

    $authHeaders = array_merge($tenantHeaders, [
        'Authorization: Bearer ' . $accessToken,
        'X-CSRF-Token: ' . $csrfToken,
    ]);
    $sessionHeaders = array_merge($tenantHeaders, ['Authorization: Bearer ' . $accessToken]);

    $me = journeyHttp('GET', $rbacBase . '/api/v1/me', $sessionHeaders, null, $cookies);
    $assert->assertStatus($me, 200, 'GET /me');
    $assert->assertTrue((int) ($me['body']['session']['user_id'] ?? 0) > 0, 'Me returns session user_id');

    $profile = journeyHttp('GET', $rbacBase . '/api/v1/me/profile', $sessionHeaders, null, $cookies);
    $assert->assertStatus($profile, 200, 'GET /me/profile');
    $assert->assertSame($phoneAdmin, (string) ($profile['body']['user']['phone'] ?? ''), 'Profile returns correct phone');
    $assert->assertTrue(!array_key_exists('login', $profile['body']['user'] ?? []), 'Profile omits login');

    $privacy = journeyHttp('GET', $rbacBase . '/api/v1/privacy/notice', $tenantHeaders, null, $cookies);
    $assert->assertStatus($privacy, 200, 'GET privacy notice');
    $assert->assertTrue((bool) ($privacy['body']['ok'] ?? false), 'Privacy notice ok=true');

    $reauth = journeyHttp('POST', $rbacBase . '/api/v1/auth/reauth', $authHeaders, [
        'password' => $password,
    ], $cookies);
    $assert->assertStatus($reauth, 200, 'Reauth for mutating actions');
    $actionToken = (string) ($reauth['body']['credentials']['action']['action_token'] ?? '');
    $assert->assertTrue($actionToken !== '', 'Action token received');
    $actionHeaders = array_merge($authHeaders, ['X-Action-Token: ' . $actionToken]);

    foreach (['/api/v1/me/permissions', '/api/v1/me/contexts', '/api/v1/me/access', '/api/v1/me/console-access'] as $path) {
        $resp = journeyHttp('GET', $rbacBase . $path, $sessionHeaders, null, $cookies);
        $assert->assertStatus($resp, 200, 'GET ' . $path);
        $assert->assertTrue((bool) ($resp['body']['ok'] ?? false), $path . ' ok=true');
    }

    $contexts = journeyHttp('GET', $rbacBase . '/api/v1/me/contexts', $sessionHeaders, null, $cookies);
    $orgIds = array_map(
        static fn (array $row): string => (string) ($row['tenant_id'] ?? ''),
        $contexts['body']['organizations'] ?? []
    );
    $assert->assertTrue(in_array($tenantId, $orgIds, true), 'Contexts organizations include tenant');

    $accessState = journeyHttp(
        'GET',
        $tlBase . '/internal/v1/tenants/' . rawurlencode($tenantId) . '/subtenants/' . rawurlencode($subtenantId) . '/access-state',
        [],
        null,
        $cookies
    );
    $assert->assertStatus($accessState, 200, 'Internal access-state for new tenant');
    $assert->assertTrue((bool) ($accessState['body']['license_active'] ?? false), 'License active in access-state');

    $createProject = journeyHttp('POST', $rbacBase . '/api/v1/projects', $actionHeaders, [
        'code' => $projectCode,
        'name' => 'HTTP Journey Project',
        'metadata' => ['source' => 'new_user_http_journey'],
    ], $cookies);
    $assert->assert2xx($createProject, 'Create project');
    $assert->assertTrue((bool) ($createProject['body']['ok'] ?? false), 'Create project ok=true');

    $listProjects = journeyHttp('GET', $rbacBase . '/api/v1/projects', $sessionHeaders, null, $cookies);
    $assert->assertStatus($listProjects, 200, 'List projects');

    $globalVar = journeyHttp('POST', $rbacBase . '/api/v1/global-variables', $actionHeaders, [
        'key' => 'stage',
        'value' => 'http-journey',
        'scope_level' => 'subtenant',
    ], $cookies);
    $assert->assert2xx($globalVar, 'Create global variable');

    $invite = journeyHttp('POST', $rbacBase . '/api/v1/admin/registration-invites', $actionHeaders, [
        'invite_type' => 'user',
        'role_code' => 'user',
        'reason' => 'new_user_http_journey',
    ], $cookies);
    $assert->assertTrue(in_array((int) $invite['status'], [200, 201], true), 'Create registration invite 2xx');
    $inviteToken = (string) ($invite['body']['invite_token'] ?? '');
    $assert->assertTrue($inviteToken !== '', 'Invite token received');

    $memberRegister = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => $phoneMember,
        'email' => $memberLogin . '@example.test',
        'invite_token' => $inviteToken,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($memberRegister, 201, 'Member register via invite');
    $assert->assertTrue(!array_key_exists('login', $memberRegister['body']['user'] ?? []), 'Member register omits login');

    $inviteReuse = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => '+7907' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        'invite_token' => $inviteToken,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($inviteReuse, 409, 'Reused invite token returns 409');
    $assert->assertSame('invite_already_used', (string) ($inviteReuse['body']['code'] ?? ''), 'Invite reuse error code');

    $manualJoin = journeyHttp('POST', $rbacBase . '/api/v1/auth/register', [], [
        'password' => $password,
        'phone' => '+7904' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        'tenant_id' => $tenantId,
        'subtenant_id' => $subtenantId,
        'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
    ], $cookies);
    $assert->assertStatus($manualJoin, 403, 'Manual tenant join without invite blocked');

    $changes = journeyHttp('GET', $verBase . '/api/v1/changes?entity_table=maniforge_users&limit=20', $sessionHeaders, null, $cookies);
    $assert->assertStatus($changes, 200, 'Versioning changes list');
    $assert->assertTrue(count($changes['body']['items'] ?? []) >= 2, 'Versioning has user changes');

    $registry = journeyHttp('GET', $verBase . '/api/v1/registry', $sessionHeaders, null, $cookies);
    $assert->assertStatus($registry, 200, 'Versioning registry');

    $refreshToken = (string) ($session['refresh_token'] ?? '');
    if ($refreshToken !== '') {
        $refresh = journeyHttp('POST', $rbacBase . '/api/v1/auth/refresh', $tenantHeaders, [
            'refresh_token' => $refreshToken,
        ], $cookies);
        $assert->assertStatus($refresh, 200, 'Refresh session');
        $refreshed = $refresh['body']['session'] ?? $refresh['body']['credentials']['session'] ?? [];
        if (($refreshed['access_token'] ?? '') !== '') {
            $accessToken = (string) $refreshed['access_token'];
            $authHeaders = array_merge($tenantHeaders, [
                'Authorization: Bearer ' . $accessToken,
                'X-CSRF-Token: ' . $csrfToken,
            ]);
        }
    }

    $logout = journeyHttp('POST', $rbacBase . '/api/v1/auth/logout', $authHeaders, [], $cookies);
    $assert->assertTrue(in_array((int) $logout['status'], [200, 404], true), 'Logout completes');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    $assert->assertTrue(false, 'Unhandled exception in HTTP journey');
}

$assert->summary();

if ($tenantId !== '') {
    fwrite(STDOUT, "\nCreated test tenant: {$tenantId} (subtenant: {$subtenantId})\n");
    fwrite(STDOUT, "Admin login: {$adminLogin}, phone: {$phoneAdmin}\n");
}

exit($assert->hasFailures() ? 1 : 0);
