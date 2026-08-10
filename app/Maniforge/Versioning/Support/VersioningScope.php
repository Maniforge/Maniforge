<?php
declare(strict_types=1);

namespace App\Maniforge\Versioning\Support;

use App\Maniforge\Versioning\Security\ChangeRecorder;

final class VersioningScope
{
    /**
     * @return array{tenant_id: string, subtenant_id: string, project_id: int|null, actor_user_id: int|null, correlation_id: string|null}
     */
    public static function fromSession(array $session, ?int $projectId = null): array
    {
        $sessionProjectId = isset($session['project_id']) && $session['project_id'] !== null
            ? (int) $session['project_id']
            : null;

        return [
            'tenant_id' => (string) ($session['tenant_id'] ?? ''),
            'subtenant_id' => (string) ($session['subtenant_id'] ?? ''),
            'project_id' => $projectId ?? $sessionProjectId,
            'actor_user_id' => isset($session['user_id']) ? (int) $session['user_id'] : null,
            'correlation_id' => null,
        ];
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(
        ChangeRecorder $recorder,
        array $session,
        string $entityTable,
        string $entityId,
        string $operation,
        ?array $before,
        ?array $after,
        ?string $entityLabel = null,
        ?int $projectId = null
    ): ?int {
        return $recorder->record(
            self::fromSession($session, $projectId),
            $entityTable,
            $entityId,
            $operation,
            $before,
            $after,
            $entityLabel
        );
    }
}
