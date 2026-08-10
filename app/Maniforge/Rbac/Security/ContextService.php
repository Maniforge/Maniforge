<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\ProjectRepository;
use App\Maniforge\Rbac\Repository\SessionRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Support\EntityScope;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

final class ContextService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly SessionRepository $sessions = new SessionRepository(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly ProjectService $projects = new ProjectService(),
        private readonly ProjectRepository $projectRepo = new ProjectRepository(),
        private readonly TenantLicensingRepository $licensing = new TenantLicensingRepository(),
    ) {
    }

    /**
     * @return array{ok: bool, status: int, home?: list<array>, delegated?: list<array>, current?: array, error?: string}
     */
    public function contextsForSession(array $session): array
    {
        $user = $this->resolveUserForSession($session);
        if ($user === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Пользователь не найден'];
        }

        $accountPhone = $this->accountPhone($user);
        if ($accountPhone === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'У пользователя не задан телефон'];
        }
        $home = $this->homeContexts($accountPhone);
        $delegated = $this->delegatedContextsForPhone($accountPhone);
        $currentTenant = (string) $session['tenant_id'];
        $currentSubtenant = (string) $session['subtenant_id'];
        $currentKind = $this->contextKind($currentTenant, $currentSubtenant, $home, $delegated);
        $currentProjectId = isset($session['project_id']) && $session['project_id'] !== null
            ? (int) $session['project_id']
            : null;
        $current = [
            'tenant_id' => $currentTenant,
            'subtenant_id' => $currentSubtenant,
            'project_id' => $currentProjectId,
            'kind' => $currentKind,
        ];
        if ($currentProjectId !== null) {
            $proj = $this->projectRepo->findById($currentProjectId);
            if ($proj !== null) {
                $current['project_code'] = (string) $proj['code'];
                if (array_key_exists('warehouse_id', $proj)) {
                    $current['warehouse_id'] = $proj['warehouse_id'];
                }
                if (($proj['warehouse'] ?? null) !== null) {
                    $current['warehouse'] = $proj['warehouse'];
                }
            }
        }
        $principalTenant = $this->resolvePrincipalTenantId($accountPhone, $currentTenant, $currentSubtenant, $home, $delegated);
        $currentDelegation = $this->delegationForScope(
            $accountPhone,
            $principalTenant,
            $currentTenant,
            $currentSubtenant,
            $home,
            $delegated
        );
        if ($currentDelegation !== null) {
            $current = array_merge($current, $currentDelegation);
        }

        $availableProjects = $this->projects->availableProjectsForUser($session);
        $projectOptions = [['project_id' => null, 'label' => 'Без проекта (tenant/subtenant scope)']];
        foreach ($availableProjects as $p) {
            $option = [
                'project_id' => (int) $p['id'],
                'code' => (string) $p['code'],
                'name' => (string) $p['name'],
                'scope' => (string) ($p['scope'] ?? 'subtenant'),
            ];
            if (array_key_exists('warehouse_id', $p)) {
                $option['warehouse_id'] = $p['warehouse_id'];
            }
            if (($p['warehouse'] ?? null) !== null) {
                $option['warehouse'] = $p['warehouse'];
            }
            $projectOptions[] = $option;
        }

        return [
            'ok' => true,
            'status' => 200,
            'current' => $this->enrichContextRow($current, $currentTenant, $currentSubtenant),
            'home' => $this->enrichContextList($home),
            'delegated' => $this->enrichContextList($delegated),
            'organizations' => $this->buildOrganizationsList($home, $delegated, $currentTenant, $currentSubtenant),
            'projects' => $availableProjects,
            'project_options' => $projectOptions,
        ];
    }

    /**
     * @return array{ok: bool, status: int, session?: array, error?: string}
     */
    public function switchContext(array $session, string $token, string $tenantId, string $subtenantId): array
    {
        $tenantId = strtolower(trim($tenantId));
        $subtenantId = strtolower(trim($subtenantId));
        if ($tenantId === '' || $subtenantId === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'tenant_id и subtenant_id обязательны'];
        }

        if (
            (string) $session['tenant_id'] === $tenantId
            && (string) $session['subtenant_id'] === $subtenantId
        ) {
            return [
                'ok' => true,
                'status' => 200,
                'session' => [
                    'tenant_id' => $tenantId,
                    'subtenant_id' => $subtenantId,
                    'unchanged' => true,
                ],
            ];
        }

        $user = $this->resolveUserForSession($session);
        if ($user === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Пользователь не найден'];
        }

        $accountPhone = $this->accountPhone($user);
        if ($accountPhone === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'У пользователя не задан телефон'];
        }
        $home = $this->homeContexts($accountPhone);
        $delegated = $this->delegatedContextsForPhone($accountPhone);
        $allowed = $this->isContextAllowed($accountPhone, $tenantId, $subtenantId);
        if (!$allowed['ok']) {
            return ['ok' => false, 'status' => 403, 'error' => $allowed['error'] ?? 'Контекст недоступен'];
        }

        $previousTenant = (string) $session['tenant_id'];
        $previousSubtenant = (string) $session['subtenant_id'];
        $rebound = $this->sessions->rebindScope((string) $session['id'], $tenantId, $subtenantId);
        if (!$rebound) {
            return ['ok' => false, 'status' => 500, 'error' => 'Не удалось переключить контекст сессии'];
        }

        $defaultProject = (new DefaultProjectService())->ensureDefault($tenantId, $subtenantId);
        $defaultProjectId = (int) ($defaultProject['id'] ?? 0);
        if ($defaultProjectId > 0) {
            $this->sessions->rebindProject((string) $session['id'], $defaultProjectId);
        }

        $principalTenant = $this->resolvePrincipalTenantId($accountPhone, $tenantId, $subtenantId, $home, $delegated);
        $delegation = $this->delegationForScope(
            $accountPhone,
            $principalTenant,
            $tenantId,
            $subtenantId,
            $home,
            $delegated
        );

        $this->audit->write(
            'auth.context_switch',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            [
                'previous_tenant_id' => $previousTenant,
                'previous_subtenant_id' => $previousSubtenant,
                'kind' => $allowed['kind'],
                'grant_level' => $allowed['grant_level'] ?? null,
                'principal_tenant_id' => $principalTenant,
                'delegated' => ($delegation['delegated'] ?? false) === true,
            ]
        );

        $sessionPayload = [
            'tenant_id' => $tenantId,
            'subtenant_id' => $subtenantId,
            'project_id' => $defaultProjectId > 0 ? $defaultProjectId : null,
            'project_code' => $defaultProjectId > 0 ? (string) ($defaultProject['code'] ?? EntityScope::DEFAULT_PROJECT_CODE) : null,
            'kind' => $allowed['kind'],
            'grant_level' => $allowed['grant_level'] ?? null,
            'delegated' => false,
            'principal_tenant_id' => $principalTenant,
        ];
        if ($delegation !== null) {
            $sessionPayload = array_merge($sessionPayload, $delegation);
        }

        return [
            'ok' => true,
            'status' => 200,
            'session' => $sessionPayload,
        ];
    }

    /**
     * Метаданные делегирования для текущего scope сессии (для /me и будущих admin guards).
     *
     * @param list<array> $home
     * @param list<array> $delegated
     * @return array{delegated: bool, grant_level?: string, principal_tenant_id?: string}|null
     */
    public function delegationForScope(
        string $accountPhone,
        string $principalTenant,
        string $tenantId,
        string $subtenantId,
        array $home,
        array $delegated
    ): ?array {
        foreach ($home as $ctx) {
            if ($ctx['tenant_id'] === $tenantId && $ctx['subtenant_id'] === $subtenantId) {
                return ['delegated' => false, 'principal_tenant_id' => $principalTenant];
            }
        }

        foreach ($delegated as $ctx) {
            if ($ctx['tenant_id'] === $tenantId && $ctx['subtenant_id'] === $subtenantId) {
                return [
                    'delegated' => true,
                    'grant_level' => (string) ($ctx['grant_level'] ?? ''),
                    'principal_tenant_id' => (string) ($ctx['principal_tenant_id'] ?? $principalTenant),
                ];
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, error?: string, kind?: string, grant_level?: string}
     */
    public function isContextAllowed(string $accountPhone, string $tenantId, string $subtenantId): array
    {
        $home = $this->homeContexts($accountPhone);
        foreach ($home as $ctx) {
            if ($ctx['tenant_id'] === $tenantId && $ctx['subtenant_id'] === $subtenantId) {
                return ['ok' => true, 'kind' => 'home'];
            }
        }

        foreach ($this->delegatedContextsForPhone($accountPhone) as $ctx) {
            if ($ctx['tenant_id'] === $tenantId && $ctx['subtenant_id'] === $subtenantId) {
                return [
                    'ok' => true,
                    'kind' => 'delegated',
                    'grant_level' => (string) ($ctx['grant_level'] ?? ''),
                ];
            }
        }

        return ['ok' => false, 'error' => 'Контекст не входит в home/delegated для пользователя'];
    }

    /**
     * @param list<array> $home
     * @param list<array> $delegated
     */
    private function resolvePrincipalTenantId(
        string $accountPhone,
        string $tenantId,
        string $subtenantId,
        array $home,
        array $delegated
    ): string {
        foreach ($delegated as $ctx) {
            if ($ctx['tenant_id'] === $tenantId && $ctx['subtenant_id'] === $subtenantId) {
                return (string) ($ctx['principal_tenant_id'] ?? '');
            }
        }
        foreach ($home as $ctx) {
            if ($ctx['tenant_id'] === $tenantId && $ctx['subtenant_id'] === $subtenantId) {
                return (string) $ctx['tenant_id'];
            }
        }

        $principals = $this->principalTenantsForPhone($accountPhone);

        return $principals[0] ?? $tenantId;
    }

    /**
     * @return list<string>
     */
    private function principalTenantsForPhone(string $accountPhone): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT DISTINCT u.tenant_id
             FROM maniforge_users u
             WHERE u.phone = :phone AND u.status = 'active'
             ORDER BY u.tenant_id ASC"
        );
        $stmt->execute([':phone' => $accountPhone]);
        $tenants = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtolower(trim((string) ($row['tenant_id'] ?? '')));
            if ($code !== '') {
                $tenants[] = $code;
            }
        }

        return $tenants;
    }

    /**
     * @return list<array{tenant_id: string, subtenant_id: string, kind: string, grant_level: string, principal_tenant_id: string}>
     */
    private function delegatedContextsForPhone(string $accountPhone): array
    {
        $contexts = [];
        foreach ($this->principalTenantsForPhone($accountPhone) as $principalTenant) {
            foreach ($this->delegatedContexts($principalTenant, $accountPhone) as $ctx) {
                $contexts[] = $ctx;
            }
        }

        return $contexts;
    }

    /**
     * @return list<array{tenant_id: string, subtenant_id: string, kind: string}>
     */
    private function homeContexts(string $accountPhone): array
    {
        $stmt = Connection::get()->prepare(
            "SELECT DISTINCT u.tenant_id, u.subtenant_id
             FROM maniforge_users u
             WHERE u.phone = :phone AND u.status = 'active'
             UNION
             SELECT DISTINCT ur.tenant_id, ur.subtenant_id
             FROM maniforge_user_roles ur
             INNER JOIN maniforge_users u ON u.id = ur.user_id
             WHERE u.phone = :phone2
               AND u.status = 'active'
               AND (ur.expires_at IS NULL OR ur.expires_at > UTC_TIMESTAMP())
             ORDER BY tenant_id ASC, subtenant_id ASC"
        );
        $stmt->execute([':phone' => $accountPhone, ':phone2' => $accountPhone]);
        $rows = $stmt->fetchAll();
        $contexts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $contexts[] = [
                'tenant_id' => (string) $row['tenant_id'],
                'subtenant_id' => (string) $row['subtenant_id'],
                'kind' => 'home',
            ];
        }

        return $contexts;
    }

    /**
     * @return list<array{tenant_id: string, subtenant_id: string, kind: string, grant_level: string, principal_tenant_id: string}>
     */
    private function delegatedContexts(string $principalTenant, string $accountPhone): array
    {
        $principalTenant = strtolower(trim($principalTenant));
        if ($principalTenant === '') {
            return [];
        }

        $stmt = Connection::get()->prepare(
            "SELECT g.managed_tenant_code, g.grant_level, s.code AS subtenant_code
             FROM maniforge_tl_tenant_grants g
             INNER JOIN maniforge_tl_subtenants s
                ON s.tenant_code = g.managed_tenant_code AND s.status = 'active'
             WHERE g.principal_tenant_code = :principal
               AND g.status = 'active'
               AND g.principal_tenant_code <> g.managed_tenant_code
               AND EXISTS (
                   SELECT 1 FROM maniforge_users u
                   WHERE u.phone = :phone
                     AND u.tenant_id = g.principal_tenant_code
                     AND u.status = 'active'
               )
             ORDER BY g.managed_tenant_code ASC, s.code ASC"
        );
        $stmt->execute([
            ':principal' => $principalTenant,
            ':phone' => $accountPhone,
        ]);

        $contexts = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $contexts[] = [
                'tenant_id' => (string) $row['managed_tenant_code'],
                'subtenant_id' => (string) $row['subtenant_code'],
                'kind' => 'delegated',
                'grant_level' => (string) $row['grant_level'],
                'principal_tenant_id' => $principalTenant,
            ];
        }

        return $contexts;
    }

    /**
     * @param list<array> $home
     * @param list<array> $delegated
     */
    private function contextKind(
        string $tenantId,
        string $subtenantId,
        array $home,
        array $delegated
    ): string {
        foreach ($home as $ctx) {
            if ($ctx['tenant_id'] === $tenantId && $ctx['subtenant_id'] === $subtenantId) {
                return 'home';
            }
        }
        foreach ($delegated as $ctx) {
            if ($ctx['tenant_id'] === $tenantId && $ctx['subtenant_id'] === $subtenantId) {
                return 'delegated';
            }
        }

        return 'session';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveUserForSession(array $session): ?array
    {
        $user = $this->users->findByIdForSession($session);
        if ($user !== null) {
            return $user;
        }

        $userId = (int) ($session['user_id'] ?? 0);

        return $userId > 0 ? $this->users->findById($userId) : null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function accountPhone(array $user): string
    {
        return trim((string) ($user['phone'] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function enrichContextList(array $items): array
    {
        $enriched = [];
        foreach ($items as $item) {
            $enriched[] = $this->enrichContextRow(
                $item,
                (string) ($item['tenant_id'] ?? ''),
                (string) ($item['subtenant_id'] ?? ''),
            );
        }

        return $enriched;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichContextRow(array $row, string $tenantId, string $subtenantId): array
    {
        $tenant = $tenantId !== '' ? $this->licensing->findTenantPublic($tenantId) : null;
        $subtenant = ($tenantId !== '' && $subtenantId !== '')
            ? $this->licensing->findSubtenantPublic($tenantId, $subtenantId)
            : null;

        $row['tenant_name'] = (string) ($tenant['name'] ?? $tenantId);
        $row['subtenant_name'] = (string) ($subtenant['name'] ?? $subtenantId);
        $row['tenant_status'] = (string) ($tenant['status'] ?? '');
        $row['label'] = $row['tenant_name'] . ' · ' . $row['subtenant_name'];

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $home
     * @param list<array<string, mixed>> $delegated
     * @return list<array<string, mixed>>
     */
    private function buildOrganizationsList(
        array $home,
        array $delegated,
        string $currentTenant,
        string $currentSubtenant,
    ): array {
        $organizations = [];
        foreach ($this->enrichContextList($home) as $ctx) {
            $organizations[] = array_merge($ctx, [
                'membership' => 'home',
                'is_current' => $ctx['tenant_id'] === $currentTenant && $ctx['subtenant_id'] === $currentSubtenant,
            ]);
        }
        foreach ($this->enrichContextList($delegated) as $ctx) {
            $organizations[] = array_merge($ctx, [
                'membership' => 'delegated',
                'is_current' => $ctx['tenant_id'] === $currentTenant && $ctx['subtenant_id'] === $currentSubtenant,
            ]);
        }

        return $organizations;
    }
}
