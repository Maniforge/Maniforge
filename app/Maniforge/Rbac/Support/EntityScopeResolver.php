<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Support;

use App\Maniforge\Rbac\Repository\ProjectRepository;
use App\Maniforge\Rbac\Security\DefaultProjectService;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

/** Разрешение scope: tenant-project и subtenant-project + visibility сущностей. */
final class EntityScopeResolver
{
    public function __construct(
        private readonly DefaultProjectService $defaultProjects = new DefaultProjectService(),
        private readonly ProjectRepository $projects = new ProjectRepository(),
        private readonly TenantLicensingRepository $licensing = new TenantLicensingRepository(),
        private readonly RbacService $rbac = new RbacService(),
    ) {
    }

    /**
     * Чтение stocks другого tenant: активный grant + delegation_share + проект сессии (по code).
     */
    public function stockDelegatedReadSql(string $alias = 's', string $keyPrefix = ''): string
    {
        $a = $alias;
        $p = $this->paramPrefix($keyPrefix);

        return "(
            {$a}.tenant_id <> :{$p}deleg_viewer_tenant_a
            AND {$a}.shared_grant_tenant_ids_json IS NOT NULL
            AND JSON_CONTAINS({$a}.shared_grant_tenant_ids_json, JSON_QUOTE(:{$p}deleg_viewer_tenant_b))
            AND EXISTS (
                SELECT 1 FROM maniforge_tl_tenant_grants g
                WHERE g.status = 'active'
                  AND (
                      (g.principal_tenant_code = {$a}.tenant_id AND g.managed_tenant_code = :{$p}deleg_viewer_tenant_c)
                      OR (g.managed_tenant_code = {$a}.tenant_id AND g.principal_tenant_code = :{$p}deleg_viewer_tenant_d)
                  )
            )
            AND (
                {$a}.scope_visibility = 'tenant'
                OR (
                    {$a}.scope_visibility = 'subtenant'
                    AND {$a}.subtenant_id = :{$p}deleg_viewer_subtenant
                )
                OR (
                    {$a}.scope_visibility = 'project'
                    AND (
                        {$a}.project_id IS NULL
                        OR EXISTS (
                            SELECT 1 FROM maniforge_projects po, maniforge_projects ps
                            WHERE po.id = {$a}.project_id
                              AND ps.id = :{$p}deleg_session_project
                              AND ps.tenant_id = :{$p}deleg_viewer_tenant_e
                              AND po.code = ps.code
                        )
                    )
                )
            )
        )";
    }

    /**
     * @return array<string, mixed>
     */
    public function stockDelegatedReadParams(array $session, int $projectId, string $keyPrefix = ''): array
    {
        $viewer = (string) $session['tenant_id'];
        $p = $this->paramPrefix($keyPrefix);

        return [
            ':' . $p . 'deleg_viewer_tenant_a' => $viewer,
            ':' . $p . 'deleg_viewer_tenant_b' => $viewer,
            ':' . $p . 'deleg_viewer_tenant_c' => $viewer,
            ':' . $p . 'deleg_viewer_tenant_d' => $viewer,
            ':' . $p . 'deleg_viewer_tenant_e' => $viewer,
            ':' . $p . 'deleg_viewer_subtenant' => (string) $session['subtenant_id'],
            ':' . $p . 'deleg_session_project' => $projectId,
        ];
    }

    /**
     * Видимость сущностей с полями scope_visibility (stocks, products, movements, …).
     */
    public function stockVisibilitySql(string $alias = 's', string $keyPrefix = ''): string
    {
        $a = $alias;
        $p = $this->paramPrefix($keyPrefix);

        return "(
            ({$a}.scope_visibility = :{$p}vis_project AND {$a}.project_id = :{$p}scope_project)
            OR ({$a}.scope_visibility = :{$p}vis_subtenant AND {$a}.subtenant_id = :{$p}scope_subtenant_s)
            OR (
                {$a}.scope_visibility = :{$p}vis_tenant
                AND {$a}.subtenant_id = ''
                AND (
                    {$a}.shared_subtenant_ids_json IS NULL
                    OR JSON_CONTAINS({$a}.shared_subtenant_ids_json, JSON_QUOTE(:{$p}scope_subtenant_t))
                )
            )
        )";
    }

    /**
     * @return array<string, mixed>
     */
    public function stockVisibilityParams(array $session, int $projectId, string $keyPrefix = ''): array
    {
        $subtenant = (string) $session['subtenant_id'];
        $p = $this->paramPrefix($keyPrefix);

        return [
            ':' . $p . 'scope_subtenant_s' => $subtenant,
            ':' . $p . 'scope_subtenant_t' => $subtenant,
            ':' . $p . 'scope_project' => $projectId,
            ':' . $p . 'vis_project' => EntityScope::VISIBILITY_PROJECT,
            ':' . $p . 'vis_subtenant' => EntityScope::VISIBILITY_SUBTENANT,
            ':' . $p . 'vis_tenant' => EntityScope::VISIBILITY_TENANT,
        ];
    }

    private function paramPrefix(string $keyPrefix): string
    {
        return $keyPrefix === '' ? '' : $keyPrefix . '_';
    }

    public function sessionProjectId(array $session): int
    {
        return $this->defaultProjects->resolveSessionProjectId($session);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   ok: bool,
     *   status: int,
     *   tenant_id?: string,
     *   subtenant_id?: string,
     *   project_id?: int|null,
     *   scope_visibility?: string,
     *   shared_subtenant_ids?: list<string>|null,
     *   error?: string
     * }
     */
    public function resolveStockWriteScope(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $sessionSubtenant = (string) $session['subtenant_id'];
        $userId = (int) $session['user_id'];

        $visibility = strtolower(trim((string) ($input['scope_visibility'] ?? $input['visibility'] ?? EntityScope::VISIBILITY_PROJECT)));
        if (!in_array($visibility, EntityScope::visibilityValues(), true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'scope_visibility: project|subtenant|tenant'];
        }

        $shared = $this->parseSharedSubtenants($input);
        if (($shared['ok'] ?? false) !== true) {
            return $shared;
        }
        $sharedIds = $shared['items'] ?? null;

        if ($visibility === EntityScope::VISIBILITY_TENANT) {
            if (!$this->canTargetSubtenant($userId, $tenantId, $sessionSubtenant)) {
                return ['ok' => false, 'status' => 403, 'error' => 'Tenant-wide сущности требуют tenant_admin'];
            }

            return [
                'ok' => true,
                'status' => 200,
                'tenant_id' => $tenantId,
                'subtenant_id' => '',
                'project_id' => null,
                'scope_visibility' => EntityScope::VISIBILITY_TENANT,
                'shared_subtenant_ids' => $sharedIds,
            ];
        }

        if ($visibility === EntityScope::VISIBILITY_SUBTENANT) {
            if (!$this->canTargetSubtenant($userId, $tenantId, $sessionSubtenant)) {
                return ['ok' => false, 'status' => 403, 'error' => 'Subtenant-wide сущности требуют subtenant_admin или выше'];
            }

            $targetSubtenant = $this->resolveTargetSubtenant($session, $input, $tenantId, $sessionSubtenant, $userId);
            if (($targetSubtenant['ok'] ?? false) !== true) {
                return $targetSubtenant;
            }

            return [
                'ok' => true,
                'status' => 200,
                'tenant_id' => $tenantId,
                'subtenant_id' => (string) $targetSubtenant['subtenant_id'],
                'project_id' => null,
                'scope_visibility' => EntityScope::VISIBILITY_SUBTENANT,
                'shared_subtenant_ids' => null,
            ];
        }

        $projectResolved = $this->resolveTargetProject($session, $input, $tenantId, $sessionSubtenant, $userId);
        if (($projectResolved['ok'] ?? false) !== true) {
            return $projectResolved;
        }

        $project = $projectResolved['project'];
        $projectId = (int) $project['id'];
        $pSub = (string) ($project['subtenant_id'] ?? '');
        $stockSubtenant = $pSub !== '' ? $pSub : '';

        return [
            'ok' => true,
            'status' => 200,
            'tenant_id' => $tenantId,
            'subtenant_id' => $stockSubtenant,
            'project_id' => $projectId,
            'scope_visibility' => EntityScope::VISIBILITY_PROJECT,
            'shared_subtenant_ids' => null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, project?: array, error?: string}
     */
    private function resolveTargetProject(
        array $session,
        array $input,
        string $tenantId,
        string $sessionSubtenant,
        int $userId,
    ): array {
        $atTenantLevel = filter_var($input['tenant_level'] ?? $input['tenant_project'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $projectId = 0;
        if (isset($input['project_id']) || isset($input['projectId'])) {
            $raw = $input['project_id'] ?? $input['projectId'];
            $projectId = $raw === null || $raw === '' ? 0 : (int) $raw;
        }

        $projectCode = strtolower(trim((string) ($input['project_code'] ?? '')));

        if ($projectId > 0) {
            $project = $this->projects->findById($projectId);
            if ($project === null || (string) $project['tenant_id'] !== $tenantId) {
                return ['ok' => false, 'status' => 422, 'error' => 'project_id вне tenant'];
            }
            if (!$this->defaultProjects->projectAccessibleInSession($project, $sessionSubtenant)) {
                return ['ok' => false, 'status' => 403, 'error' => 'Проект недоступен в subtenant сессии'];
            }

            return ['ok' => true, 'status' => 200, 'project' => $project];
        }

        if ($projectCode !== '') {
            if ($atTenantLevel) {
                $project = $this->projects->findByCodeInScope($tenantId, '', $projectCode, true);
                if ($project === null || (string) ($project['subtenant_id'] ?? '') !== '') {
                    return ['ok' => false, 'status' => 422, 'error' => 'Tenant-проект не найден'];
                }
            } else {
                $targetSub = $this->resolveTargetSubtenant($session, $input, $tenantId, $sessionSubtenant, $userId);
                if (($targetSub['ok'] ?? false) !== true) {
                    return ['ok' => false, 'status' => (int) ($targetSub['status'] ?? 422), 'error' => (string) ($targetSub['error'] ?? '')];
                }
                $project = $this->projects->findByCodeInScope(
                    $tenantId,
                    (string) $targetSub['subtenant_id'],
                    $projectCode,
                    true
                );
                if ($project === null) {
                    return ['ok' => false, 'status' => 422, 'error' => 'Subtenant-проект не найден'];
                }
            }

            return ['ok' => true, 'status' => 200, 'project' => $project];
        }

        if ($atTenantLevel) {
            if (!$this->rbac->hasAnyRole($userId, $tenantId, $sessionSubtenant, ['super_admin', 'tenant_admin'])) {
                return ['ok' => false, 'status' => 403, 'error' => 'Tenant-проект требует tenant_admin'];
            }

            return [
                'ok' => true,
                'status' => 200,
                'project' => $this->defaultProjects->ensureDefaultTenant($tenantId),
            ];
        }

        $sessionProject = $this->defaultProjects->sessionProject($session);
        if ($sessionProject !== null) {
            return ['ok' => true, 'status' => 200, 'project' => $sessionProject];
        }

        if ($sessionSubtenant === '') {
            return [
                'ok' => true,
                'status' => 200,
                'project' => $this->defaultProjects->ensureDefaultTenant($tenantId),
            ];
        }

        $targetSub = $this->resolveTargetSubtenant($session, $input, $tenantId, $sessionSubtenant, $userId);
        if (($targetSub['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => (int) ($targetSub['status'] ?? 422), 'error' => (string) ($targetSub['error'] ?? '')];
        }

        return [
            'ok' => true,
            'status' => 200,
            'project' => $this->defaultProjects->ensureDefaultSubtenant($tenantId, (string) $targetSub['subtenant_id']),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, subtenant_id?: string, error?: string}
     */
    private function resolveTargetSubtenant(
        array $session,
        array $input,
        string $tenantId,
        string $sessionSubtenant,
        int $userId,
    ): array {
        $targetSubtenant = strtolower(trim((string) (
            $input['target_subtenant_id']
            ?? $input['subtenant_id']
            ?? $sessionSubtenant
        )));
        if ($targetSubtenant === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'subtenant_id обязателен для subtenant-scope'];
        }

        if ($targetSubtenant !== $sessionSubtenant && !$this->canTargetSubtenant($userId, $tenantId, $sessionSubtenant)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Другой subtenant доступен tenant_admin'];
        }

        if ($this->licensing->findSubtenantPublic($tenantId, $targetSubtenant) === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'Subtenant не найден в tenant'];
        }

        return ['ok' => true, 'status' => 200, 'subtenant_id' => $targetSubtenant];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, items?: list<string>|null, error?: string}
     */
    public function parseSharedSubtenants(array $input): array
    {
        if (!array_key_exists('shared_subtenant_ids', $input) && !array_key_exists('sharedSubtenantIds', $input)) {
            return ['ok' => true, 'status' => 200, 'items' => null];
        }

        $raw = $input['shared_subtenant_ids'] ?? $input['sharedSubtenantIds'];
        if ($raw === null) {
            return ['ok' => true, 'status' => 200, 'items' => null];
        }
        if (!is_array($raw)) {
            return ['ok' => false, 'status' => 422, 'error' => 'shared_subtenant_ids должен быть массивом кодов subtenant'];
        }

        $items = [];
        foreach ($raw as $code) {
            $code = strtolower(trim((string) $code));
            if ($code !== '') {
                $items[] = $code;
            }
        }

        return ['ok' => true, 'status' => 200, 'items' => $items === [] ? null : array_values(array_unique($items))];
    }

    private function canTargetSubtenant(int $userId, string $tenantId, string $sessionSubtenant): bool
    {
        return $this->rbac->hasAnyRole($userId, $tenantId, $sessionSubtenant, [
            'super_admin', 'tenant_admin', 'subtenant_admin',
        ]);
    }
}
