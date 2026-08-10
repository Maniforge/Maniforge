<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\ProjectRepository;
use App\Maniforge\Rbac\Repository\ScopeVariableRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Versioning\Security\ChangeRecorder;
use App\Maniforge\Versioning\Support\VersioningScope;

final class ProjectService
{
    public function __construct(
        private readonly ProjectRepository $projects = new ProjectRepository(),
        private readonly ScopeVariableRepository $variables = new ScopeVariableRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
        private readonly ProjectWarehouseValidator $warehouseValidator = new ProjectWarehouseValidator(),
    ) {
    }

    /**
     * @return array{ok: bool, status: int, items?: list<array>, error?: string}
     */
    public function listProjects(array $session, bool $adminBypassMembership = false): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $userId = (int) $session['user_id'];
        $includeTenantLevel = $this->canSeeTenantLevelProjects($userId, $tenantId, $subtenantId);

        $all = $this->projects->listInScope($tenantId, $subtenantId, $includeTenantLevel);
        if ($adminBypassMembership || $this->rbac->hasAnyRole($userId, $tenantId, $subtenantId, [
            'super_admin', 'tenant_admin', 'subtenant_admin',
        ])) {
            return ['ok' => true, 'status' => 200, 'items' => $all];
        }

        $memberIds = [];
        foreach ($this->projects->listForUser($userId, $tenantId, $subtenantId) as $p) {
            $memberIds[(int) $p['id']] = true;
        }

        $items = array_values(array_filter(
            $all,
            static fn (array $p): bool => isset($memberIds[(int) $p['id']])
        ));

        return ['ok' => true, 'status' => 200, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, project?: array, error?: string}
     */
    public function createProject(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $targetSubtenant = strtolower(trim((string) ($input['subtenant_id'] ?? $subtenantId)));
        $atTenantLevel = filter_var($input['tenant_level'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || $targetSubtenant === '' || $targetSubtenant === '_tenant';

        if ($code === '' || $name === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'code и name обязательны'];
        }

        if ($atTenantLevel) {
            if (!$this->rbac->hasAnyRole((int) $session['user_id'], $tenantId, $subtenantId, [
                'super_admin', 'tenant_admin',
            ])) {
                return ['ok' => false, 'status' => 403, 'error' => 'Проекты уровня tenant доступны только tenant_admin'];
            }
            $projectSubtenant = '';
        } else {
            $projectSubtenant = $subtenantId;
        }

        $metadata = $input['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            return ['ok' => false, 'status' => 422, 'error' => 'metadata должен быть объектом'];
        }

        $warehouseParsed = $this->warehouseValidator->parseWarehouseId($input);
        if (($warehouseParsed['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($warehouseParsed['status'] ?? 422),
                'error' => (string) ($warehouseParsed['error'] ?? 'Некорректный warehouse_id'),
            ];
        }
        $warehouseId = null;
        if (($warehouseParsed['provided'] ?? false) === true) {
            $warehouseId = $warehouseParsed['warehouse_id'] ?? null;
            if ($warehouseId !== null) {
                $whCheck = $this->warehouseValidator->validate(
                    $warehouseId,
                    $session,
                    ['subtenant_id' => $projectSubtenant, 'id' => 0]
                );
                if (($whCheck['ok'] ?? false) !== true) {
                    return [
                        'ok' => false,
                        'status' => (int) ($whCheck['status'] ?? 422),
                        'error' => (string) ($whCheck['error'] ?? 'Некорректный склад'),
                    ];
                }
            }
        }

        try {
            $project = $this->projects->create(
                $tenantId,
                $projectSubtenant,
                $code,
                $name,
                $metadata,
                $warehouseId
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'Проект с таким code уже существует в scope'];
            }
            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка создания проекта'];
        }

        $this->versioning->record(
            $this->versionScope($session, (int) $project['id']),
            'maniforge_projects',
            (string) $project['id'],
            'insert',
            null,
            $project,
            (string) $project['code']
        );

        return ['ok' => true, 'status' => 201, 'project' => $project];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, project?: array, error?: string}
     */
    public function updateProject(array $session, string $code, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $includeTenantLevel = $this->canSeeTenantLevelProjects((int) $session['user_id'], $tenantId, $subtenantId);
        $project = $this->projects->findByCodeInScope($tenantId, $subtenantId, $code, $includeTenantLevel);
        if ($project === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Проект не найден'];
        }

        if (!$this->userCanAccessProject($session, $project)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Нет доступа к проекту'];
        }

        $changes = [];
        if (array_key_exists('name', $input)) {
            $changes['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('status', $input)) {
            $changes['status'] = trim((string) $input['status']);
        }
        if (array_key_exists('metadata', $input)) {
            $meta = $input['metadata'];
            if ($meta !== null && !is_array($meta)) {
                return ['ok' => false, 'status' => 422, 'error' => 'metadata должен быть объектом'];
            }
            $changes['metadata'] = $meta;
        }

        $warehouseParsed = $this->warehouseValidator->parseWarehouseId($input);
        if (($warehouseParsed['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($warehouseParsed['status'] ?? 422),
                'error' => (string) ($warehouseParsed['error'] ?? 'Некорректный warehouse_id'),
            ];
        }
        if (($warehouseParsed['provided'] ?? false) === true) {
            $warehouseId = $warehouseParsed['warehouse_id'] ?? null;
            if ($warehouseId !== null) {
                $whCheck = $this->warehouseValidator->validate($warehouseId, $session, $project);
                if (($whCheck['ok'] ?? false) !== true) {
                    return [
                        'ok' => false,
                        'status' => (int) ($whCheck['status'] ?? 422),
                        'error' => (string) ($whCheck['error'] ?? 'Некорректный склад'),
                    ];
                }
            }
            $changes['warehouse_id'] = $warehouseId;
        }

        $updated = $this->projects->updateById((int) $project['id'], $changes);

        if ($updated !== null && $changes !== []) {
            $this->versioning->record(
                $this->versionScope($session, (int) $project['id']),
                'maniforge_projects',
                (string) $project['id'],
                'update',
                $project,
                $updated,
                (string) $project['code']
            );
        }

        return ['ok' => true, 'status' => 200, 'project' => $updated ?? $project];
    }

    public function getProject(array $session, string $code): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $includeTenantLevel = $this->canSeeTenantLevelProjects((int) $session['user_id'], $tenantId, $subtenantId);
        $project = $this->projects->findByCodeInScope($tenantId, $subtenantId, $code, $includeTenantLevel);
        if ($project === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Проект не найден'];
        }
        if (!$this->userCanAccessProject($session, $project)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Нет доступа к проекту'];
        }

        return ['ok' => true, 'status' => 200, 'project' => $project];
    }

    /**
     * @return array{ok: bool, status: int, items?: list<array>, error?: string}
     */
    public function listGlobalVariables(array $session): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $projectId = isset($session['project_id']) && $session['project_id'] !== null
            ? (int) $session['project_id']
            : null;

        return [
            'ok' => true,
            'status' => 200,
            'items' => $this->variables->listVisible($tenantId, $subtenantId, $projectId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createGlobalVariable(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $key = trim((string) ($input['key'] ?? ''));
        $value = (string) ($input['value'] ?? '');
        $valueType = trim((string) ($input['value_type'] ?? 'string'));
        $scope = strtolower(trim((string) ($input['scope_level'] ?? 'tenant')));

        if ($key === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'key обязателен'];
        }

        $projectId = null;
        $varSubtenant = '';
        if ($scope === 'project') {
            $code = $this->normalizeCode((string) ($input['project_code'] ?? ''));
            if ($code === '') {
                return ['ok' => false, 'status' => 422, 'error' => 'project_code обязателен для scope_level=project'];
            }
            $includeTenantLevel = $this->canSeeTenantLevelProjects((int) $session['user_id'], $tenantId, $subtenantId);
            $project = $this->projects->findByCodeInScope($tenantId, $subtenantId, $code, $includeTenantLevel);
            if ($project === null) {
                return ['ok' => false, 'status' => 404, 'error' => 'Проект не найден'];
            }
            $projectId = (int) $project['id'];
            $varSubtenant = (string) $project['subtenant_id'];
        } elseif ($scope === 'subtenant') {
            $varSubtenant = $subtenantId;
            if (!$this->rbac->hasAnyRole((int) $session['user_id'], $tenantId, $subtenantId, [
                'super_admin', 'tenant_admin', 'subtenant_admin',
            ])) {
                return ['ok' => false, 'status' => 403, 'error' => 'Переменные subtenant-level требуют admin-роль'];
            }
        } elseif ($scope === 'tenant') {
            if (!$this->rbac->hasAnyRole((int) $session['user_id'], $tenantId, $subtenantId, [
                'super_admin', 'tenant_admin',
            ])) {
                return ['ok' => false, 'status' => 403, 'error' => 'Глобальные tenant-level переменные требуют tenant_admin'];
            }
        } else {
            return ['ok' => false, 'status' => 422, 'error' => 'scope_level: tenant|subtenant|project'];
        }

        $existing = $this->variables->findByKey($tenantId, $varSubtenant, $projectId, $key);
        $item = $this->variables->upsert($tenantId, $varSubtenant, $projectId, $scope, $key, $value, $valueType);

        $this->versioning->record(
            $this->versionScope($session, $projectId),
            'maniforge_scope_variables',
            (string) $item['id'],
            $existing === null ? 'insert' : 'update',
            $existing,
            $item,
            (string) $item['key']
        );

        return ['ok' => true, 'status' => 201, 'item' => $item];
    }

    /**
     * @return list<array>
     */
    public function availableProjectsForUser(array $session): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $userId = (int) $session['user_id'];
        $result = $this->listProjects($session, true);
        $items = $result['items'] ?? [];

        if (!$this->rbac->hasAnyRole($userId, $tenantId, $subtenantId, [
            'super_admin', 'tenant_admin', 'subtenant_admin',
        ])) {
            $member = $this->projects->listForUser($userId, $tenantId, $subtenantId);
            $ids = [];
            foreach ($member as $p) {
                $ids[(int) $p['id']] = true;
            }
            $items = array_values(array_filter(
                $items,
                static fn (array $p): bool => isset($ids[(int) $p['id']])
            ));
        }

        return $items;
    }

    /**
     * @return array{ok: bool, status: int, session?: array, error?: string}
     */
    public function switchProject(array $session, ?int $projectId): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $current = isset($session['project_id']) ? (int) $session['project_id'] : null;

        if ($projectId === null || $projectId === 0) {
            if ($current === null) {
                return [
                    'ok' => true,
                    'status' => 200,
                    'session' => ['project_id' => null, 'unchanged' => true],
                ];
            }

            return ['ok' => true, 'status' => 200, 'session' => ['project_id' => null]];
        }

        $project = $this->projects->findById($projectId);
        if ($project === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Проект не найден'];
        }
        if ((string) $project['tenant_id'] !== $tenantId) {
            return ['ok' => false, 'status' => 403, 'error' => 'Проект вне tenant сессии'];
        }
        $pSub = (string) $project['subtenant_id'];
        if ($pSub !== '' && $pSub !== $subtenantId) {
            return ['ok' => false, 'status' => 403, 'error' => 'Проект вне subtenant сессии'];
        }
        if (!$this->userCanAccessProject($session, $project)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Нет членства в проекте'];
        }

        if ($current === $projectId) {
            $unchanged = [
                'project_id' => $projectId,
                'project_code' => (string) $project['code'],
                'project_scope' => (string) ($project['project_scope'] ?? $project['scope'] ?? 'subtenant'),
                'unchanged' => true,
            ];
            if (array_key_exists('warehouse_id', $project)) {
                $unchanged['warehouse_id'] = $project['warehouse_id'];
            }

            return ['ok' => true, 'status' => 200, 'session' => $unchanged];
        }

        $sessionPayload = [
            'project_id' => $projectId,
            'project_code' => (string) $project['code'],
            'project_scope' => (string) ($project['project_scope'] ?? $project['scope'] ?? 'subtenant'),
        ];
        if (array_key_exists('warehouse_id', $project)) {
            $sessionPayload['warehouse_id'] = $project['warehouse_id'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'session' => $sessionPayload,
        ];
    }

    public function resolveProjectForSession(array $session, ?int $projectId): ?array
    {
        if ($projectId === null || $projectId === 0) {
            return null;
        }

        $result = $this->switchProject($session, $projectId);

        return ($result['ok'] ?? false) ? ($this->projects->findById($projectId) ?? null) : null;
    }

    /**
     * @param array<string, mixed> $project
     */
    public function userCanAccessProject(array $session, array $project): bool
    {
        $userId = (int) $session['user_id'];
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];

        if ($this->rbac->hasAnyRole($userId, $tenantId, $subtenantId, [
            'super_admin', 'tenant_admin', 'subtenant_admin',
        ])) {
            return true;
        }

        return $this->projects->userHasMembership($userId, (int) $project['id']);
    }

    public function assignUserToProject(array $session, int $userId, string $projectCode): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $includeTenantLevel = true;
        $project = $this->projects->findByCodeInScope($tenantId, $subtenantId, $projectCode, $includeTenantLevel);
        if ($project === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Проект не найден'];
        }

        $user = $this->users->findByIdInScope($userId, $tenantId, $subtenantId);
        if ($user === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Пользователь не найден в scope'];
        }

        $this->projects->assignUser($userId, (int) $project['id'], $tenantId, $subtenantId);

        VersioningScope::record(
            $this->versioning,
            $session,
            'maniforge_user_project_memberships',
            $userId . ':' . (int) $project['id'],
            'insert',
            null,
            [
                'user_id' => $userId,
                'project_id' => (int) $project['id'],
                'project_code' => (string) $project['code'],
            ],
            (string) ($user['login'] ?? $userId) . '@' . (string) $project['code'],
            (int) $project['id']
        );

        return ['ok' => true, 'status' => 200, 'user_id' => $userId, 'project_code' => $project['code']];
    }

    private function canSeeTenantLevelProjects(int $userId, string $tenantId, string $subtenantId): bool
    {
        return $this->rbac->hasAnyRole($userId, $tenantId, $subtenantId, [
            'super_admin', 'tenant_admin', 'subtenant_admin',
        ]);
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_-]+/', '-', $code) ?? '';

        return trim($code, '-');
    }

    /**
     * @return array{tenant_id: string, subtenant_id: string, project_id: int|null, actor_user_id: int}
     */
    private function versionScope(array $session, ?int $projectId = null): array
    {
        $sessionProjectId = isset($session['project_id']) && $session['project_id'] !== null
            ? (int) $session['project_id']
            : null;

        return [
            'tenant_id' => (string) $session['tenant_id'],
            'subtenant_id' => (string) $session['subtenant_id'],
            'project_id' => $projectId ?? $sessionProjectId,
            'actor_user_id' => (int) $session['user_id'],
        ];
    }
}
