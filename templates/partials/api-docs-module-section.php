<?php
declare(strict_types=1);

/** @var array<string, mixed> $module */
/** @var array<string, mixed> $moduleDocs */
/** @var bool $isFirst */

$sectionId = (string) $module['section_id'];
$sectionKey = str_replace('-docs', '', $sectionId);
$badge = (string) $module['badge'];
$badgeClass = match ($badge) {
    'public' => 'app-api-badge-public',
    'private' => 'app-api-badge-private',
    default => 'app-api-badge-module',
};
?>
<section
    id="<?= htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8') ?>"
    class="app-panel app-api-module-body"
>
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div>
            <span class="app-api-badge <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars((string) $module['badge_label'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            <h2 class="app-title h3 mt-2"><?= htmlspecialchars((string) $moduleDocs['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="app-lead mb-0"><?= htmlspecialchars((string) $moduleDocs['description'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <?php if (!empty($module['actions'])): ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($module['actions'] as $action): ?>
                    <?php $actionTab = (string) ($action['tab'] ?? ''); ?>
                    <a
                        class="app-button app-button-secondary"
                        href="<?= htmlspecialchars((string) $action['href'], ENT_QUOTES, 'UTF-8') ?>"
                        <?= $actionTab !== '' ? ' data-api-tab-link="' . htmlspecialchars($actionTab, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                    >
                        <?= htmlspecialchars((string) $action['label'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    $common = $moduleDocs['common'];
    $sectionId = $sectionKey;
    $apiHeadersPrefix = match ((string) $module['key']) {
        'public' => 'rbac',
        'private' => 'platform',
        default => 'modules',
    };
    require __DIR__ . '/api-docs-common.php';
    $apiSectionId = $sectionKey;
    $apiCommonErrors = $common['errors'] ?? [];
    ?>

    <?php foreach ($moduleDocs['groups'] as $group): ?>
        <?php if (!empty($group['is_live_panel'])): ?>
            <?php require __DIR__ . '/api-docs-manifest-live.php'; ?>
            <?php continue; ?>
        <?php endif; ?>
        <?php if (!empty($group['is_fields_panel'])): ?>
            <?php require __DIR__ . '/api-docs-manifest-fields.php'; ?>
            <?php continue; ?>
        <?php endif; ?>
        <section id="<?= htmlspecialchars((string) $group['id'], ENT_QUOTES, 'UTF-8') ?>" class="app-api-group<?= !empty($group['generated']) ? ' app-api-group-generated' : '' ?>" data-api-spy-section>
            <h3 class="app-title h4">
                <?= htmlspecialchars((string) $group['title'], ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($group['generated'])): ?>
                    <span class="app-api-badge app-api-badge-module ms-2">OpenAPI</span>
                <?php endif; ?>
            </h3>

            <?php if (!empty($group['generated'])): ?>
                <p class="app-muted small">
                    <code><?= htmlspecialchars((string) ($group['manifest_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                    <?php if (!empty($group['manifest_type'])): ?>
                        · тип <?= htmlspecialchars(api_doc_manifest_type_label((string) $group['manifest_type']), ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                    — OpenAPI из Manifest Engine
                </p>
            <?php endif; ?>
            <?php foreach ($group['endpoints'] ?? [] as $endpoint): ?>
                <?php require __DIR__ . '/api-endpoint-doc.php'; ?>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</section>
