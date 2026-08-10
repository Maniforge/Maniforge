<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
require_once __DIR__ . '/data/api-docs/helpers.php';
$apiCatalog = require __DIR__ . '/data/api-docs/catalog.php';
api_doc_merge_personal_modules($apiCatalog, __DIR__ . '/data/api-docs/generated');

$defaultModuleKey = (string) ($apiCatalog['default_module_key'] ?? 'public');
$apiDocsByKey = [];
$apiModules = [];
foreach ($apiCatalog['categories'] as $category) {
    foreach ($category['modules'] as $module) {
        if (!empty($module['inline']) && is_array($module['docs'] ?? null)) {
            $apiDocsByKey[(string) $module['key']] = $module['docs'];
        } else {
            $apiDocsByKey[(string) $module['key']] = require __DIR__ . '/data/api-docs/' . $module['file'];
        }
        $apiModules[] = [
            'category' => $category,
            'module' => $module,
        ];
    }
}

$pageTitle = 'API — ' . $branding['app_name'];
$activeNav = 'api';
$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero" id="api-page-hero">
    <div class="app-api-hero-inner">
        <div>
            <span class="app-kicker">Документация API</span>
            <h1 class="app-title h2">Каталог API по модулям</h1>
            <p class="app-lead app-api-hero-lead">
                Выберите модуль во вкладках. Токены и заголовки — в справочнике
                <a href="#api-credentials-overview" class="text-white text-decoration-underline" data-api-tab-link="api-credentials-docs">Ключи</a>
                и
                <a href="#api-headers-kit" class="text-white text-decoration-underline" data-api-tab-link="api-headers-docs">Заголовки</a>.
                <strong>Публичные</strong> — RBAC внутри тенанта; <strong>приватные</strong> — операции платформы.
            </p>
        </div>
        <button type="button" class="app-api-hero-collapse" id="api-hero-collapse" aria-expanded="true" aria-controls="api-page-hero">
            Свернуть
        </button>
    </div>
</section>
<script>
(function () {
    try {
        if (!document.documentElement.classList.contains('api-docs-compact')) {
            return;
        }
        var hero = document.getElementById('api-page-hero');
        var btn = document.getElementById('api-hero-collapse');
        if (hero) {
            hero.classList.add('is-collapsed');
        }
        if (btn) {
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'Развернуть';
        }
    } catch (e) {}
})();
</script>

<div class="app-api-shell app-main-wide" id="api-docs-app">
<script>
(function () {
    try {
        if (document.documentElement.classList.contains('api-docs-compact')) {
            document.getElementById('api-docs-app').classList.add('is-compact');
        }
    } catch (e) {}
})();
</script>
    <div class="app-api-dock" id="api-dock">
        <div class="app-api-category app-panel" id="api-category">
            <?php require __DIR__ . '/partials/api-docs-tabs.php'; ?>
            <nav class="app-api-breadcrumbs" id="api-breadcrumbs" aria-label="Текущее положение" hidden></nav>
        </div>
        <div class="app-api-workspace">
            <div class="app-api-tab-panels">
                <?php foreach ($apiModules as $item): ?>
                    <?php
                    $module = $item['module'];
                    $moduleKey = (string) $module['key'];
                    $moduleDocs = $apiDocsByKey[$moduleKey];
                    $sectionId = (string) $module['section_id'];
                    $isActive = $moduleKey === $defaultModuleKey;
                    $isReference = !empty($module['is_reference']);
                    ?>
                    <div
                        class="app-api-tab-panel<?= $isActive ? ' is-active' : '' ?>"
                        id="api-panel-<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>"
                        role="tabpanel"
                        aria-labelledby="api-tab-<?= htmlspecialchars($moduleKey, ENT_QUOTES, 'UTF-8') ?>"
                        data-panel="<?= htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $isReference ? ' data-api-reference="true"' : '' ?>
                        <?= $isActive ? '' : ' hidden' ?>
                    >
                        <div class="app-api-mobile-tabs" role="tablist" aria-label="Колонки документации">
                            <button type="button" class="app-api-mobile-tab" data-api-mobile-col="sidebar" role="tab" aria-selected="false">Разделы</button>
                            <button type="button" class="app-api-mobile-tab is-active" data-api-mobile-col="content" role="tab" aria-selected="true">Документ</button>
                        </div>
                        <div class="app-api-layout is-mobile-content">
                            <aside class="app-api-sidebar app-panel">
                                <span class="app-kicker">Разделы</span>
                                <?php
                                $navModule = $module;
                                $navModuleDocs = $moduleDocs;
                                require __DIR__ . '/partials/api-docs-nav.php';
                                ?>
                            </aside>
                            <div class="app-api-content app-panel">
                                <?php if ($isReference): ?>
                                    <?php
                                    $referencePanel = (string) ($module['reference_panel'] ?? 'headers');
                                    if ($referencePanel === 'credentials') {
                                        $credentialsDocs = $moduleDocs;
                                        require __DIR__ . '/partials/api-docs-credentials-panel.php';
                                    } else {
                                        $headersDocs = $moduleDocs;
                                        require __DIR__ . '/partials/api-docs-headers-panel.php';
                                    }
                                    ?>
                                <?php else: ?>
                                    <?php
                                    $isFirst = true;
                                    require __DIR__ . '/partials/api-docs-module-section.php';
                                    ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <footer class="app-api-dock-footer" id="api-dock-footer" aria-label="Справочные ссылки">
            <?php foreach ($apiCatalog['references'] as $ref): ?>
                <a href="<?= htmlspecialchars((string) $ref['href'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) $ref['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
            <span class="app-api-dock-footer-copy">
                © <?= htmlspecialchars((string) $branding['company_year'], ENT_QUOTES, 'UTF-8') ?>
                <?= htmlspecialchars((string) $branding['company_name'], ENT_QUOTES, 'UTF-8') ?>
            </span>
        </footer>
    </div>

    <div class="app-api-search" id="api-docs-search" hidden>
        <div class="app-api-search-backdrop" data-api-search-close tabindex="-1"></div>
        <div class="app-api-search-dialog" role="dialog" aria-modal="true" aria-labelledby="api-search-title">
            <div class="app-api-search-head">
                <h2 class="app-title h6 mb-0" id="api-search-title">Поиск по документации</h2>
                <kbd class="app-api-search-kbd">Esc</kbd>
            </div>
            <input
                type="search"
                class="form-control app-api-search-input"
                id="api-docs-search-input"
                placeholder="Метод, путь, раздел…"
                autocomplete="off"
                spellcheck="false"
                role="combobox"
                aria-expanded="true"
                aria-controls="api-docs-search-results"
                aria-autocomplete="list"
            >
            <ul class="app-api-search-results" id="api-docs-search-results" role="listbox" aria-label="Результаты поиска"></ul>
            <p class="app-api-search-hint app-muted small mb-0">Ctrl+K — открыть поиск</p>
        </div>
    </div>
</div>
<button
    type="button"
    class="app-api-scroll-top"
    id="api-scroll-top"
    aria-label="Наверх"
    title="Наверх"
    hidden
>
    <i class="bi bi-arrow-up" aria-hidden="true"></i>
</button>
<script src="/assets/js/api-docs.js"></script>
<script src="/assets/js/api-docs-enhancements.js"></script>
<script src="/assets/js/api-docs-manifest.js"></script>
<?php require __DIR__ . '/layout/footer.php';
