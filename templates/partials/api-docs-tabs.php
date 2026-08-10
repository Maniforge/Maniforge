<?php
declare(strict_types=1);

/** @var list<array{category: array<string, mixed>, module: array<string, mixed>}> $apiModules */
/** @var string $defaultModuleKey */

$defaultCategoryId = '';
foreach ($apiModules as $item) {
    if ((string) $item['module']['key'] === $defaultModuleKey) {
        $defaultCategoryId = (string) $item['category']['id'];
        break;
    }
}
?>
<div class="app-api-tabs-bar" role="navigation" aria-label="Модули API">
    <?php
    $lastCategoryId = null;
    foreach ($apiModules as $item):
        $category = $item['category'];
        $module = $item['module'];
        $categoryId = (string) $category['id'];
        $isActiveTab = (string) $module['key'] === $defaultModuleKey;
        if ($lastCategoryId !== $categoryId):
            if ($lastCategoryId !== null):
                echo '</div></div>';
            endif;
            $lastCategoryId = $categoryId;
            ?>
            <div
                class="app-api-tabs-group<?= $categoryId === $defaultCategoryId ? ' is-current' : '' ?>"
                data-category-id="<?= htmlspecialchars($categoryId, ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="app-api-tabs-group-label"><?= htmlspecialchars((string) $category['title'], ENT_QUOTES, 'UTF-8') ?></span>
                <div class="app-api-tabs-list" role="tablist" aria-label="<?= htmlspecialchars((string) $category['title'], ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
                <button
                    type="button"
                    class="app-api-tab<?= $isActiveTab ? ' is-active' : '' ?>"
                    role="tab"
                    id="api-tab-<?= htmlspecialchars((string) $module['key'], ENT_QUOTES, 'UTF-8') ?>"
                    aria-selected="<?= $isActiveTab ? 'true' : 'false' ?>"
                    aria-controls="api-panel-<?= htmlspecialchars((string) $module['key'], ENT_QUOTES, 'UTF-8') ?>"
                    data-panel="<?= htmlspecialchars((string) $module['section_id'], ENT_QUOTES, 'UTF-8') ?>"
                    data-category="<?= htmlspecialchars($categoryId, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <?= htmlspecialchars((string) $module['nav_label'], ENT_QUOTES, 'UTF-8') ?>
                </button>
    <?php endforeach; ?>
    <?php if ($lastCategoryId !== null): ?>
        </div></div>
    <?php endif; ?>

    <div class="app-api-tabs-toolbar">
        <button
            type="button"
            class="app-api-tabs-search"
            id="api-search-trigger"
            title="Поиск (Ctrl+K)"
            aria-expanded="false"
            aria-controls="api-docs-search"
        >
            <i class="bi bi-search" aria-hidden="true"></i>
            <span class="d-none d-md-inline">Поиск</span>
            <kbd class="app-api-search-kbd d-none d-lg-inline">Ctrl+K</kbd>
        </button>
        <button
            type="button"
            class="app-api-tabs-expand"
            id="api-tabs-expand"
            aria-expanded="false"
            aria-controls="api-category"
        >
            Все разделы
        </button>
    </div>

    <?php if (!empty($apiCatalog['references'])): ?>
        <div class="app-api-tabs-references">
            <?php foreach ($apiCatalog['references'] as $ref): ?>
                <a href="<?= htmlspecialchars((string) $ref['href'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) $ref['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
