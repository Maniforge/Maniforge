<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Controllers;

use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\PdConsentRepository;
use App\Maniforge\Rbac\Repository\PdOperatorProfileRepository;
use App\Maniforge\Rbac\Repository\PdPurposeRepository;
use App\Maniforge\Rbac\Repository\PdSubjectRequestRepository;
use App\Maniforge\Rbac\Security\PersonalDataService;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Security\TenantPdComplianceService;
use App\Maniforge\Rbac\Security\RequestAuthenticator;
use App\Maniforge\Rbac\Security\PolicyEngine;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class PersonalDataController
{
    public function __construct(
        private readonly PersonalDataService $pd = new PersonalDataService(),
        private readonly RequestAuthenticator $authenticator = new RequestAuthenticator(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly PolicyEngine $policies = new PolicyEngine(),
        private readonly PdPurposeRepository $purposes = new PdPurposeRepository(),
        private readonly PdSubjectRequestRepository $requests = new PdSubjectRequestRepository(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    public function privacyNotice(RequestContext $ctx): void
    {
        $scope = $this->pd->resolveTenantFromServer($ctx->server, $ctx->input);
        if (($scope['ok'] ?? false) !== true) {
            JsonResponse::send($scope, (int) ($scope['status'] ?? 400));
            return;
        }

        $result = $this->pd->buildPrivacyNotice((string) $scope['tenant_id']);
        JsonResponse::send($result, (int) ($result['status'] ?? 200));
    }

    public function exportMe(RequestContext $ctx): void
    {
        $session = $this->guardMe($ctx, 'me.personal_data.read');
        if ($session === null) {
            return;
        }

        $result = $this->pd->exportForUser($session);
        $this->audit->write('pd.export', (int) $session['user_id'], (string) $session['tenant_id'], (string) $session['subtenant_id'], []);
        JsonResponse::send($result, (int) ($result['status'] ?? 200));
    }

    public function listMyConsents(RequestContext $ctx): void
    {
        $session = $this->guardMe($ctx, 'me.consent.read');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => (new PdConsentRepository())->listForUser(
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
    }

    public function grantConsent(RequestContext $ctx): void
    {
        $session = $this->guardMe($ctx, 'me.consent.manage');
        if ($session === null) {
            return;
        }

        $purposeCode = trim((string) ($ctx->input['purpose_code'] ?? ''));
        $policyVersion = trim((string) ($ctx->input['policy_version'] ?? ''));
        if ($purposeCode === '') {
            JsonResponse::send(['ok' => false, 'error' => 'purpose_code обязателен'], 422);
            return;
        }

        $result = $this->pd->grantConsent($session, $purposeCode, $policyVersion, $ctx->server);
        JsonResponse::send($result, (int) ($result['status'] ?? 200));
    }

    public function revokeConsent(RequestContext $ctx): void
    {
        $session = $this->guardMe($ctx, 'me.consent.manage');
        if ($session === null) {
            return;
        }

        $purposeCode = trim((string) ($ctx->input['purpose_code'] ?? ''));
        if ($purposeCode === '') {
            JsonResponse::send(['ok' => false, 'error' => 'purpose_code обязателен'], 422);
            return;
        }

        $result = $this->pd->revokeConsent($session, $purposeCode);
        JsonResponse::send($result, (int) ($result['status'] ?? 200));
    }

    public function listMySubjectRequests(RequestContext $ctx): void
    {
        $session = $this->guardMe($ctx, 'me.personal_data.request');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->requests->listForUser(
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
    }

    public function createSubjectRequest(RequestContext $ctx): void
    {
        $session = $this->guardMe($ctx, 'me.personal_data.request');
        if ($session === null) {
            return;
        }

        $requestType = strtolower(trim((string) ($ctx->input['request_type'] ?? '')));
        $payload = $ctx->input['payload'] ?? null;
        if (!is_array($payload)) {
            $payload = null;
        }

        $result = $this->pd->createSubjectRequest($session, $requestType, $payload);
        if (($result['ok'] ?? false) === true) {
            $this->audit->write(
                'pd.subject_request.created',
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                ['request_type' => $requestType]
            );
        }
        JsonResponse::send($result, (int) ($result['status'] ?? 200));
    }

    public function getOperatorProfile(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.operator.read');
        if ($session === null) {
            return;
        }

        $profile = (new PdOperatorProfileRepository())->find((string) $session['tenant_id']);
        JsonResponse::send(['ok' => true, 'profile' => $profile]);
    }

    public function putOperatorProfile(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.operator.write');
        if ($session === null) {
            return;
        }

        $name = trim((string) ($ctx->input['operator_name'] ?? ''));
        if ($name === '') {
            JsonResponse::send(['ok' => false, 'error' => 'operator_name обязателен'], 422);
            return;
        }

        $profile = (new PdOperatorProfileRepository())->upsert(
            (string) $session['tenant_id'],
            $ctx->input
        );
        $this->audit->write(
            'pd.operator_profile.updated',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            []
        );
        JsonResponse::send(['ok' => true, 'profile' => $profile]);
    }

    public function listPurposes(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.purposes.read');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->purposes->listAll((string) $session['tenant_id']),
        ]);
    }

    public function createPurpose(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.purposes.write');
        if ($session === null) {
            return;
        }

        $code = $this->purposes->normalizeCode((string) ($ctx->input['code'] ?? ''));
        if ($code === '') {
            JsonResponse::send(['ok' => false, 'error' => 'code обязателен'], 422);
            return;
        }

        try {
            $row = $this->purposes->create((string) $session['tenant_id'], $ctx->input);
        } catch (\PDOException) {
            JsonResponse::send(['ok' => false, 'error' => 'Цель с таким code уже существует'], 409);
            return;
        }

        JsonResponse::send(['ok' => true, 'purpose' => $row], 201);
    }

    public function patchPurpose(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.purposes.write');
        if ($session === null) {
            return;
        }

        $code = $this->purposes->normalizeCode((string) ($ctx->input['code'] ?? ''));
        if ($code === '') {
            JsonResponse::send(['ok' => false, 'error' => 'code обязателен'], 422);
            return;
        }

        $row = $this->purposes->update((string) $session['tenant_id'], $code, $ctx->input);
        if ($row === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Цель не найдена'], 404);
            return;
        }

        JsonResponse::send(['ok' => true, 'purpose' => $row]);
    }

    public function listSubjectRequests(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.requests.read');
        if ($session === null) {
            return;
        }

        $status = trim((string) ($ctx->input['status'] ?? ''));
        JsonResponse::send([
            'ok' => true,
            'items' => $this->requests->listForScope(
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                $status !== '' ? $status : null
            ),
        ]);
    }

    public function resolveSubjectRequest(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.requests.handle');
        if ($session === null) {
            return;
        }

        $requestId = (int) ($ctx->input['request_id'] ?? 0);
        $status = strtolower(trim((string) ($ctx->input['status'] ?? '')));
        $note = trim((string) ($ctx->input['handler_note'] ?? ''));
        if ($requestId <= 0 || $status === '') {
            JsonResponse::send(['ok' => false, 'error' => 'request_id и status обязательны'], 422);
            return;
        }

        $result = $this->pd->resolveSubjectRequest($session, $requestId, $status, $note !== '' ? $note : null);
        if (($result['ok'] ?? false) === true) {
            $this->audit->write(
                'pd.subject_request.resolved',
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                ['request_id' => $requestId, 'status' => $status]
            );
        }
        JsonResponse::send($result, (int) ($result['status'] ?? 200));
    }

    public function complianceStatus(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.operator.read');
        if ($session === null) {
            return;
        }

        $status = (new TenantPdComplianceService())->buildStatus((string) $session['tenant_id']);
        JsonResponse::send(['ok' => true, 'compliance' => $status]);
    }

    public function acknowledgeDpa(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.pd.operator.write');
        if ($session === null) {
            return;
        }

        $result = (new TenantPdComplianceService())->recordDpaAcceptance(
            (string) $session['tenant_id'],
            (int) $session['user_id']
        );
        if (($result['ok'] ?? false) === true) {
            $this->audit->write(
                'pd.dpa.acknowledged',
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                []
            );
        }
        JsonResponse::send($result, (int) ($result['status'] ?? 200));
    }

    private function guardMe(RequestContext $ctx, string $permission): ?array
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

        return $session;
    }

    private function guardAdmin(RequestContext $ctx, string $permission): ?array
    {
        $session = $this->authenticator->authenticateSession($ctx);
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return null;
        }

        $allowed = $this->rbac->hasAnyRole(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['super_admin', 'tenant_admin', 'subtenant_admin', 'security_auditor']
        );
        if (!$allowed) {
            JsonResponse::send(['ok' => false, 'error' => 'Недостаточно прав'], 403);
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

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $policy = $this->policies->allowsAdminAction($ctx->server, $tenantId, $subtenantId);
        if (!$policy['ok']) {
            JsonResponse::send(['ok' => false, 'error' => $policy['error']], 403);
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
