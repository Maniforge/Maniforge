<?php
declare(strict_types=1);

final class JourneyHttpAsserts
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
        $this->assertTrue(
            $actual >= 200 && $actual < 300,
            "{$message} (actual={$actual}, body=" . substr($response['raw'] ?? '', 0, 200) . ')'
        );
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertTrue(
            $expected === $actual,
            $message . ' (expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true) . ')'
        );
    }

    public function summary(string $label = 'HTTP journey'): void
    {
        fwrite(STDOUT, "\n{$label} summary: ok={$this->ok}, fail={$this->fail}\n");
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

function journeyEnv(string $key, string $default): string
{
    $value = trim((string) (getenv($key) ?: ($_ENV[$key] ?? '')));

    return $value === '' ? $default : $value;
}

/**
 * @return array<string, mixed>
 */
function journeyLoginSession(array $body): array
{
    $session = $body['session'] ?? $body['credentials']['session'] ?? [];

    return is_array($session) ? $session : [];
}

/**
 * @return array{0: string, 1: array<int, string>}
 */
function journeyAuthFromLogin(array $loginBody, array $tenantHeaders): array
{
    $session = journeyLoginSession($loginBody);
    $accessToken = (string) ($session['access_token'] ?? '');
    $csrfToken = (string) ($loginBody['csrf_token'] ?? '');

    return [
        $accessToken,
        array_merge($tenantHeaders, [
            'Authorization: Bearer ' . $accessToken,
            'X-CSRF-Token: ' . $csrfToken,
        ]),
    ];
}

/**
 * @return array{0: string, 1: array<int, string>}
 */
function journeyReauth(string $rbacBase, array $authHeaders, string $password, array &$cookies): array
{
    $reauth = journeyHttp('POST', $rbacBase . '/api/v1/auth/reauth', $authHeaders, [
        'password' => $password,
    ], $cookies);
    $actionToken = (string) ($reauth['body']['credentials']['action']['action_token'] ?? '');

    return [
        $actionToken,
        array_merge($authHeaders, ['X-Action-Token: ' . $actionToken]),
    ];
}

function journeyRandomPhone(string $prefix = '+7900'): string
{
    return $prefix . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
}
