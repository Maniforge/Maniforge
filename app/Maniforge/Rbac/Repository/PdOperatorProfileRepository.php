<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class PdOperatorProfileRepository
{
    public function find(string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_pd_operator_profiles WHERE tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    public function upsert(string $tenantId, array $fields): array
    {
        $existing = $this->find($tenantId);
        if ($existing === null) {
            $stmt = Connection::get()->prepare(
                'INSERT INTO maniforge_pd_operator_profiles (
                    tenant_id, operator_name, operator_inn, operator_address,
                    dpo_name, dpo_email, dpo_phone, privacy_policy_url, privacy_policy_version,
                    data_storage_region, cross_border_transfer_allowed, cross_border_basis,
                    roskomnadzor_notified_at, metadata_json
                ) VALUES (
                    :tenant_id, :operator_name, :operator_inn, :operator_address,
                    :dpo_name, :dpo_email, :dpo_phone, :privacy_policy_url, :privacy_policy_version,
                    :data_storage_region, :cross_border_transfer_allowed, :cross_border_basis,
                    :roskomnadzor_notified_at, :metadata_json
                )'
            );
        } else {
            $stmt = Connection::get()->prepare(
                'UPDATE maniforge_pd_operator_profiles SET
                    operator_name = :operator_name,
                    operator_inn = :operator_inn,
                    operator_address = :operator_address,
                    dpo_name = :dpo_name,
                    dpo_email = :dpo_email,
                    dpo_phone = :dpo_phone,
                    privacy_policy_url = :privacy_policy_url,
                    privacy_policy_version = :privacy_policy_version,
                    data_storage_region = :data_storage_region,
                    cross_border_transfer_allowed = :cross_border_transfer_allowed,
                    cross_border_basis = :cross_border_basis,
                    roskomnadzor_notified_at = :roskomnadzor_notified_at,
                    metadata_json = :metadata_json
                 WHERE tenant_id = :tenant_id'
            );
        }

        $params = $this->bindParams($tenantId, $fields, $existing);
        $stmt->execute($params);

        return $this->find($tenantId) ?? [];
    }

    private function bindParams(string $tenantId, array $fields, ?array $existing = null): array
    {
        $meta = $fields['metadata'] ?? $fields['metadata_json'] ?? null;
        if ($meta === null && $existing !== null) {
            $meta = $existing['metadata'] ?? null;
        }

        return [
            ':tenant_id' => $tenantId,
            ':operator_name' => trim((string) ($fields['operator_name'] ?? '')),
            ':operator_inn' => $this->nullableString($fields['operator_inn'] ?? null),
            ':operator_address' => $this->nullableString($fields['operator_address'] ?? null),
            ':dpo_name' => $this->nullableString($fields['dpo_name'] ?? null),
            ':dpo_email' => $this->nullableString($fields['dpo_email'] ?? null),
            ':dpo_phone' => $this->nullableString($fields['dpo_phone'] ?? null),
            ':privacy_policy_url' => $this->nullableString($fields['privacy_policy_url'] ?? null),
            ':privacy_policy_version' => trim((string) ($fields['privacy_policy_version'] ?? '1.0')) ?: '1.0',
            ':data_storage_region' => strtoupper(trim((string) ($fields['data_storage_region'] ?? 'RU'))) ?: 'RU',
            ':cross_border_transfer_allowed' => filter_var(
                $fields['cross_border_transfer_allowed'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ) ? 1 : 0,
            ':cross_border_basis' => $this->nullableString($fields['cross_border_basis'] ?? null),
            ':roskomnadzor_notified_at' => $this->nullableDate($fields['roskomnadzor_notified_at'] ?? null),
            ':metadata_json' => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
        ];
    }

    private function normalize(array $row): array
    {
        $meta = $row['metadata_json'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $row['metadata'] = is_array($decoded) ? $decoded : null;
        }
        unset($row['metadata_json']);

        return $row;
    }

    private function nullableString(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private function nullableDate(mixed $value): ?string
    {
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
