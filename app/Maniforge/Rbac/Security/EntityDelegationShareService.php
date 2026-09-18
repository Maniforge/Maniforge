<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Database\Connection;

/** Доступ сущности владельца для peer-tenant по grant (родитель ↔ клиент). */
final class EntityDelegationShareService
{
    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, tenant_ids?: list<string>|null, error?: string}
     */
    public function resolveForOwner(string $ownerTenantId, array $input): array
    {
        $ownerTenantId = strtolower(trim($ownerTenantId));
        if ($ownerTenantId === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'owner tenant обязателен'];
        }

        $explicit = $this->parseExplicitList($input);
        if (($explicit['ok'] ?? false) !== true) {
            return $explicit;
        }

        $codes = $explicit['items'] ?? [];

        if (filter_var($input['share_with_principal'] ?? $input['shareWithPrincipal'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            foreach ($this->principalPeersForManaged($ownerTenantId) as $principal) {
                $codes[] = $principal;
            }
        }

        $managedFlag = $input['share_with_managed'] ?? $input['shareWithManaged'] ?? null;
        if ($managedFlag !== null && $managedFlag !== false) {
            if (is_array($managedFlag)) {
                foreach ($managedFlag as $code) {
                    $code = strtolower(trim((string) $code));
                    if ($code !== '') {
                        $codes[] = $code;
                    }
                }
            } elseif (filter_var($managedFlag, FILTER_VALIDATE_BOOLEAN)) {
                foreach ($this->managedPeersForPrincipal($ownerTenantId) as $managed) {
                    $codes[] = $managed;
                }
            }
        }

        $codes = array_values(array_unique(array_filter($codes, static fn (string $c): bool => $c !== '' && $c !== $ownerTenantId)));

        if ($codes === []) {
            return ['ok' => true, 'status' => 200, 'tenant_ids' => null];
        }

        foreach ($codes as $peer) {
            if (!$this->hasActiveGrant($ownerTenantId, $peer)) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'error' => "Нет активного grant между {$ownerTenantId} и {$peer}",
                ];
            }
        }

        return ['ok' => true, 'status' => 200, 'tenant_ids' => $codes];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, items?: list<string>|null, error?: string}
     */
    private function parseExplicitList(array $input): array
    {
        $keys = [
            'delegation_share_tenant_ids',
            'delegationShareTenantIds',
            'shared_grant_tenant_ids',
            'sharedGrantTenantIds',
        ];
        $raw = null;
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $raw = $input[$key];
                break;
            }
        }

        if ($raw === null) {
            return ['ok' => true, 'status' => 200, 'items' => []];
        }
        if (is_array($raw) && $raw === []) {
            return ['ok' => true, 'status' => 200, 'items' => []];
        }
        if (!is_array($raw)) {
            return ['ok' => false, 'status' => 422, 'error' => 'delegation_share_tenant_ids должен быть массивом кодов tenant'];
        }

        $items = [];
        foreach ($raw as $code) {
            $code = strtolower(trim((string) $code));
            if ($code !== '') {
                $items[] = $code;
            }
        }

        return ['ok' => true, 'status' => 200, 'items' => $items];
    }

    /** @return list<string> */
    public function listActiveGrantPeers(string $ownerTenantId): array
    {
        $ownerTenantId = strtolower(trim($ownerTenantId));
        $stmt = Connection::get()->prepare(
            "SELECT principal_tenant_code, managed_tenant_code
             FROM maniforge_tl_tenant_grants
             WHERE status = 'active'
               AND (principal_tenant_code = :owner_p OR managed_tenant_code = :owner_m)"
        );
        $stmt->execute([
            ':owner_p' => $ownerTenantId,
            ':owner_m' => $ownerTenantId,
        ]);
        $peers = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $principal = strtolower((string) ($row['principal_tenant_code'] ?? ''));
            $managed = strtolower((string) ($row['managed_tenant_code'] ?? ''));
            $peer = $principal === $ownerTenantId ? $managed : $principal;
            if ($peer !== '' && $peer !== $ownerTenantId) {
                $peers[] = $peer;
            }
        }

        return array_values(array_unique($peers));
    }

    public function hasActiveGrant(string $tenantA, string $tenantB): bool
    {
        $tenantA = strtolower(trim($tenantA));
        $tenantB = strtolower(trim($tenantB));
        if ($tenantA === '' || $tenantB === '' || $tenantA === $tenantB) {
            return false;
        }

        $stmt = Connection::get()->prepare(
            "SELECT 1 FROM maniforge_tl_tenant_grants
             WHERE status = 'active'
               AND principal_tenant_code = :principal AND managed_tenant_code = :managed
             LIMIT 1"
        );
        $stmt->execute([':principal' => $tenantA, ':managed' => $tenantB]);
        if ($stmt->fetch() !== false) {
            return true;
        }

        $stmt->execute([':principal' => $tenantB, ':managed' => $tenantA]);

        return $stmt->fetch() !== false;
    }

    /**
     * @param list<string>|null $tenantIds
     */
    public function encodeJson(?array $tenantIds): ?string
    {
        if ($tenantIds === null || $tenantIds === []) {
            return null;
        }

        return json_encode(array_values($tenantIds), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>|null
     */
    public function decodeJson(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw === [] ? null : array_values($raw);
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== [] ? array_values($decoded) : null;
    }

    /** @return list<string> */
    private function principalPeersForManaged(string $managedTenantId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT principal_tenant_code FROM maniforge_tl_tenant_grants
             WHERE status = 'active' AND managed_tenant_code = :managed"
        );
        $stmt->execute([':managed' => strtolower(trim($managedTenantId))]);

        return array_map(
            static fn (array $row): string => strtolower((string) ($row['principal_tenant_code'] ?? '')),
            array_filter($stmt->fetchAll() ?: [], 'is_array')
        );
    }

    /** @return list<string> */
    private function managedPeersForPrincipal(string $principalTenantId): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT managed_tenant_code FROM maniforge_tl_tenant_grants
             WHERE status = 'active' AND principal_tenant_code = :principal"
        );
        $stmt->execute([':principal' => strtolower(trim($principalTenantId))]);

        return array_map(
            static fn (array $row): string => strtolower((string) ($row['managed_tenant_code'] ?? '')),
            array_filter($stmt->fetchAll() ?: [], 'is_array')
        );
    }
}
