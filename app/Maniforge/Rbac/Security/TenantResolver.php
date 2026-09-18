<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

/**
 * Tenant/subtenant на границе запроса нужен только до выдачи сессии (login).
 * После login scope живёт в access_token → maniforge_sessions.
 */
final class TenantResolver
{
    /** @var list<string> */
    private const TENANT_AT_EDGE_PATHS = [
        '/api/v1/auth/login',
        '/api/v1/auth/register',
    ];

    public function resolve(array $server, string $path, array $input = []): array
    {
        $mode = strtolower((string) ($_ENV['TENANCY_MODE'] ?? 'single'));
        $defaultTenant = strtolower(trim((string) ($_ENV['DEFAULT_TENANT_ID'] ?? 'default')));
        $defaultSubtenant = strtolower(trim((string) ($_ENV['DEFAULT_SUBTENANT_ID'] ?? 'default')));
        $normalizedPath = '/' . ltrim($path, '/');

        if (!$this->requiresTenantAtEdge($normalizedPath)) {
            return [
                'ok' => true,
                'mode' => $mode,
                'tenant_id' => '',
                'subtenant_id' => '',
                'context_key' => '',
                'scope_source' => 'session',
            ];
        }

        if ($mode === 'disabled') {
            return $this->okContext($mode, $defaultTenant, $defaultSubtenant, 'env_default');
        }

        if ($mode === 'single') {
            return $this->okContext($mode, $defaultTenant, $defaultSubtenant, 'env_default');
        }

        if ($normalizedPath === '/api/v1/auth/login' && $this->hasPhoneCredential($input)) {
            return [
                'ok' => true,
                'mode' => $mode,
                'tenant_id' => '',
                'subtenant_id' => '',
                'context_key' => '',
                'scope_source' => 'phone_login',
            ];
        }

        $tenantId = strtolower(trim((string) ($server['HTTP_X_TENANT_ID'] ?? '')));
        $subtenantId = strtolower(trim((string) ($server['HTTP_X_SUBTENANT_ID'] ?? '')));

        if ($tenantId === '' && is_array($input)) {
            $tenantId = strtolower(trim((string) ($input['tenant_id'] ?? '')));
        }
        if ($subtenantId === '' && is_array($input)) {
            $subtenantId = strtolower(trim((string) ($input['subtenant_id'] ?? '')));
        }

        if ($tenantId === '' || $subtenantId === '') {
            return [
                'ok' => false,
                'status' => 400,
                'error' => 'Для login в multi режиме укажите X-Tenant-ID и X-Subtenant-ID (или tenant_id/subtenant_id в теле)',
                'code' => 'tenant_context_required',
            ];
        }

        return $this->okContext($mode, $tenantId, $subtenantId, 'request');
    }

    public function requiresTenantAtEdge(string $path): bool
    {
        $path = '/' . ltrim($path, '/');

        return in_array($path, self::TENANT_AT_EDGE_PATHS, true);
    }

    private function okContext(string $mode, string $tenantId, string $subtenantId, string $scopeSource): array
    {
        return [
            'ok' => true,
            'mode' => $mode,
            'tenant_id' => $tenantId,
            'subtenant_id' => $subtenantId,
            'context_key' => $tenantId . '::' . $subtenantId,
            'scope_source' => $scopeSource,
        ];
    }

    private function hasPhoneCredential(array $input): bool
    {
        if (trim((string) ($input['phone'] ?? '')) !== '') {
            return true;
        }

        $prefix = trim((string) ($input['phone_prefix'] ?? ''));
        $number = trim((string) ($input['phone_number'] ?? ($input['phone_local'] ?? '')));

        return $prefix !== '' || $number !== '';
    }
}
