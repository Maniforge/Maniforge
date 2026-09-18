<?php
declare(strict_types=1);

/** @var array<string, mixed> $moduleDocs */
$live = is_array($moduleDocs['live_openapi'] ?? null) ? $moduleDocs['live_openapi'] : [];
if (empty($live['enabled'])) {
    return;
}

$defaultBase = (string) ($live['default_base'] ?? 'http://127.0.0.1:8095');
$defaultCode = (string) ($live['default_code'] ?? 'invoice');
?>
<section id="personal-live-openapi" class="app-api-group app-api-group-live">
    <h3 class="app-title h4">Live-загрузка</h3>
    <p class="app-muted">
        Загрузите описание data REST напрямую из
        <code>GET /api/v1/manifests/{code}/openapi</code>
        (нужен Bearer после login в RBAC). Для офлайн-каталога используйте
        <code>make manifest-openapi-export</code>.
    </p>
    <form class="app-api-live-form" id="mf-live-openapi-form">
        <div class="row g-2">
            <div class="col-md-4">
                <label class="form-label small" for="mf-live-base">Manifest Engine URL</label>
                <input class="form-control form-control-sm" id="mf-live-base" type="url" value="<?= htmlspecialchars($defaultBase, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small" for="mf-live-code">Код manifest</label>
                <input class="form-control form-control-sm" id="mf-live-code" type="text" value="<?= htmlspecialchars($defaultCode, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label small" for="mf-live-token">Bearer access_token</label>
                <input class="form-control form-control-sm" id="mf-live-token" type="password" placeholder="после POST /api/v1/auth/login" autocomplete="off">
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <button class="app-button app-button-secondary" type="submit">Загрузить OpenAPI</button>
            <span class="app-muted small align-self-center" id="mf-live-status" aria-live="polite"></span>
        </div>
    </form>
    <div id="mf-live-openapi-endpoints" class="mt-3" hidden></div>
</section>
