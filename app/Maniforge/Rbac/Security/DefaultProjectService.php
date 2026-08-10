<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\ProjectRepository;
use App\Maniforge\Rbac\Support\EntityScope;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

final class DefaultProjectService
{
    public function __construct(
        private readonly ProjectRepository $projects = new ProjectRepository(),
        private readonly TenantLicensingRepository $licensing = new TenantLicensingRepository(),
    ) {
    }

    /**
     * Tenant-level main (subtenant_id = '').
     *
     * @return array<string, mixed>
     */
    public function ensureDefaultTenant(string $tenantId): array
    {
        $tenantId = strtolower(trim($tenantId));
        if ($tenantId === '') {
            throw new \InvalidArgumentException('tenant_id обязателен');
        }

        if ($this->licensing->findTenantPublic($tenantId) === null) {
            throw new \RuntimeException("Tenant {$tenantId} не найден");
        }

        $existing = $this->projects->findDefaultByTenant($tenantId);
        if ($existing !== null) {
            return $existing;
        }

        $byCode = $this->projects->findByCodeInScope($tenantId, '', EntityScope::DEFAULT_PROJECT_CODE, true);
        if ($byCode !== null && (string) ($byCode['subtenant_id'] ?? '') === '') {
            $this->projects->markAsDefault((int) $byCode['id']);

            return $this->projects->findById((int) $byCode['id']) ?? $byCode;
        }

        return $this->projects->create(
            $tenantId,
            '',
            EntityScope::DEFAULT_PROJECT_CODE,
            EntityScope::DEFAULT_PROJECT_NAME . ' (tenant)',
            ['bootstrap' => 'default_tenant_project', 'project_scope' => EntityScope::PROJECT_SCOPE_TENANT],
            null,
            true
        );
    }

    /**
     * Subtenant-level main.
     *
     * @return array<string, mixed>
     */
    public function ensureDefaultSubtenant(string $tenantId, string $subtenantId): array
    {
        $tenantId = strtolower(trim($tenantId));
        $subtenantId = strtolower(trim($subtenantId));
        if ($tenantId === '' || $subtenantId === '') {
            throw new \InvalidArgumentException('tenant_id и subtenant_id обязательны');
        }

        if ($this->licensing->findSubtenantPublic($tenantId, $subtenantId) === null) {
            throw new \RuntimeException("Subtenant {$subtenantId} не найден в tenant {$tenantId}");
        }

        $existing = $this->projects->findDefaultBySubtenant($tenantId, $subtenantId);
        if ($existing !== null) {
            return $existing;
        }

        $byCode = $this->projects->findByCodeInScope(
            $tenantId,
            $subtenantId,
            EntityScope::DEFAULT_PROJECT_CODE,
            false
        );
        if ($byCode !== null) {
            $this->projects->markAsDefault((int) $byCode['id']);

            return $this->projects->findById((int) $byCode['id']) ?? $byCode;
        }

        return $this->projects->create(
            $tenantId,
            $subtenantId,
            EntityScope::DEFAULT_PROJECT_CODE,
            EntityScope::DEFAULT_PROJECT_NAME,
            ['bootstrap' => 'default_subtenant_project', 'project_scope' => EntityScope::PROJECT_SCOPE_SUBTENANT],
            null,
            true
        );
    }

    /** @deprecated alias */
    public function ensureDefault(string $tenantId, string $subtenantId): array
    {
        return $this->ensureDefaultSubtenant($tenantId, $subtenantId);
    }

    /**
     * project_id для сессии: явный в сессии или default main subtenant.
     */
    public function resolveSessionProjectId(array $session): int
    {
        $explicit = isset($session['project_id']) && $session['project_id'] !== null && $session['project_id'] !== ''
            ? (int) $session['project_id']
            : 0;
        if ($explicit > 0) {
            $project = $this->projects->findById($explicit);
            if (
                $project !== null
                && (string) $project['tenant_id'] === (string) $session['tenant_id']
                && $this->projectAccessibleInSession($project, (string) $session['subtenant_id'])
            ) {
                return $explicit;
            }
        }

        $subtenantId = (string) $session['subtenant_id'];
        if ($subtenantId !== '') {
            return (int) $this->ensureDefaultSubtenant((string) $session['tenant_id'], $subtenantId)['id'];
        }

        return (int) $this->ensureDefaultTenant((string) $session['tenant_id'])['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sessionProject(array $session): ?array
    {
        $id = $this->resolveSessionProjectId($session);

        return $this->projects->findById($id);
    }

    public function isTenantLevelProject(array $project): bool
    {
        return (string) ($project['subtenant_id'] ?? '') === '';
    }

    /**
     * @param array<string, mixed> $project
     */
    public function projectAccessibleInSession(array $project, string $sessionSubtenant): bool
    {
        $pSub = (string) ($project['subtenant_id'] ?? '');
        if ($pSub === '') {
            return true;
        }

        return $pSub === $sessionSubtenant;
    }
}
