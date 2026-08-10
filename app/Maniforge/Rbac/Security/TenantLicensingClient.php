<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\TenantAccessCacheRepository;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

final class TenantLicensingClient
{
    public function __construct(
        private readonly TenantAccessCacheRepository $cache = new TenantAccessCacheRepository(),
    ) {
    }

    public function assertAccess(string $tenantCode, string $subtenantCode): array
    {
        $mode = strtolower((string) ($_ENV['TENANT_LICENSING_ENFORCEMENT'] ?? 'optional'));
        if ($mode === 'disabled') {
            return ['ok' => true, 'source' => 'disabled'];
        }

        $tenantCode = strtolower(trim($tenantCode));
        $subtenantCode = strtolower(trim($subtenantCode));
        $ttl = max(5, (int) ($_ENV['TENANT_LICENSING_CACHE_TTL_SEC'] ?? 60));

        try {
            $state = $this->fetchAccessState($tenantCode, $subtenantCode);
            $this->tryPutCache($tenantCode, $subtenantCode, $state, $ttl);

            return $this->decision($state, 'live');
        } catch (\Throwable $e) {
            $cached = $this->tryGetCache($tenantCode, $subtenantCode);
            if ($cached !== null) {
                return $this->decision($cached, 'cache');
            }

            if ($mode === 'optional') {
                return ['ok' => true, 'source' => 'optional', 'warning' => 'tenant licensing unavailable'];
            }

            return [
                'ok' => false,
                'status' => 503,
                'temporary' => true,
                'error' => 'Tenant/Licensing service недоступен',
            ];
        }
    }

    public function assertUserActivationAllowed(
        string $tenantCode,
        string $subtenantCode,
        int $activeUsers,
        bool $alreadyActive = false
    ): array {
        $access = $this->assertAccess($tenantCode, $subtenantCode);
        if (($access['ok'] ?? false) !== true) {
            return $access;
        }
        if ($alreadyActive) {
            return $access + ['seats' => ['enforced' => false, 'reason' => 'already_active']];
        }

        $state = is_array($access['state'] ?? null) ? $access['state'] : [];
        $license = is_array($state['license'] ?? null) ? $state['license'] : [];
        $seatsMax = $license['seats_max'] ?? null;
        if ($seatsMax === null || (int) $seatsMax <= 0) {
            return $access + ['seats' => ['enforced' => false]];
        }

        if ($activeUsers >= (int) $seatsMax) {
            return [
                'ok' => false,
                'status' => 402,
                'source' => (string) ($access['source'] ?? 'unknown'),
                'error' => 'Лимит активных пользователей по лицензии исчерпан',
                'deny_reason' => 'seats_quota_exceeded',
                'seats' => [
                    'active_users' => $activeUsers,
                    'seats_max' => (int) $seatsMax,
                ],
            ];
        }

        return $access + [
            'seats' => [
                'enforced' => true,
                'active_users' => $activeUsers,
                'seats_max' => (int) $seatsMax,
            ],
        ];
    }

    private function fetchAccessState(string $tenantCode, string $subtenantCode): array
    {
        $baseUrl = rtrim((string) ($_ENV['TENANT_LICENSING_INTERNAL_URL'] ?? ''), '/');
        if ($baseUrl === '') {
            return (new TenantLicensingRepository())->accessState($tenantCode, $subtenantCode);
        }

        $url = $baseUrl
            . '/internal/v1/tenants/' . rawurlencode($tenantCode)
            . '/subtenants/' . rawurlencode($subtenantCode)
            . '/access-state';
        $headers = ['Accept: application/json'];
        $token = (string) ($_ENV['TENANT_LICENSING_INTERNAL_TOKEN'] ?? '');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => max(1, (int) ($_ENV['TENANT_LICENSING_TIMEOUT_SEC'] ?? 2)),
                'ignore_errors' => true,
            ],
        ]);
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('Tenant/Licensing request failed');
        }
        $statusCode = $this->httpStatusCode($http_response_header ?? []);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException("Tenant/Licensing returned HTTP {$statusCode}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Tenant/Licensing returned invalid JSON');
        }
        if (($decoded['ok'] ?? false) !== true) {
            throw new \RuntimeException('Tenant/Licensing returned unsuccessful response');
        }

        return $decoded;
    }

    private function decision(array $state, string $source): array
    {
        if (($state['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => 503,
                'temporary' => true,
                'source' => $source,
                'error' => 'Tenant/Licensing state недоступен',
            ];
        }

        if (($state['tenant_active'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => 403,
                'source' => $source,
                'error' => 'Tenant не активен',
                'deny_reason' => 'tenant_not_active',
            ];
        }

        if (($state['subtenant_active'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => 403,
                'source' => $source,
                'error' => 'Subtenant не активен',
                'deny_reason' => 'subtenant_not_active',
            ];
        }

        if (($state['license_active'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => 402,
                'source' => $source,
                'error' => 'Лицензия tenant недействительна',
                'deny_reason' => 'license_not_active',
            ];
        }

        return ['ok' => true, 'source' => $source, 'state' => $state];
    }

    private function tryGetCache(string $tenantCode, string $subtenantCode): ?array
    {
        try {
            return $this->cache->get($tenantCode, $subtenantCode);
        } catch (\Throwable) {
            return null;
        }
    }

    private function tryPutCache(string $tenantCode, string $subtenantCode, array $state, int $ttlSeconds): void
    {
        try {
            $this->cache->put($tenantCode, $subtenantCode, $state, $ttlSeconds);
        } catch (\Throwable) {
            // Access decisions must not depend on cache table availability.
        }
    }

    private function httpStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 200;
    }
}
