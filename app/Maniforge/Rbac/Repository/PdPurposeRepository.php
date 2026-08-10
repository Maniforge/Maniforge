<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class PdPurposeRepository
{
    private const LEGAL_BASES = ['consent', 'contract', 'legal_obligation', 'legitimate_interest'];

    public function listActive(string $tenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_id, code, title, description, legal_basis, retention_days,
                    is_mandatory_for_registration, is_active, policy_version, created_at, updated_at
             FROM maniforge_pd_processing_purposes
             WHERE tenant_id = :tenant_id AND is_active = 1
             ORDER BY code ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }

    public function listAll(string $tenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_id, code, title, description, legal_basis, retention_days,
                    is_mandatory_for_registration, is_active, policy_version, created_at, updated_at
             FROM maniforge_pd_processing_purposes
             WHERE tenant_id = :tenant_id
             ORDER BY code ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findByCode(string $tenantId, string $code): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_pd_processing_purposes
             WHERE tenant_id = :tenant_id AND code = :code LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function listMandatoryForRegistration(string $tenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT code, policy_version, title, legal_basis
             FROM maniforge_pd_processing_purposes
             WHERE tenant_id = :tenant_id AND is_active = 1 AND is_mandatory_for_registration = 1'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll() ?: [];
    }

    public function create(string $tenantId, array $input): array
    {
        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        $legalBasis = strtolower(trim((string) ($input['legal_basis'] ?? 'consent')));
        if (!in_array($legalBasis, self::LEGAL_BASES, true)) {
            $legalBasis = 'consent';
        }

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_pd_processing_purposes (
                tenant_id, code, title, description, legal_basis, retention_days,
                is_mandatory_for_registration, is_active, policy_version
            ) VALUES (
                :tenant_id, :code, :title, :description, :legal_basis, :retention_days,
                :is_mandatory_for_registration, :is_active, :policy_version
            )'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':code' => $code,
            ':title' => trim((string) ($input['title'] ?? $code)),
            ':description' => trim((string) ($input['description'] ?? '')) ?: null,
            ':legal_basis' => $legalBasis,
            ':retention_days' => isset($input['retention_days']) ? (int) $input['retention_days'] : null,
            ':is_mandatory_for_registration' => filter_var($input['is_mandatory_for_registration'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            ':is_active' => filter_var($input['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            ':policy_version' => trim((string) ($input['policy_version'] ?? '1.0')) ?: '1.0',
        ]);

        return $this->findByCode($tenantId, $code) ?? [];
    }

    public function update(string $tenantId, string $code, array $input): ?array
    {
        $existing = $this->findByCode($tenantId, $code);
        if ($existing === null) {
            return null;
        }

        $sets = [];
        $params = [':tenant_id' => $tenantId, ':code' => $code];

        foreach ([
            'title' => 'string',
            'description' => 'nullable_string',
            'legal_basis' => 'legal_basis',
            'retention_days' => 'int_nullable',
            'is_mandatory_for_registration' => 'bool',
            'is_active' => 'bool',
            'policy_version' => 'string',
        ] as $field => $type) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            if ($type === 'legal_basis') {
                $lb = strtolower(trim((string) $input[$field]));
                if (!in_array($lb, self::LEGAL_BASES, true)) {
                    continue;
                }
                $sets[] = 'legal_basis = :legal_basis';
                $params[':legal_basis'] = $lb;
                continue;
            }
            if ($type === 'bool') {
                $sets[] = "{$field} = :{$field}";
                $params[":{$field}"] = filter_var($input[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                continue;
            }
            if ($type === 'int_nullable') {
                $sets[] = 'retention_days = :retention_days';
                $params[':retention_days'] = $input[$field] === null ? null : (int) $input[$field];
                continue;
            }
            if ($type === 'nullable_string') {
                $v = trim((string) $input[$field]);
                $sets[] = "{$field} = :{$field}";
                $params[":{$field}"] = $v === '' ? null : $v;
                continue;
            }
            $sets[] = "{$field} = :{$field}";
            $params[":{$field}"] = trim((string) $input[$field]);
        }

        if ($sets === []) {
            return $existing;
        }

        $sql = 'UPDATE maniforge_pd_processing_purposes SET ' . implode(', ', $sets)
            . ' WHERE tenant_id = :tenant_id AND code = :code';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return $this->findByCode($tenantId, $code);
    }

    public function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';

        return trim($code, '_');
    }
}
