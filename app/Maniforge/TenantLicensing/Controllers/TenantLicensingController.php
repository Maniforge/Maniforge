<?php
declare(strict_types=1);

namespace App\Maniforge\TenantLicensing\Controllers;

use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;
use App\Maniforge\TenantLicensing\Support\JsonResponse;
use App\Maniforge\TenantLicensing\Support\RequestContext;

final class TenantLicensingController
{
    public function __construct(
        private readonly TenantLicensingRepository $repository = new TenantLicensingRepository(),
    ) {
    }

    public function admin(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $this->sendHtml($this->renderAdmin());
    }

    public function apiDocs(RequestContext $ctx): void
    {
        $base = $this->basePath();
        $this->sendHtml($this->page('Tenant/Licensing API docs', '
            <section class="card">
                <h1>Tenant/Licensing API</h1>
                <p>JSON API для platform-level lifecycle: tenants, subtenants, plans, licenses, entitlements и quotas.</p>
                <div class="actions">
                    <a href="' . $this->e($base . '/admin') . '">Platform admin</a>
                    <a href="' . $this->e($base . '/health') . '">Health</a>
                    <a href="/docs/MANIFORGE_TENANT_LICENSING_OPENAPI.yaml">OpenAPI YAML</a>
                </div>
            </section>
            <section class="card">
                <h2>Admin API</h2>
                <ul class="endpoint-list">
                    <li><code>GET /api/v1/tenants</code> - список tenants</li>
                    <li><code>POST /api/v1/tenants</code> - создать tenant</li>
                    <li><code>PATCH /api/v1/tenants/{tenantCode}</code> - обновить/suspend tenant</li>
                    <li><code>GET /api/v1/tenants/{tenantCode}/subtenants</code> - список subtenants</li>
                    <li><code>POST /api/v1/tenants/{tenantCode}/subtenants</code> - создать subtenant</li>
                    <li><code>PATCH /api/v1/tenants/{tenantCode}/subtenants/{subtenantCode}</code> - обновить/suspend subtenant</li>
                    <li><code>GET /api/v1/plans</code> - список plans</li>
                    <li><code>POST /api/v1/plans</code> - создать/update plan</li>
                    <li><code>PATCH /api/v1/plans/{planCode}</code> - обновить plan</li>
                    <li><code>GET /api/v1/licenses</code> - список licenses</li>
                    <li><code>POST /api/v1/licenses/assign</code> - выдать license</li>
                    <li><code>PATCH /api/v1/licenses/{licenseId}</code> - обновить/suspend license</li>
                    <li><code>POST /api/v1/licenses/revoke</code> - revoke active license tenant-а</li>
                    <li><code>GET /api/v1/tenants/{tenantCode}/entitlements</code> - entitlement snapshot</li>
                    <li><code>GET /api/v1/tenants/{tenantCode}/quota</code> - quota usage</li>
                </ul>
            </section>
        '));
    }

    public function adminCreateTenant(RequestContext $ctx): void
    {
        if (!$this->guardAdminForm($ctx)) {
            return;
        }

        $result = $this->repository->createTenant(
            (string) ($ctx->input['code'] ?? ''),
            trim((string) ($ctx->input['name'] ?? '')),
            $ctx->actor(),
            $this->decodeJsonInput((string) ($ctx->input['metadata_json'] ?? '{}'))
        );
        $this->redirectAdmin($result);
    }

    public function adminUpdateTenant(RequestContext $ctx): void
    {
        if (!$this->guardAdminForm($ctx)) {
            return;
        }

        $result = $this->repository->updateTenant(
            (string) ($ctx->input['code'] ?? ''),
            [
                'name' => $ctx->input['name'] ?? '',
                'status' => $ctx->input['status'] ?? '',
            ],
            $ctx->actor()
        );
        $this->redirectAdmin($result);
    }

    public function adminCreateSubtenant(RequestContext $ctx): void
    {
        if (!$this->guardAdminForm($ctx)) {
            return;
        }

        $result = $this->repository->createSubtenant(
            (string) ($ctx->input['tenant_code'] ?? ''),
            (string) ($ctx->input['code'] ?? ''),
            trim((string) ($ctx->input['name'] ?? '')),
            $ctx->actor(),
            $this->decodeJsonInput((string) ($ctx->input['metadata_json'] ?? '{}'))
        );
        $this->redirectAdmin($result);
    }

    public function adminUpdateSubtenant(RequestContext $ctx): void
    {
        if (!$this->guardAdminForm($ctx)) {
            return;
        }

        $result = $this->repository->updateSubtenant(
            (string) ($ctx->input['tenant_code'] ?? ''),
            (string) ($ctx->input['code'] ?? ''),
            [
                'name' => $ctx->input['name'] ?? '',
                'status' => $ctx->input['status'] ?? '',
            ],
            $ctx->actor()
        );
        $this->redirectAdmin($result);
    }

    public function adminUpsertPlan(RequestContext $ctx): void
    {
        if (!$this->guardAdminForm($ctx)) {
            return;
        }

        $result = $this->repository->upsertPlan(
            (string) ($ctx->input['code'] ?? ''),
            trim((string) ($ctx->input['name'] ?? '')),
            (string) ($ctx->input['status'] ?? 'active'),
            $this->decodeJsonInput((string) ($ctx->input['features_json'] ?? '{}')),
            $this->decodeJsonInput((string) ($ctx->input['limits_json'] ?? '{}')),
            $ctx->actor()
        );
        $this->redirectAdmin($result);
    }

    public function adminAssignLicense(RequestContext $ctx): void
    {
        if (!$this->guardAdminForm($ctx)) {
            return;
        }

        $seatsMax = isset($ctx->input['seats_max']) ? (int) $ctx->input['seats_max'] : null;
        $result = $this->repository->assignLicense(
            (string) ($ctx->input['tenant_code'] ?? ''),
            (string) ($ctx->input['plan_code'] ?? ''),
            $ctx->actor(),
            $this->nullableString($ctx->input['expires_at'] ?? null),
            $seatsMax !== null && $seatsMax > 0 ? $seatsMax : null
        );
        $this->redirectAdmin($result);
    }

    public function adminUpdateLicense(RequestContext $ctx): void
    {
        if (!$this->guardAdminForm($ctx)) {
            return;
        }

        $result = $this->repository->updateLicense(
            (int) ($ctx->input['license_id'] ?? 0),
            [
                'status' => $ctx->input['status'] ?? '',
                'expires_at' => $ctx->input['expires_at'] ?? null,
                'seats_max' => $ctx->input['seats_max'] ?? null,
            ],
            $ctx->actor()
        );
        $this->redirectAdmin($result);
    }

    public function adminRevokeLicense(RequestContext $ctx): void
    {
        if (!$this->guardAdminForm($ctx)) {
            return;
        }

        $result = $this->repository->revokeLicense(
            (string) ($ctx->input['tenant_code'] ?? ''),
            $ctx->actor(),
            trim((string) ($ctx->input['reason'] ?? 'platform_admin_revoke'))
        );
        $this->redirectAdmin($result);
    }

    public function tenants(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send(['ok' => true, 'items' => $this->repository->listTenants()]);
    }

    public function createTenant(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $result = $this->repository->createTenant(
            (string) ($ctx->input['code'] ?? ''),
            trim((string) ($ctx->input['name'] ?? '')),
            $ctx->actor(),
            is_array($ctx->input['metadata'] ?? null) ? $ctx->input['metadata'] : []
        );
        JsonResponse::send($result, (int) $result['status']);
    }

    public function updateTenant(RequestContext $ctx, string $tenantCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $result = $this->repository->updateTenant($tenantCode, $ctx->input, $ctx->actor());
        JsonResponse::send($result, (int) $result['status']);
    }

    public function managedTenants(RequestContext $ctx, string $agencyCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'agency_code' => $agencyCode,
            'items' => $this->repository->listManagedTenants($agencyCode),
        ]);
    }

    public function createManagedTenant(RequestContext $ctx, string $agencyCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $result = $this->repository->createManagedTenantGrant(
            $agencyCode,
            (string) ($ctx->input['managed_tenant_code'] ?? ''),
            (string) ($ctx->input['grant_level'] ?? 'operator'),
            $ctx->actor(),
            is_array($ctx->input['metadata'] ?? null) ? $ctx->input['metadata'] : []
        );
        JsonResponse::send($result, (int) $result['status']);
    }

    public function revokeManagedTenant(RequestContext $ctx, string $agencyCode, string $managedCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $result = $this->repository->revokeManagedTenantGrant($agencyCode, $managedCode, $ctx->actor());
        JsonResponse::send($result, (int) $result['status']);
    }

    public function subtenants(RequestContext $ctx, string $tenantCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send(['ok' => true, 'items' => $this->repository->listSubtenants($tenantCode)]);
    }

    public function createSubtenant(RequestContext $ctx, string $tenantCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $result = $this->repository->createSubtenant(
            $tenantCode,
            (string) ($ctx->input['code'] ?? ''),
            trim((string) ($ctx->input['name'] ?? '')),
            $ctx->actor(),
            is_array($ctx->input['metadata'] ?? null) ? $ctx->input['metadata'] : []
        );
        JsonResponse::send($result, (int) $result['status']);
    }

    public function updateSubtenant(RequestContext $ctx, string $tenantCode, string $subtenantCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $result = $this->repository->updateSubtenant($tenantCode, $subtenantCode, $ctx->input, $ctx->actor());
        JsonResponse::send($result, (int) $result['status']);
    }

    public function plans(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send(['ok' => true, 'items' => $this->repository->listPlans()]);
    }

    public function createPlan(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $features = $this->arrayInput($ctx->input['features'] ?? []);
        $limits = $this->arrayInput($ctx->input['limits'] ?? []);
        $result = $this->repository->createPlan(
            (string) ($ctx->input['code'] ?? ''),
            trim((string) ($ctx->input['name'] ?? '')),
            trim((string) ($ctx->input['status'] ?? 'active')),
            $features,
            $limits,
            $ctx->actor()
        );
        JsonResponse::send($result, (int) $result['status']);
    }

    public function updatePlan(RequestContext $ctx, string $planCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $changes = $ctx->input;
        if (array_key_exists('features', $changes)) {
            $changes['features'] = $this->arrayInput($changes['features']);
        }
        if (array_key_exists('limits', $changes)) {
            $changes['limits'] = $this->arrayInput($changes['limits']);
        }

        $result = $this->repository->updatePlan($planCode, $changes, $ctx->actor());
        JsonResponse::send($result, (int) $result['status']);
    }

    public function upsertPlan(RequestContext $ctx, ?string $planCode = null): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $features = $ctx->input['features'] ?? [];
        $limits = $ctx->input['limits'] ?? [];
        $result = $this->repository->upsertPlan(
            (string) ($planCode ?? ($ctx->input['code'] ?? '')),
            trim((string) ($ctx->input['name'] ?? '')),
            (string) ($ctx->input['status'] ?? 'active'),
            is_array($features) ? $features : [],
            is_array($limits) ? $limits : [],
            $ctx->actor()
        );
        JsonResponse::send($result, (int) $result['status']);
    }

    public function licenses(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send(['ok' => true, 'items' => $this->repository->listLicenses()]);
    }

    public function assignLicense(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $seatsMax = isset($ctx->input['seats_max']) ? (int) $ctx->input['seats_max'] : null;
        $result = $this->repository->assignLicense(
            (string) ($ctx->input['tenant_code'] ?? ''),
            (string) ($ctx->input['plan_code'] ?? ''),
            $ctx->actor(),
            $this->nullableString($ctx->input['expires_at'] ?? null),
            $seatsMax !== null && $seatsMax > 0 ? $seatsMax : null
        );
        JsonResponse::send($result, (int) $result['status']);
    }

    public function updateLicense(RequestContext $ctx, int $licenseId): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $result = $this->repository->updateLicense($licenseId, $ctx->input, $ctx->actor());
        JsonResponse::send($result, (int) $result['status']);
    }

    public function revokeLicense(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        $result = $this->repository->revokeLicense(
            (string) ($ctx->input['tenant_code'] ?? ''),
            $ctx->actor(),
            trim((string) ($ctx->input['reason'] ?? 'manual_revoke'))
        );
        JsonResponse::send($result, (int) $result['status']);
    }

    public function entitlements(RequestContext $ctx, string $tenantCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'tenant_code' => $tenantCode,
            'entitlements' => $this->repository->entitlements($tenantCode),
        ]);
    }

    public function quota(RequestContext $ctx, string $tenantCode): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'tenant_code' => $tenantCode,
            'items' => $this->repository->quota($tenantCode, $this->nullableString($_GET['metric'] ?? null)),
        ]);
    }

    public function platformOpsSummary(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'summary' => $this->repository->platformOpsSummary(),
        ]);
    }

    public function events(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->repository->listEvents(
                $this->nullableString($_GET['tenant_code'] ?? null),
                (int) ($_GET['limit'] ?? 100)
            ),
        ]);
    }

    public function audit(RequestContext $ctx): void
    {
        if (!$this->guardAdmin($ctx)) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->repository->listAudit(
                $this->nullableString($_GET['tenant_code'] ?? null),
                (int) ($_GET['limit'] ?? 100)
            ),
        ]);
    }

    public function accessState(RequestContext $ctx, string $tenantCode, string $subtenantCode): void
    {
        if (!$this->guardInternal($ctx)) {
            return;
        }

        JsonResponse::send($this->repository->accessState($tenantCode, $subtenantCode));
    }

    public function pendingEvents(RequestContext $ctx): void
    {
        if (!$this->guardInternal($ctx)) {
            return;
        }

        JsonResponse::send(['ok' => true, 'items' => $this->repository->pendingEvents()]);
    }

    public function ackEvent(RequestContext $ctx, int $eventId): void
    {
        if (!$this->guardInternal($ctx)) {
            return;
        }

        JsonResponse::send(['ok' => $this->repository->ackEvent($eventId)]);
    }

    private function renderAdmin(): string
    {
        $tenants = $this->repository->listTenants();
        $subtenants = $this->repository->listAllSubtenants();
        $plans = $this->repository->listPlans();
        $licenses = $this->repository->listLicenses();
        $base = $this->basePath();
        $token = $this->adminTokenParam();
        $message = trim((string) ($_GET['message'] ?? ''));
        $error = trim((string) ($_GET['error'] ?? ''));

        return $this->page('Tenant/Licensing Platform Admin', '
            <section class="hero">
                <div>
                    <p class="eyebrow">Maniforge Tenant/Licensing</p>
                    <h1>Platform Admin</h1>
                    <p>Операционная консоль для tenants, subtenants, plans, licenses и quota snapshots поверх текущего JSON API.</p>
                </div>
                <div class="actions">
                    <a href="' . $this->e($base . '/api-docs') . '">API docs</a>
                    <a href="' . $this->e($base . '/health') . '">Health</a>
                    <a href="/docs/MANIFORGE_TENANT_LICENSING_SERVICE.md">Service docs</a>
                </div>
            </section>
            ' . ($message === '' ? '' : '<p class="notice ok">' . $this->e($message) . '</p>') . '
            ' . ($error === '' ? '' : '<p class="notice error">' . $this->e($error) . '</p>') . '
            <section class="grid two">
                <article class="card">
                    <h2>Create tenant</h2>
                    <form method="post" action="' . $this->e($base . '/admin/tenants') . '">
                        ' . $this->formHidden($token) . '
                        <label>Code<input name="code" required placeholder="acme"></label>
                        <label>Name<input name="name" required placeholder="Acme Corp"></label>
                        <label>Metadata JSON<textarea name="metadata_json" rows="3">{}</textarea></label>
                        <button>Create tenant</button>
                    </form>
                </article>
                <article class="card">
                    <h2>Create subtenant</h2>
                    <form method="post" action="' . $this->e($base . '/admin/subtenants') . '">
                        ' . $this->formHidden($token) . '
                        <label>Tenant code<input name="tenant_code" required placeholder="acme"></label>
                        <label>Code<input name="code" required placeholder="default"></label>
                        <label>Name<input name="name" required placeholder="Default workspace"></label>
                        <label>Metadata JSON<textarea name="metadata_json" rows="3">{}</textarea></label>
                        <button>Create subtenant</button>
                    </form>
                </article>
            </section>
            <section class="grid two">
                <article class="card">
                    <h2>Upsert plan</h2>
                    <form method="post" action="' . $this->e($base . '/admin/plans') . '">
                        ' . $this->formHidden($token) . '
                        <label>Code<input name="code" required placeholder="business"></label>
                        <label>Name<input name="name" required placeholder="Business"></label>
                        <label>Status' . $this->statusSelect('status', 'active', ['active', 'disabled']) . '</label>
                        <label>Features JSON<textarea name="features_json" rows="4">{&quot;rbac&quot;:true,&quot;admin_api&quot;:true}</textarea></label>
                        <label>Limits JSON<textarea name="limits_json" rows="4">{&quot;max_users&quot;:100,&quot;max_sessions&quot;:500}</textarea></label>
                        <button>Save plan</button>
                    </form>
                </article>
                <article class="card">
                    <h2>Assign license</h2>
                    <form method="post" action="' . $this->e($base . '/admin/licenses/assign') . '">
                        ' . $this->formHidden($token) . '
                        <label>Tenant code<input name="tenant_code" required placeholder="acme"></label>
                        <label>Plan code<input name="plan_code" required placeholder="starter"></label>
                        <label>Expires at<input name="expires_at" placeholder="2026-12-31 23:59:59"></label>
                        <label>Seats max<input name="seats_max" type="number" min="1" placeholder="100"></label>
                        <button>Assign license</button>
                    </form>
                </article>
            </section>
            <section class="card">
                <h2>Tenants</h2>
                ' . $this->renderTenantsTable($tenants, $base, $token) . '
            </section>
            <section class="card">
                <h2>Subtenants</h2>
                ' . $this->renderSubtenantsTable($subtenants, $base, $token) . '
            </section>
            <section class="card">
                <h2>Plans</h2>
                ' . $this->renderPlansTable($plans, $base, $token) . '
            </section>
            <section class="card">
                <h2>Licenses</h2>
                ' . $this->renderLicensesTable($licenses, $base, $token) . '
            </section>
            <section class="card">
                <h2>Quotas and entitlements</h2>
                <p>Quota snapshots доступны per tenant через JSON API. Используйте tenant code из таблицы выше.</p>
                <div class="actions">
                    <a href="' . $this->e($base . '/api/v1/tenants/default/quota') . '">Default quota JSON</a>
                    <a href="' . $this->e($base . '/api/v1/tenants/default/entitlements') . '">Default entitlements JSON</a>
                </div>
            </section>
        ');
    }

    private function renderTenantsTable(array $tenants, string $base, string $token): string
    {
        if ($tenants === []) {
            return '<p class="empty">Tenants пока нет.</p>';
        }

        $rows = '';
        foreach ($tenants as $tenant) {
            $code = (string) $tenant['code'];
            $rows .= '<tr>
                <td><code>' . $this->e($code) . '</code></td>
                <td>
                    <form class="inline-form" method="post" action="' . $this->e($base . '/admin/tenants/update') . '">
                        ' . $this->formHidden($token) . '
                        <input type="hidden" name="code" value="' . $this->e($code) . '">
                        <input name="name" value="' . $this->e((string) $tenant['name']) . '">
                </td>
                <td>' . $this->statusSelect('status', (string) $tenant['status'], ['active', 'suspended', 'disabled']) . '</td>
                <td>' . $this->e((string) ($tenant['suspended_at'] ?? '')) . '</td>
                <td><button>Save</button></form></td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Status</th><th>Suspended at</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function renderSubtenantsTable(array $subtenants, string $base, string $token): string
    {
        if ($subtenants === []) {
            return '<p class="empty">Subtenants пока нет.</p>';
        }

        $rows = '';
        foreach ($subtenants as $subtenant) {
            $tenantCode = (string) $subtenant['tenant_code'];
            $code = (string) $subtenant['code'];
            $rows .= '<tr>
                <td><code>' . $this->e($tenantCode) . '</code></td>
                <td><code>' . $this->e($code) . '</code></td>
                <td>
                    <form class="inline-form" method="post" action="' . $this->e($base . '/admin/subtenants/update') . '">
                        ' . $this->formHidden($token) . '
                        <input type="hidden" name="tenant_code" value="' . $this->e($tenantCode) . '">
                        <input type="hidden" name="code" value="' . $this->e($code) . '">
                        <input name="name" value="' . $this->e((string) $subtenant['name']) . '">
                </td>
                <td>' . $this->statusSelect('status', (string) $subtenant['status'], ['active', 'suspended', 'disabled']) . '</td>
                <td><a href="' . $this->e($base . '/api/v1/tenants/' . rawurlencode($tenantCode) . '/quota') . '">Quota</a></td>
                <td><button>Save</button></form></td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Tenant</th><th>Code</th><th>Name</th><th>Status</th><th>Quota</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function renderPlansTable(array $plans, string $base, string $token): string
    {
        if ($plans === []) {
            return '<p class="empty">Plans пока нет.</p>';
        }

        $rows = '';
        foreach ($plans as $plan) {
            $rows .= '<tr>
                <td><code>' . $this->e((string) $plan['code']) . '</code></td>
                <td>
                    <form class="inline-form" method="post" action="' . $this->e($base . '/admin/plans') . '">
                        ' . $this->formHidden($token) . '
                        <input type="hidden" name="code" value="' . $this->e((string) $plan['code']) . '">
                        <input name="name" value="' . $this->e((string) $plan['name']) . '">
                </td>
                <td>' . $this->statusSelect('status', (string) $plan['status'], ['active', 'disabled']) . '</td>
                <td><textarea name="features_json" rows="3">' . $this->e($this->prettyJson((string) $plan['features_json'])) . '</textarea></td>
                <td><textarea name="limits_json" rows="3">' . $this->e($this->prettyJson((string) $plan['limits_json'])) . '</textarea></td>
                <td><button>Save</button></form></td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Status</th><th>Features</th><th>Limits</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function renderLicensesTable(array $licenses, string $base, string $token): string
    {
        if ($licenses === []) {
            return '<p class="empty">Licenses пока нет.</p>';
        }

        $rows = '';
        foreach ($licenses as $license) {
            $id = (int) $license['id'];
            $tenantCode = (string) $license['tenant_code'];
            $rows .= '<tr>
                <td><code>#' . $id . '</code></td>
                <td><code>' . $this->e($tenantCode) . '</code></td>
                <td><code>' . $this->e((string) $license['plan_code']) . '</code></td>
                <td>
                    <form class="inline-form" method="post" action="' . $this->e($base . '/admin/licenses/update') . '">
                        ' . $this->formHidden($token) . '
                        <input type="hidden" name="license_id" value="' . $id . '">
                        ' . $this->statusSelect('status', (string) $license['status'], ['active', 'suspended', 'revoked', 'expired']) . '
                </td>
                <td><input name="expires_at" value="' . $this->e((string) ($license['expires_at'] ?? '')) . '" placeholder="YYYY-MM-DD HH:MM:SS"></td>
                <td><input name="seats_max" type="number" min="1" value="' . $this->e((string) ($license['seats_max'] ?? '')) . '"></td>
                <td><button>Save</button></form></td>
                <td>
                    <form method="post" action="' . $this->e($base . '/admin/licenses/revoke') . '">
                        ' . $this->formHidden($token) . '
                        <input type="hidden" name="tenant_code" value="' . $this->e($tenantCode) . '">
                        <input type="hidden" name="reason" value="platform_admin_revoke">
                        <button class="danger">Revoke active</button>
                    </form>
                </td>
            </tr>';
        }

        return '<div class="table-wrap"><table><thead><tr><th>ID</th><th>Tenant</th><th>Plan</th><th>Status</th><th>Expires</th><th>Seats</th><th></th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>' . $this->e($title) . '</title>
            <style>
                :root{color-scheme:light dark;--bg:#0f172a;--panel:#111827;--text:#e5e7eb;--muted:#94a3b8;--border:#334155;--accent:#38bdf8;--danger:#f87171;--ok:#34d399}
                *{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#0f172a,#111827);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
                main{max-width:1280px;margin:0 auto;padding:32px 20px 56px}.hero,.card{background:rgba(17,24,39,.88);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 18px 50px rgba(0,0,0,.22)}
                .hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.eyebrow{color:var(--accent);font-weight:700;text-transform:uppercase;letter-spacing:.08em}h1,h2{margin:0 0 12px}p{color:var(--muted)}
                .grid{display:grid;gap:18px}.grid.two{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}.actions{display:flex;gap:10px;flex-wrap:wrap}.actions a,a{color:var(--accent);text-decoration:none}
                form{display:grid;gap:10px}label{display:grid;gap:6px;color:var(--muted);font-weight:600}input,select,textarea{width:100%;border:1px solid var(--border);border-radius:10px;background:#020617;color:var(--text);padding:9px 10px;font:inherit}
                button{border:0;border-radius:10px;background:var(--accent);color:#00111c;padding:9px 12px;font-weight:800;cursor:pointer}.danger{background:var(--danger);color:#260101}
                .table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:760px}th,td{border-bottom:1px solid var(--border);padding:10px;vertical-align:top;text-align:left}th{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.04em}
                .inline-form{display:contents}.notice{border-radius:12px;padding:12px 14px}.notice.ok{background:rgba(52,211,153,.12);color:var(--ok)}.notice.error{background:rgba(248,113,113,.12);color:var(--danger)}.empty{margin:0}.endpoint-list{display:grid;gap:8px;padding-left:20px}
                code{color:#bae6fd}
            </style></head><body><main>' . $body . '</main></body></html>';
    }

    private function sendHtml(string $html, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    private function redirectAdmin(array $result): void
    {
        $messageKey = ((bool) ($result['ok'] ?? false)) ? 'message' : 'error';
        $message = (string) ($result['error'] ?? 'Операция выполнена');
        $token = $this->adminTokenParam();
        $query = $token === '' ? [] : ['token' => $token];
        $query[$messageKey] = $message;
        header('Location: ' . $this->basePath() . '/admin?' . http_build_query($query));
        http_response_code(303);
    }

    private function guardAdminForm(RequestContext $ctx): bool
    {
        if (!$this->guardAdmin($ctx)) {
            return false;
        }
        if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($ctx->input['_csrf'] ?? ''))) {
            $this->sendHtml($this->page('CSRF error', '<section class="card"><h1>CSRF validation failed</h1><p>Обновите admin-страницу и повторите действие.</p></section>'), 419);
            return false;
        }

        return true;
    }

    private function formHidden(string $token): string
    {
        $html = '<input type="hidden" name="_csrf" value="' . $this->e((string) ($_SESSION['csrf_token'] ?? '')) . '">';
        if ($token !== '') {
            $html .= '<input type="hidden" name="_admin_token" value="' . $this->e($token) . '">';
        }

        return $html;
    }

    private function statusSelect(string $name, string $current, array $options): string
    {
        $html = '<select name="' . $this->e($name) . '">';
        foreach ($options as $option) {
            $selected = $option === $current ? ' selected' : '';
            $html .= '<option value="' . $this->e($option) . '"' . $selected . '>' . $this->e($option) . '</option>';
        }

        return $html . '</select>';
    }

    private function prettyJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }

        return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function decodeJsonInput(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function basePath(): string
    {
        return '/tenant-licensing';
    }

    private function adminTokenParam(): string
    {
        return trim((string) ($_GET['token'] ?? $_POST['_admin_token'] ?? ''));
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function guardAdmin(RequestContext $ctx): bool
    {
        return $this->guardToken($ctx, (string) ($_ENV['TENANT_LICENSING_ADMIN_TOKEN'] ?? ''));
    }

    private function guardInternal(RequestContext $ctx): bool
    {
        return $this->guardToken($ctx, (string) ($_ENV['TENANT_LICENSING_INTERNAL_TOKEN'] ?? ''));
    }

    private function guardToken(RequestContext $ctx, string $expected): bool
    {
        if ($expected === '') {
            $env = strtolower((string) ($_ENV['APP_ENV'] ?? 'production'));
            if (in_array($env, ['local', 'testing', 'test'], true)) {
                return true;
            }

            JsonResponse::send(['ok' => false, 'error' => 'Service token не настроен'], 503);
            return false;
        }

        $provided = $ctx->bearerToken();
        if ($provided === '') {
            $provided = trim((string) ($_GET['token'] ?? $ctx->input['_admin_token'] ?? ''));
        }

        if (hash_equals($expected, $provided)) {
            return true;
        }

        JsonResponse::send(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function arrayInput(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
