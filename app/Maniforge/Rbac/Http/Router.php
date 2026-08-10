<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Http;

use App\Maniforge\Rbac\Controllers\AdminController;
use App\Maniforge\Rbac\Controllers\AuthController;
use App\Maniforge\Rbac\Controllers\HealthController;
use App\Maniforge\Rbac\Controllers\PageController;
use App\Maniforge\Rbac\Controllers\PersonalDataController;
use App\Maniforge\Rbac\Controllers\ProjectsController;
use App\Maniforge\Rbac\Controllers\TenantLifecycleEventController;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class Router
{
    public function dispatch(RequestContext $ctx): void
    {
        $method = $ctx->method;
        $path = $this->normalizePath($ctx->path);

        if ($method === 'GET' && $path === '/health') {
            (new HealthController())($ctx->tenant);
            return;
        }

        if ($method === 'GET' && $path === '/') {
            (new PageController())->home();
            return;
        }

        if ($method === 'GET' && $path === '/admin') {
            (new PageController())->admin();
            return;
        }

        if ($method === 'GET' && $path === '/api-docs') {
            (new PageController())->apiDocs();
            return;
        }

        if ($method === 'GET' && $path === '/api-docs/openapi.yaml') {
            (new PageController())->openapiYaml();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/privacy/notice') {
            (new PersonalDataController())->privacyNotice($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/register') {
            (new AuthController())->register($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/accept-invite') {
            (new AuthController())->acceptInvite($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/login') {
            (new AuthController())->login($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/logout') {
            (new AuthController())->logout($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/logout-all') {
            (new AuthController())->logoutAll($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/refresh') {
            (new AuthController())->refresh($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/reauth') {
            (new AuthController())->reauth($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me') {
            (new AuthController())->me($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me/contexts') {
            (new AuthController())->myContexts($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/switch-context') {
            (new AuthController())->switchContext($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/switch-project') {
            (new AuthController())->switchProject($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/projects') {
            (new ProjectsController())->list($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/projects') {
            (new ProjectsController())->create($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/projects/memberships') {
            (new ProjectsController())->assignUser($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/global-variables') {
            (new ProjectsController())->listGlobalVariables($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/global-variables') {
            (new ProjectsController())->createGlobalVariable($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me/permissions') {
            (new AuthController())->myPermissions($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me/access') {
            (new AuthController())->myAccess($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me/console-access') {
            (new AuthController())->consoleAccess($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me/profile') {
            (new AuthController())->profile($ctx);
            return;
        }

        if ($method === 'PATCH' && $path === '/api/v1/me/profile') {
            (new AuthController())->updateProfile($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/me/security/password') {
            (new AuthController())->changePassword($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me/personal-data') {
            (new PersonalDataController())->exportMe($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me/personal-data/consents') {
            (new PersonalDataController())->listMyConsents($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/me/personal-data/consents') {
            (new PersonalDataController())->grantConsent($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/me/personal-data/consents/revoke') {
            (new PersonalDataController())->revokeConsent($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/me/personal-data/subject-requests') {
            (new PersonalDataController())->listMySubjectRequests($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/me/personal-data/subject-requests') {
            (new PersonalDataController())->createSubjectRequest($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/users') {
            (new AdminController())->users($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/users') {
            (new AdminController())->createUser($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/registration-invites') {
            (new AdminController())->createRegistrationInvite($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/organization-members') {
            (new AdminController())->attachOrganizationMember($ctx);
            return;
        }

        if ($method === 'PATCH' && $path === '/api/v1/admin/users') {
            (new AdminController())->updateUser($ctx);
            return;
        }

        if ($method === 'DELETE' && $path === '/api/v1/admin/users') {
            (new AdminController())->deleteUser($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/sessions') {
            (new AdminController())->sessions($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/sessions/revoke') {
            (new AdminController())->revokeSession($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/sessions/batch-revoke') {
            (new AdminController())->batchRevokeSessions($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/audit') {
            (new AdminController())->audit($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/audit/export') {
            (new AdminController())->auditExport($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/security-events') {
            (new AdminController())->securityEvents($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/policies') {
            (new AdminController())->policyRules($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/policies') {
            (new AdminController())->updatePolicyRules($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/roles') {
            (new AdminController())->roles($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/roles') {
            (new AdminController())->createRole($ctx);
            return;
        }

        if ($method === 'PATCH' && $path === '/api/v1/admin/roles') {
            (new AdminController())->updateRole($ctx);
            return;
        }

        if ($method === 'DELETE' && $path === '/api/v1/admin/roles') {
            (new AdminController())->deleteRole($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/permissions') {
            (new AdminController())->permissions($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/role-permissions') {
            (new AdminController())->rolePermissions($ctx);
            return;
        }

        if ($method === 'PUT' && $path === '/api/v1/admin/role-permissions') {
            (new AdminController())->replaceRolePermissions($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/user-roles') {
            (new AdminController())->userRoles($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/user-roles/assign') {
            (new AdminController())->assignUserRole($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/user-roles/revoke') {
            (new AdminController())->revokeUserRole($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/user-roles/batch') {
            (new AdminController())->batchUserRoles($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/users/batch-status') {
            (new AdminController())->batchUserStatus($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/effective-access') {
            (new AdminController())->effectiveAccess($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/ops-summary') {
            (new AdminController())->opsSummary($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/personal-data/operator-profile') {
            (new PersonalDataController())->getOperatorProfile($ctx);
            return;
        }

        if ($method === 'PUT' && $path === '/api/v1/admin/personal-data/operator-profile') {
            (new PersonalDataController())->putOperatorProfile($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/personal-data/compliance-status') {
            (new PersonalDataController())->complianceStatus($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/personal-data/dpa-acknowledge') {
            (new PersonalDataController())->acknowledgeDpa($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/personal-data/purposes') {
            (new PersonalDataController())->listPurposes($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/personal-data/purposes') {
            (new PersonalDataController())->createPurpose($ctx);
            return;
        }

        if ($method === 'PATCH' && $path === '/api/v1/admin/personal-data/purposes') {
            (new PersonalDataController())->patchPurpose($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/admin/personal-data/subject-requests') {
            (new PersonalDataController())->listSubjectRequests($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/admin/personal-data/subject-requests/resolve') {
            (new PersonalDataController())->resolveSubjectRequest($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/internal/v1/tenant-events') {
            (new TenantLifecycleEventController())->receive($ctx);
            return;
        }

        if (preg_match('#^/api/v1/projects/([a-z0-9_-]+)$#', $path, $m) === 1) {
            $code = (string) $m[1];
            if ($method === 'GET') {
                (new ProjectsController())->get($ctx, $code);
                return;
            }
            if ($method === 'PATCH') {
                (new ProjectsController())->patch($ctx, $code);
                return;
            }
        }

        JsonResponse::send([
            'ok' => false,
            'error' => 'Маршрут не найден',
            'method' => $method,
            'path' => $path,
        ], 404);
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $bases = ['/rbac'];

        foreach ($bases as $base) {
            if ($path === $base || $path === $base . '/') {
                return '/';
            }

            if (str_starts_with($path, $base . '/')) {
                return '/' . ltrim(substr($path, strlen($base . '/')), '/');
            }
        }

        return $path;
    }

    public function normalizePathForMiddleware(string $path): string
    {
        return $this->normalizePath($path);
    }
}
