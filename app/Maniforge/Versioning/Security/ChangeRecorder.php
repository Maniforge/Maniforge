<?php
declare(strict_types=1);

namespace App\Maniforge\Versioning\Security;

use App\Maniforge\Versioning\Repository\VersioningRepository;

final class ChangeRecorder
{
    public function __construct(
        private readonly VersioningRepository $versions = new VersioningRepository(),
    ) {
    }

    /**
     * @param array{tenant_id: string, subtenant_id?: string, project_id?: int|null, actor_user_id?: int|null, correlation_id?: string|null} $scope
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        array $scope,
        string $entityTable,
        string $entityId,
        string $operation,
        ?array $before,
        ?array $after,
        ?string $entityLabel = null
    ): ?int {
        if (!$this->isEnabled()) {
            return null;
        }

        $operation = strtolower(trim($operation));
        if (!in_array($operation, ['insert', 'update', 'delete'], true)) {
            return null;
        }

        if (!$this->versions->isTableTracked($entityTable)) {
            return null;
        }

        try {
            return $this->versions->insertChange([
                'tenant_id' => (string) $scope['tenant_id'],
                'subtenant_id' => (string) ($scope['subtenant_id'] ?? ''),
                'project_id' => $scope['project_id'] ?? null,
                'entity_table' => $entityTable,
                'entity_id' => $entityId,
                'entity_label' => $entityLabel,
                'operation' => $operation,
                'actor_user_id' => $scope['actor_user_id'] ?? null,
                'correlation_id' => $scope['correlation_id'] ?? null,
                'before' => $this->redact($before),
                'after' => $this->redact($after),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private function redact(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $copy = $payload;
        foreach (['password_hash', 'password', 'token', 'refresh_token', 'access_token', 'session_secret_hash', 'phone', 'email'] as $key) {
            if (array_key_exists($key, $copy)) {
                $copy[$key] = '[redacted]';
            }
        }

        return $copy;
    }

    private function isEnabled(): bool
    {
        $raw = strtolower(trim((string) ($_ENV['VERSIONING_ENABLED'] ?? 'true')));

        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }
}
