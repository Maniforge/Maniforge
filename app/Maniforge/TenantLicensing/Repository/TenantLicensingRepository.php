<?php
declare(strict_types=1);

namespace App\Maniforge\TenantLicensing\Repository;

use App\Database\Connection;

final class TenantLicensingRepository
{
    public function findTenantPublic(string $code): ?array
    {
        return $this->findTenant($this->normalizeCode($code));
    }

    public function findSubtenantPublic(string $tenantCode, string $subtenantCode): ?array
    {
        $tenantCode = $this->normalizeCode($tenantCode);
        $subtenantCode = $this->normalizeCode($subtenantCode);
        if ($tenantCode === '' || $subtenantCode === '') {
            return null;
        }

        $stmt = Connection::get()->prepare(
            'SELECT tenant_code, code, name, status
             FROM maniforge_tl_subtenants
             WHERE tenant_code = :tenant_code AND code = :code
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_code' => $tenantCode,
            ':code' => $subtenantCode,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function listTenants(int $limit = 100): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT code, name, status, suspended_at, metadata_json, created_at, updated_at
             FROM maniforge_tl_tenants
             ORDER BY code ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function createTenant(string $code, string $name, string $actor, array $metadata = []): array
    {
        $code = $this->normalizeCode($code);
        if ($code === '' || $name === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'code и name обязательны'];
        }

        $pdo = Connection::get();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO maniforge_tl_tenants (code, name, metadata_json)
                 VALUES (:code, :name, :metadata_json)'
            );
            $stmt->execute([
                ':code' => $code,
                ':name' => $name,
                ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'tenant уже существует'];
            }
            throw $e;
        }

        $this->writeAudit('tenant.created', $actor, $code, null, ['name' => $name]);
        $this->enqueueEvent('tenant.created', $code, null, ['name' => $name]);

        return ['ok' => true, 'status' => 201, 'tenant' => $this->findTenant($code)];
    }

    public function updateTenant(string $code, array $changes, string $actor): array
    {
        $code = $this->normalizeCode($code);
        $tenant = $this->findTenant($code);
        if ($tenant === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'tenant не найден'];
        }

        $name = trim((string) ($changes['name'] ?? $tenant['name']));
        $status = trim((string) ($changes['status'] ?? $tenant['status']));
        if ($name === '' || !in_array($status, ['active', 'suspended', 'disabled'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Неверные name или status'];
        }

        $metadataMerged = null;
        if (array_key_exists('metadata', $changes) || array_key_exists('metadata_json', $changes)) {
            $patch = $changes['metadata'] ?? $changes['metadata_json'] ?? [];
            if (!is_array($patch)) {
                return ['ok' => false, 'status' => 422, 'error' => 'metadata должен быть объектом'];
            }
            $merge = $this->mergeTenantMetadata($code, $patch, $actor);
            if (($merge['ok'] ?? false) !== true) {
                return $merge;
            }
            $metadataMerged = $merge['metadata'] ?? null;
        }

        $suspendedAtSql = $status === 'suspended' ? 'UTC_TIMESTAMP()' : 'NULL';
        $stmt = Connection::get()->prepare(
            "UPDATE maniforge_tl_tenants
             SET name = :name, status = :status, suspended_at = {$suspendedAtSql}, updated_at = UTC_TIMESTAMP()
             WHERE code = :code"
        );
        $stmt->execute([
            ':name' => $name,
            ':status' => $status,
            ':code' => $code,
        ]);

        $payload = ['name' => $name, 'status' => $status, 'previous_status' => (string) $tenant['status']];
        if ($metadataMerged !== null) {
            $payload['metadata'] = $metadataMerged;
        }
        $this->writeAudit('tenant.updated', $actor, $code, null, $payload);
        if ($status !== (string) $tenant['status']) {
            $this->enqueueEvent('tenant.' . $status, $code, null, $payload);
        }

        return ['ok' => true, 'status' => 200, 'tenant' => $this->findTenant($code)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTenantMetadata(string $tenantCode): array
    {
        $tenant = $this->findTenant($this->normalizeCode($tenantCode));
        if ($tenant === null) {
            return [];
        }

        return $this->decodeJson((string) ($tenant['metadata_json'] ?? '{}'));
    }

    /**
     * @param array<string, mixed> $patch
     * @return array{ok: bool, status?: int, error?: string, metadata?: array<string, mixed>}
     */
    public function mergeTenantMetadata(string $tenantCode, array $patch, string $actor): array
    {
        $code = $this->normalizeCode($tenantCode);
        $tenant = $this->findTenant($code);
        if ($tenant === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'tenant не найден'];
        }

        $current = $this->decodeJson((string) ($tenant['metadata_json'] ?? '{}'));
        $merged = array_merge($current, $patch);

        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_tl_tenants
             SET metadata_json = :metadata_json, updated_at = UTC_TIMESTAMP()
             WHERE code = :code'
        );
        $stmt->execute([
            ':metadata_json' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            ':code' => $code,
        ]);

        $this->writeAudit('tenant.metadata.updated', $actor, $code, null, ['patch' => $patch]);

        return ['ok' => true, 'metadata' => $merged];
    }

    public function listSubtenants(string $tenantCode): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT tenant_code, code, name, status, metadata_json, created_at, updated_at
             FROM maniforge_tl_subtenants
             WHERE tenant_code = :tenant_code
             ORDER BY code ASC'
        );
        $stmt->execute([':tenant_code' => $this->normalizeCode($tenantCode)]);

        return $stmt->fetchAll();
    }

    public function listAllSubtenants(): array
    {
        $stmt = Connection::get()->query(
            'SELECT tenant_code, code, name, status, metadata_json, created_at, updated_at
             FROM maniforge_tl_subtenants
             ORDER BY tenant_code ASC, code ASC'
        );

        return $stmt->fetchAll();
    }

    public function createSubtenant(string $tenantCode, string $code, string $name, string $actor, array $metadata = []): array
    {
        $tenantCode = $this->normalizeCode($tenantCode);
        $code = $this->normalizeCode($code);
        $tenant = $this->findTenant($tenantCode);
        if ($tenant === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'tenant не найден'];
        }
        if ($code === '' || $name === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'code и name обязательны'];
        }
        if ($this->findSubtenant($tenantCode, $code) !== null) {
            return ['ok' => false, 'status' => 409, 'error' => 'subtenant уже существует'];
        }

        $subtenantLimit = $this->subtenantLimitForTenant($tenantCode);
        if ($subtenantLimit !== null) {
            $used = $this->countSubtenants($tenantCode);
            if ($used >= $subtenantLimit) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'error' => 'Превышен лимит subtenants по тарифу',
                    'limit' => $subtenantLimit,
                    'used' => $used,
                ];
            }
        }

        try {
            $stmt = Connection::get()->prepare(
                'INSERT INTO maniforge_tl_subtenants (tenant_id, tenant_code, code, name, metadata_json)
                 VALUES (:tenant_id, :tenant_code, :code, :name, :metadata_json)'
            );
            $stmt->execute([
                ':tenant_id' => (int) $tenant['id'],
                ':tenant_code' => $tenantCode,
                ':code' => $code,
                ':name' => $name,
                ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'subtenant уже существует'];
            }
            throw $e;
        }

        $this->writeAudit('subtenant.created', $actor, $tenantCode, $code, ['name' => $name]);
        $this->enqueueEvent('subtenant.created', $tenantCode, $code, ['name' => $name]);

        return ['ok' => true, 'status' => 201, 'subtenant' => $this->findSubtenant($tenantCode, $code)];
    }

    public function updateSubtenant(string $tenantCode, string $code, array $changes, string $actor): array
    {
        $tenantCode = $this->normalizeCode($tenantCode);
        $code = $this->normalizeCode($code);
        $subtenant = $this->findSubtenant($tenantCode, $code);
        if ($subtenant === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'subtenant не найден'];
        }

        $name = trim((string) ($changes['name'] ?? $subtenant['name']));
        $status = trim((string) ($changes['status'] ?? $subtenant['status']));
        if ($name === '' || !in_array($status, ['active', 'suspended', 'disabled'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Неверные name или status'];
        }

        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_tl_subtenants
             SET name = :name, status = :status, updated_at = UTC_TIMESTAMP()
             WHERE tenant_code = :tenant_code AND code = :code'
        );
        $stmt->execute([
            ':name' => $name,
            ':status' => $status,
            ':tenant_code' => $tenantCode,
            ':code' => $code,
        ]);

        $payload = ['name' => $name, 'status' => $status, 'previous_status' => (string) $subtenant['status']];
        $this->writeAudit('subtenant.updated', $actor, $tenantCode, $code, $payload);
        if ($status !== (string) $subtenant['status']) {
            $this->enqueueEvent('subtenant.' . $status, $tenantCode, $code, $payload);
        }

        return ['ok' => true, 'status' => 200, 'subtenant' => $this->findSubtenant($tenantCode, $code)];
    }

    public function listPlans(): array
    {
        $stmt = Connection::get()->query(
            'SELECT code, name, status, features_json, limits_json, created_at, updated_at
             FROM maniforge_tl_license_plans
             ORDER BY code ASC'
        );

        return $stmt->fetchAll();
    }

    public function createPlan(string $code, string $name, string $status, array $features, array $limits, string $actor): array
    {
        $code = $this->normalizeCode($code);
        if ($code === '' || $name === '' || !in_array($status, ['active', 'disabled'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'code, name и валидный status обязательны'];
        }

        try {
            $stmt = Connection::get()->prepare(
                'INSERT INTO maniforge_tl_license_plans (code, name, status, features_json, limits_json)
                 VALUES (:code, :name, :status, :features_json, :limits_json)'
            );
            $stmt->execute([
                ':code' => $code,
                ':name' => $name,
                ':status' => $status,
                ':features_json' => json_encode($features, JSON_UNESCAPED_UNICODE),
                ':limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'plan уже существует'];
            }
            throw $e;
        }

        $this->writeAudit('plan.created', $actor, '_platform', null, ['plan_code' => $code, 'name' => $name]);

        return ['ok' => true, 'status' => 201, 'plan' => $this->findPlan($code)];
    }

    public function updatePlan(string $code, array $changes, string $actor): array
    {
        $code = $this->normalizeCode($code);
        $plan = $this->findPlan($code);
        if ($plan === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'plan не найден'];
        }

        $name = trim((string) ($changes['name'] ?? $plan['name']));
        $status = trim((string) ($changes['status'] ?? $plan['status']));
        $features = is_array($changes['features'] ?? null)
            ? $changes['features']
            : $this->decodeJson((string) $plan['features_json']);
        $limits = is_array($changes['limits'] ?? null)
            ? $changes['limits']
            : $this->decodeJson((string) $plan['limits_json']);
        if ($name === '' || !in_array($status, ['active', 'disabled'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Неверные name или status'];
        }

        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_tl_license_plans
             SET name = :name, status = :status, features_json = :features_json, limits_json = :limits_json, updated_at = UTC_TIMESTAMP()
             WHERE code = :code'
        );
        $stmt->execute([
            ':name' => $name,
            ':status' => $status,
            ':features_json' => json_encode($features, JSON_UNESCAPED_UNICODE),
            ':limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE),
            ':code' => $code,
        ]);

        $this->writeAudit('plan.updated', $actor, '_platform', null, ['plan_code' => $code, 'status' => $status]);

        return ['ok' => true, 'status' => 200, 'plan' => $this->findPlan($code)];
    }

    public function upsertPlan(string $code, string $name, string $status, array $features, array $limits, string $actor): array
    {
        $code = $this->normalizeCode($code);
        $name = trim($name);
        $status = trim($status);
        if ($code === '' || $name === '' || !in_array($status, ['active', 'disabled'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Неверные code, name или status'];
        }

        $exists = $this->findPlan($code) !== null;
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_tl_license_plans (code, name, status, features_json, limits_json)
             VALUES (:code, :name, :status, :features_json, :limits_json)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                status = VALUES(status),
                features_json = VALUES(features_json),
                limits_json = VALUES(limits_json),
                updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':status' => $status,
            ':features_json' => json_encode($features, JSON_UNESCAPED_UNICODE),
            ':limits_json' => json_encode($limits, JSON_UNESCAPED_UNICODE),
        ]);

        $eventType = $exists ? 'plan.updated' : 'plan.created';
        $payload = ['code' => $code, 'name' => $name, 'status' => $status];
        $this->writeAudit($eventType, $actor, 'platform', null, $payload);
        $this->enqueueEvent($eventType, 'platform', null, $payload);

        return ['ok' => true, 'status' => $exists ? 200 : 201, 'plan' => $this->findPlan($code)];
    }

    public function listLicenses(int $limit = 100): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_code, plan_code, status, starts_at, expires_at, seats_max, assigned_by, created_at, updated_at
             FROM maniforge_tl_tenant_licenses
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function assignLicense(
        string $tenantCode,
        string $planCode,
        string $actor,
        ?string $expiresAt,
        ?int $seatsMax
    ): array {
        $tenantCode = $this->normalizeCode($tenantCode);
        $planCode = $this->normalizeCode($planCode);
        $tenant = $this->findTenant($tenantCode);
        $plan = $this->findPlan($planCode);
        if ($tenant === null || $plan === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'tenant или plan не найден'];
        }
        $normalizedExpiresAt = $this->normalizeExpiresAt($expiresAt);
        if (!$normalizedExpiresAt['ok']) {
            return ['ok' => false, 'status' => 422, 'error' => $normalizedExpiresAt['error']];
        }
        $expiresAt = $normalizedExpiresAt['value'];

        if ($seatsMax === null || $seatsMax <= 0) {
            $planLimits = $this->decodeJson((string) ($plan['limits_json'] ?? '{}'));
            $defaultSeats = (int) ($planLimits['max_users'] ?? 0);
            $seatsMax = $defaultSeats > 0 ? $defaultSeats : null;
        }

        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $close = $pdo->prepare(
                "UPDATE maniforge_tl_tenant_licenses
                 SET status = 'revoked', updated_at = UTC_TIMESTAMP()
                 WHERE tenant_code = :tenant_code AND status = 'active'"
            );
            $close->execute([':tenant_code' => $tenantCode]);

            $insert = $pdo->prepare(
                'INSERT INTO maniforge_tl_tenant_licenses
                    (tenant_id, tenant_code, plan_code, status, expires_at, seats_max, assigned_by)
                 VALUES
                    (:tenant_id, :tenant_code, :plan_code, :status, :expires_at, :seats_max, :assigned_by)'
            );
            $insert->execute([
                ':tenant_id' => (int) $tenant['id'],
                ':tenant_code' => $tenantCode,
                ':plan_code' => $planCode,
                ':status' => 'active',
                ':expires_at' => $expiresAt,
                ':seats_max' => $seatsMax,
                ':assigned_by' => $actor,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $payload = ['plan_code' => $planCode, 'expires_at' => $expiresAt, 'seats_max' => $seatsMax];
        $this->writeAudit('license.assigned', $actor, $tenantCode, null, $payload);
        $this->enqueueEvent('license.changed', $tenantCode, null, $payload);

        return ['ok' => true, 'status' => 200, 'license' => $this->activeLicense($tenantCode)];
    }

    public function updateLicense(int $licenseId, array $changes, string $actor): array
    {
        $license = $this->findLicense($licenseId);
        if ($license === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'license не найдена'];
        }

        $status = trim((string) ($changes['status'] ?? $license['status']));
        if (!in_array($status, ['active', 'suspended', 'revoked', 'expired'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Неверный status лицензии'];
        }

        $normalizedExpiresAt = $this->normalizeExpiresAt($changes['expires_at'] ?? $license['expires_at']);
        if (!$normalizedExpiresAt['ok']) {
            return ['ok' => false, 'status' => 422, 'error' => $normalizedExpiresAt['error']];
        }
        $seatsMax = array_key_exists('seats_max', $changes)
            ? (int) $changes['seats_max']
            : ($license['seats_max'] === null ? null : (int) $license['seats_max']);
        $seatsMax = $seatsMax !== null && $seatsMax > 0 ? $seatsMax : null;

        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_tl_tenant_licenses
             SET status = :status, expires_at = :expires_at, seats_max = :seats_max, updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':expires_at' => $normalizedExpiresAt['value'],
            ':seats_max' => $seatsMax,
            ':id' => $licenseId,
        ]);

        $tenantCode = (string) $license['tenant_code'];
        $payload = [
            'license_id' => $licenseId,
            'status' => $status,
            'previous_status' => (string) $license['status'],
            'expires_at' => $normalizedExpiresAt['value'],
            'seats_max' => $seatsMax,
        ];
        $this->writeAudit('license.updated', $actor, $tenantCode, null, $payload);
        $this->enqueueEvent($status === (string) $license['status'] ? 'license.changed' : 'license.' . $status, $tenantCode, null, $payload);

        return ['ok' => true, 'status' => 200, 'license' => $this->findLicense($licenseId)];
    }

    public function revokeLicense(string $tenantCode, string $actor, string $reason): array
    {
        $tenantCode = $this->normalizeCode($tenantCode);
        $stmt = Connection::get()->prepare(
            "UPDATE maniforge_tl_tenant_licenses
             SET status = 'revoked', updated_at = UTC_TIMESTAMP()
             WHERE tenant_code = :tenant_code AND status = 'active'"
        );
        $stmt->execute([':tenant_code' => $tenantCode]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'status' => 404, 'error' => 'active license не найдена'];
        }

        $payload = ['reason' => $reason];
        $this->writeAudit('license.revoked', $actor, $tenantCode, null, $payload);
        $this->enqueueEvent('license.revoked', $tenantCode, null, $payload);

        return ['ok' => true, 'status' => 200, 'revoked' => true];
    }

    public function expireDueLicenses(string $actor = 'system'): array
    {
        $stmt = Connection::get()->query(
            "SELECT id, tenant_code, plan_code, expires_at
             FROM maniforge_tl_tenant_licenses
             WHERE status = 'active'
               AND expires_at IS NOT NULL
               AND expires_at <= UTC_TIMESTAMP()
             ORDER BY expires_at ASC"
        );
        $licenses = $stmt->fetchAll();
        $expired = 0;

        foreach ($licenses as $license) {
            $update = Connection::get()->prepare(
                "UPDATE maniforge_tl_tenant_licenses
                 SET status = 'expired', updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = 'active'"
            );
            $update->execute([':id' => (int) $license['id']]);
            if ($update->rowCount() === 0) {
                continue;
            }

            $tenantCode = (string) $license['tenant_code'];
            $payload = [
                'plan_code' => (string) $license['plan_code'],
                'expires_at' => $license['expires_at'],
            ];
            $this->writeAudit('license.expired', $actor, $tenantCode, null, $payload);
            $this->enqueueEvent('license.expired', $tenantCode, null, $payload);
            $expired++;
        }

        return ['ok' => true, 'expired' => $expired, 'total_scanned' => count($licenses)];
    }

    public function entitlements(string $tenantCode): array
    {
        $license = $this->activeLicense($tenantCode);
        if ($license === null) {
            return ['features' => [], 'limits' => [], 'license' => null];
        }

        return [
            'features' => $this->decodeJson((string) ($license['features_json'] ?? '{}')),
            'limits' => $this->decodeJson((string) ($license['limits_json'] ?? '{}')),
            'license' => [
                'plan_code' => (string) $license['plan_code'],
                'status' => (string) $license['license_status'],
                'expires_at' => $license['expires_at'],
                'seats_max' => $license['seats_max'] === null ? null : (int) $license['seats_max'],
            ],
        ];
    }

    public function quota(string $tenantCode, ?string $metric = null): array
    {
        $sql = 'SELECT tenant_code, subtenant_code, metric, period_key, used, limit_snapshot, updated_at
                FROM maniforge_tl_quota_usage
                WHERE tenant_code = :tenant_code';
        $params = [':tenant_code' => $this->normalizeCode($tenantCode)];
        if ($metric !== null && $metric !== '') {
            $sql .= ' AND metric = :metric';
            $params[':metric'] = $metric;
        }
        $sql .= ' ORDER BY period_key DESC, metric ASC';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function accessState(string $tenantCode, string $subtenantCode): array
    {
        $tenantCode = $this->normalizeCode($tenantCode);
        $subtenantCode = $this->normalizeCode($subtenantCode);
        $tenant = $this->findTenant($tenantCode);
        $subtenant = $this->findSubtenant($tenantCode, $subtenantCode);
        $entitlements = $this->entitlements($tenantCode);
        $license = $entitlements['license'];
        $licenseActive = is_array($license)
            && ($license['status'] ?? '') === 'active'
            && (($license['expires_at'] ?? null) === null || strtotime((string) $license['expires_at']) > time());

        return [
            'ok' => true,
            'tenant_code' => $tenantCode,
            'subtenant_code' => $subtenantCode,
            'tenant_active' => is_array($tenant) && ($tenant['status'] ?? '') === 'active',
            'subtenant_active' => is_array($subtenant) && ($subtenant['status'] ?? '') === 'active',
            'license_active' => $licenseActive,
            'features' => $entitlements['features'],
            'limits' => $entitlements['limits'],
            'license' => $license,
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{
     *   tenants_total: int,
     *   tenants_active: int,
     *   subtenants_total: int,
     *   licenses_active: int,
     *   events_pending: int,
     *   events_pending_oldest_created_at: ?string,
     *   grants_active: int
     * }
     */
    public function platformOpsSummary(): array
    {
        $pdo = Connection::get();

        $tenantsTotal = (int) $pdo->query('SELECT COUNT(*) FROM maniforge_tl_tenants')->fetchColumn();
        $tenantsActive = (int) $pdo->query(
            "SELECT COUNT(*) FROM maniforge_tl_tenants WHERE status = 'active'"
        )->fetchColumn();
        $subtenantsTotal = (int) $pdo->query('SELECT COUNT(*) FROM maniforge_tl_subtenants')->fetchColumn();
        $licensesActive = (int) $pdo->query(
            "SELECT COUNT(*) FROM maniforge_tl_tenant_licenses WHERE status = 'active'"
        )->fetchColumn();
        $eventsPending = (int) $pdo->query(
            'SELECT COUNT(*) FROM maniforge_tl_events WHERE delivered_at IS NULL'
        )->fetchColumn();
        $oldest = $pdo->query(
            'SELECT MIN(created_at) AS oldest FROM maniforge_tl_events WHERE delivered_at IS NULL'
        )->fetch();
        $grantsActive = 0;
        try {
            $grantsActive = (int) $pdo->query(
                "SELECT COUNT(*) FROM maniforge_tl_tenant_grants WHERE status = 'active'"
            )->fetchColumn();
        } catch (\Throwable) {
            $grantsActive = 0;
        }

        return [
            'tenants_total' => $tenantsTotal,
            'tenants_active' => $tenantsActive,
            'subtenants_total' => $subtenantsTotal,
            'licenses_active' => $licensesActive,
            'events_pending' => $eventsPending,
            'events_pending_oldest_created_at' => is_array($oldest)
                ? ($oldest['oldest'] !== null ? (string) $oldest['oldest'] : null)
                : null,
            'grants_active' => $grantsActive,
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function pendingEvents(int $limit = 50): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, event_type, tenant_code, subtenant_code, payload_json, created_at
             FROM maniforge_tl_events
             WHERE delivered_at IS NULL
             ORDER BY id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function listEvents(?string $tenantCode = null, int $limit = 100): array
    {
        $sql = 'SELECT id, event_type, tenant_code, subtenant_code, payload_json, delivered_at, created_at
                FROM maniforge_tl_events';
        $params = [];
        if ($tenantCode !== null && $tenantCode !== '') {
            $sql .= ' WHERE tenant_code = :tenant_code';
            $params[':tenant_code'] = $this->normalizeCode($tenantCode);
        }
        $sql .= ' ORDER BY id DESC LIMIT :lim';

        $stmt = Connection::get()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', max(1, min($limit, 500)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function listAudit(?string $tenantCode = null, int $limit = 100): array
    {
        $sql = 'SELECT id, event_type, actor, tenant_code, subtenant_code, payload_json, created_at
                FROM maniforge_tl_audit_log';
        $params = [];
        if ($tenantCode !== null && $tenantCode !== '') {
            $sql .= ' WHERE tenant_code = :tenant_code';
            $params[':tenant_code'] = $this->normalizeCode($tenantCode);
        }
        $sql .= ' ORDER BY id DESC LIMIT :lim';

        $stmt = Connection::get()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', max(1, min($limit, 500)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function ackEvent(int $eventId): bool
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_tl_events SET delivered_at = UTC_TIMESTAMP() WHERE id = :id AND delivered_at IS NULL'
        );
        $stmt->execute([':id' => $eventId]);

        return $stmt->rowCount() > 0;
    }

    public function listManagedTenants(string $agencyCode, bool $activeOnly = true): array
    {
        $agencyCode = $this->normalizeCode($agencyCode);
        $sql = 'SELECT id, principal_tenant_code, managed_tenant_code, grant_level, status,
                       metadata_json, created_by, created_at, revoked_at
                FROM maniforge_tl_tenant_grants
                WHERE principal_tenant_code = :principal';
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' ORDER BY managed_tenant_code ASC';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute([':principal' => $agencyCode]);

        return $stmt->fetchAll();
    }

    public function createManagedTenantGrant(
        string $agencyCode,
        string $managedCode,
        string $grantLevel,
        string $actor,
        array $metadata = []
    ): array {
        $agencyCode = $this->normalizeCode($agencyCode);
        $managedCode = $this->normalizeCode($managedCode);
        $grantLevel = strtolower(trim($grantLevel));

        if ($agencyCode === '' || $managedCode === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'principal и managed tenant обязательны'];
        }
        if ($agencyCode === $managedCode) {
            return ['ok' => false, 'status' => 422, 'error' => 'Нельзя выдать grant на собственный tenant'];
        }
        if (!in_array($grantLevel, ['operator', 'admin', 'read_only'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'grant_level: operator | admin | read_only'];
        }

        $principal = $this->findTenant($agencyCode);
        $managed = $this->findTenant($managedCode);
        if ($principal === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'agency tenant не найден'];
        }
        if ($managed === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'managed tenant не найден'];
        }

        $existing = $this->findGrant($agencyCode, $managedCode);
        $needsSlot = $existing === null || (string) ($existing['status'] ?? '') !== 'active';
        if ($needsSlot) {
            $managedLimit = $this->managedTenantLimitForPrincipal($agencyCode);
            if ($managedLimit !== null) {
                $used = $this->countActiveManagedGrants($agencyCode);
                if ($used >= $managedLimit) {
                    return [
                        'ok' => false,
                        'status' => 403,
                        'error' => 'Превышен лимит managed tenants по тарифу principal',
                        'limit' => $managedLimit,
                        'used' => $used,
                    ];
                }
            }
        }

        try {
            $stmt = Connection::get()->prepare(
                "INSERT INTO maniforge_tl_tenant_grants (
                    principal_tenant_code, managed_tenant_code, grant_level, status, metadata_json, created_by
                 ) VALUES (
                    :principal, :managed, :grant_level, 'active', :metadata_json, :created_by
                 )"
            );
            $stmt->execute([
                ':principal' => $agencyCode,
                ':managed' => $managedCode,
                ':grant_level' => $grantLevel,
                ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                ':created_by' => $actor,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $reactivate = Connection::get()->prepare(
                    "UPDATE maniforge_tl_tenant_grants
                     SET grant_level = :grant_level, status = 'active', metadata_json = :metadata_json,
                         created_by = :created_by, revoked_at = NULL
                     WHERE principal_tenant_code = :principal AND managed_tenant_code = :managed"
                );
                $reactivate->execute([
                    ':grant_level' => $grantLevel,
                    ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    ':created_by' => $actor,
                    ':principal' => $agencyCode,
                    ':managed' => $managedCode,
                ]);
                if ($reactivate->rowCount() === 0) {
                    return ['ok' => false, 'status' => 409, 'error' => 'grant уже существует'];
                }
            } else {
                throw $e;
            }
        }

        $grant = $this->findGrant($agencyCode, $managedCode);
        $payload = [
            'managed_tenant_code' => $managedCode,
            'grant_level' => $grantLevel,
            'metadata' => $metadata,
        ];
        $this->writeAudit('agency_grant.created', $actor, $agencyCode, null, $payload);
        $this->enqueueEvent('agency_grant.created', $agencyCode, null, $payload);

        return ['ok' => true, 'status' => 201, 'grant' => $grant];
    }

    public function revokeManagedTenantGrant(string $agencyCode, string $managedCode, string $actor): array
    {
        $agencyCode = $this->normalizeCode($agencyCode);
        $managedCode = $this->normalizeCode($managedCode);
        $grant = $this->findGrant($agencyCode, $managedCode);
        if ($grant === null || ($grant['status'] ?? '') !== 'active') {
            return ['ok' => false, 'status' => 404, 'error' => 'active grant не найден'];
        }

        $stmt = Connection::get()->prepare(
            "UPDATE maniforge_tl_tenant_grants
             SET status = 'revoked', revoked_at = UTC_TIMESTAMP()
             WHERE principal_tenant_code = :principal AND managed_tenant_code = :managed AND status = 'active'"
        );
        $stmt->execute([
            ':principal' => $agencyCode,
            ':managed' => $managedCode,
        ]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'status' => 404, 'error' => 'active grant не найден'];
        }

        $payload = ['managed_tenant_code' => $managedCode];
        $this->writeAudit('agency_grant.revoked', $actor, $agencyCode, null, $payload);
        $this->enqueueEvent('agency_grant.revoked', $agencyCode, null, $payload);

        return ['ok' => true, 'status' => 200, 'revoked' => true];
    }

    private function findGrant(string $agencyCode, string $managedCode): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_tl_tenant_grants
             WHERE principal_tenant_code = :principal AND managed_tenant_code = :managed
             LIMIT 1'
        );
        $stmt->execute([
            ':principal' => $this->normalizeCode($agencyCode),
            ':managed' => $this->normalizeCode($managedCode),
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function countSubtenants(string $tenantCode): int
    {
        $stmt = Connection::get()->prepare(
            'SELECT COUNT(*) FROM maniforge_tl_subtenants WHERE tenant_code = :tenant_code'
        );
        $stmt->execute([':tenant_code' => $this->normalizeCode($tenantCode)]);

        return (int) $stmt->fetchColumn();
    }

    private function subtenantLimitForTenant(string $tenantCode): ?int
    {
        $license = $this->activeLicense($tenantCode);
        if ($license === null) {
            return null;
        }

        $limits = $this->decodeJson((string) ($license['limits_json'] ?? '{}'));
        if (!array_key_exists('max_subtenants', $limits)) {
            return null;
        }

        return max(0, (int) $limits['max_subtenants']);
    }

    private function countActiveManagedGrants(string $principalCode): int
    {
        $stmt = Connection::get()->prepare(
            "SELECT COUNT(*) FROM maniforge_tl_tenant_grants
             WHERE principal_tenant_code = :principal AND status = 'active'"
        );
        $stmt->execute([':principal' => $this->normalizeCode($principalCode)]);

        return (int) $stmt->fetchColumn();
    }

    private function managedTenantLimitForPrincipal(string $principalCode): ?int
    {
        $license = $this->activeLicense($principalCode);
        if ($license === null) {
            return null;
        }

        $limits = $this->decodeJson((string) ($license['limits_json'] ?? '{}'));
        if (!array_key_exists('max_tenants', $limits)) {
            return null;
        }

        return max(0, (int) $limits['max_tenants']);
    }

    private function findTenant(string $code): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM maniforge_tl_tenants WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function findSubtenant(string $tenantCode, string $code): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_tl_subtenants WHERE tenant_code = :tenant_code AND code = :code LIMIT 1'
        );
        $stmt->execute([':tenant_code' => $tenantCode, ':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function findPlan(string $code): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM maniforge_tl_license_plans WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function findLicense(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM maniforge_tl_tenant_licenses WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function activeLicense(string $tenantCode): ?array
    {
        $stmt = Connection::get()->prepare(
            "SELECT l.*, l.status AS license_status, p.features_json, p.limits_json
             FROM maniforge_tl_tenant_licenses l
             INNER JOIN maniforge_tl_license_plans p ON p.code = l.plan_code
             WHERE l.tenant_code = :tenant_code
               AND l.status = 'active'
             ORDER BY l.id DESC
             LIMIT 1"
        );
        $stmt->execute([':tenant_code' => $this->normalizeCode($tenantCode)]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function writeAudit(string $eventType, string $actor, string $tenantCode, ?string $subtenantCode, array $payload): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_tl_audit_log (event_type, actor, tenant_code, subtenant_code, payload_json)
             VALUES (:event_type, :actor, :tenant_code, :subtenant_code, :payload_json)'
        );
        $stmt->execute([
            ':event_type' => $eventType,
            ':actor' => $actor,
            ':tenant_code' => $tenantCode,
            ':subtenant_code' => $subtenantCode,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function enqueueEvent(string $eventType, string $tenantCode, ?string $subtenantCode, array $payload): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_tl_events (event_type, tenant_code, subtenant_code, payload_json)
             VALUES (:event_type, :tenant_code, :subtenant_code, :payload_json)'
        );
        $stmt->execute([
            ':event_type' => $eventType,
            ':tenant_code' => $tenantCode,
            ':subtenant_code' => $subtenantCode,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeExpiresAt(?string $expiresAt): array
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return ['ok' => true, 'value' => null];
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            return ['ok' => false, 'error' => 'expires_at должен быть валидной датой/временем'];
        }

        return ['ok' => true, 'value' => gmdate('Y-m-d H:i:s', $timestamp)];
    }

    private function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }
}
