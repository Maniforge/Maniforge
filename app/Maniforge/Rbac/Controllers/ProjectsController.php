<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Controllers;

use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\SessionRepository;
use App\Maniforge\Rbac\Security\ProjectService;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Security\RequestAuthenticator;
use App\Maniforge\Rbac\Security\SessionService;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class ProjectsController
{
    public function __construct(
        private readonly SessionService $sessions = new SessionService(),
        private readonly SessionRepository $sessionsRepo = new SessionRepository(),
        private readonly ProjectService $projects = new ProjectService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly RequestAuthenticator $authenticator = new RequestAuthenticator(),
    ) {
    }

    public function list(RequestContext $ctx): void
    {
        $session = $this->guard($ctx, 'projects.read');
        if ($session === null) {
            return;
        }

        $result = $this->projects->listProjects($session);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function create(RequestContext $ctx): void
    {
        $session = $this->guard($ctx, 'projects.create');
        if ($session === null) {
            return;
        }

        $result = $this->projects->createProject($session, $ctx->input);
        if (($result['ok'] ?? false) === true) {
            $this->audit->write(
                'projects.create',
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                ['project_code' => $result['project']['code'] ?? null]
            );
        }
        JsonResponse::send($result, (int) $result['status']);
    }

    public function get(RequestContext $ctx, string $code): void
    {
        $session = $this->guard($ctx, 'projects.read');
        if ($session === null) {
            return;
        }

        $result = $this->projects->getProject($session, $code);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function patch(RequestContext $ctx, string $code): void
    {
        $session = $this->guard($ctx, 'projects.update');
        if ($session === null) {
            return;
        }

        $result = $this->projects->updateProject($session, $code, $ctx->input);
        if (($result['ok'] ?? false) === true) {
            $this->audit->write(
                'projects.update',
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                ['project_code' => $code]
            );
        }
        JsonResponse::send($result, (int) $result['status']);
    }

    public function listGlobalVariables(RequestContext $ctx): void
    {
        $session = $this->guard($ctx, 'scope_variables.read');
        if ($session === null) {
            return;
        }

        $result = $this->projects->listGlobalVariables($session);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function createGlobalVariable(RequestContext $ctx): void
    {
        $session = $this->guard($ctx, 'scope_variables.create');
        if ($session === null) {
            return;
        }

        $result = $this->projects->createGlobalVariable($session, $ctx->input);
        if (($result['ok'] ?? false) === true) {
            $this->audit->write(
                'scope_variables.upsert',
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                ['key' => $ctx->input['key'] ?? null]
            );
        }
        JsonResponse::send($result, (int) $result['status']);
    }

    public function assignUser(RequestContext $ctx): void
    {
        $session = $this->guard($ctx, 'projects.update');
        if ($session === null) {
            return;
        }

        $userId = (int) ($ctx->input['user_id'] ?? 0);
        $projectCode = (string) ($ctx->input['project_code'] ?? '');
        if ($userId <= 0 || $projectCode === '') {
            JsonResponse::send(['ok' => false, 'error' => 'user_id и project_code обязательны'], 422);
            return;
        }

        $result = $this->projects->assignUserToProject($session, $userId, $projectCode);
        JsonResponse::send($result, (int) $result['status']);
    }

    private function guard(RequestContext $ctx, string $permission): ?array
    {
        $session = $this->authenticator->authenticateSession($ctx);
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return null;
        }

        if (!$this->rbac->hasPermission(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            $permission
        )) {
            JsonResponse::send(['ok' => false, 'error' => 'Недостаточно permissions'], 403);
            return null;
        }

        $mutating = in_array($ctx->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($mutating && !$this->authenticator->satisfiesSensitiveAction($ctx, $session)) {
            JsonResponse::send($this->authenticator->stepUpRequiredError(), 403);
            return null;
        }

        return $session;
    }
}
