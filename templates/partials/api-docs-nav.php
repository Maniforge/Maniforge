<?php
declare(strict_types=1);

/** @var array<string, mixed> $navModule */
/** @var array<string, mixed> $navModuleDocs */
?>
<nav class="app-api-nav" aria-label="Разделы <?= htmlspecialchars((string) $navModule['nav_label'], ENT_QUOTES, 'UTF-8') ?>">
    <p class="app-api-nav-purpose"><?= htmlspecialchars((string) $navModule['nav_purpose'], ENT_QUOTES, 'UTF-8') ?></p>

    <?php if (!empty($navModule['is_reference'])): ?>
        <?php if ((string) ($navModule['key'] ?? '') === 'credentials'): ?>
            <a href="#api-credentials-overview" class="app-api-nav-group app-api-nav-group-main" data-api-nav-anchor="api-credentials-overview">С чего начать</a>
            <?php foreach ($navModuleDocs['sections'] as $credSection): ?>
                <a
                    href="#<?= htmlspecialchars((string) $credSection['id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="app-api-nav-group"
                    data-api-nav-anchor="<?= htmlspecialchars((string) $credSection['id'], ENT_QUOTES, 'UTF-8') ?>"
                ><?= htmlspecialchars((string) $credSection['title'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        <?php else: ?>
            <a href="#api-headers-kit" class="app-api-nav-group app-api-nav-group-main" data-api-nav-anchor="api-headers-kit">Заготовки</a>
            <a href="#api-headers-overview" class="app-api-nav-group" data-api-nav-anchor="api-headers-overview">Справочник заголовков</a>
        <?php endif; ?>
    <?php else: ?>
        <?php
        $sectionId = (string) $navModule['section_id'];
        $commonId = str_replace('-docs', '', $sectionId) . '-common';
        ?>
        <a href="#<?= htmlspecialchars($commonId, ENT_QUOTES, 'UTF-8') ?>" class="app-api-nav-group app-api-nav-group-main" data-api-nav-anchor="<?= htmlspecialchars($commonId, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars((string) $navModule['common_nav_label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="#api-credentials-overview" class="app-api-nav-group" data-api-tab-link="api-credentials-docs" data-api-nav-anchor="api-credentials-overview">Ключи</a>
        <a href="#api-headers-kit" class="app-api-nav-group" data-api-tab-link="api-headers-docs" data-api-nav-anchor="api-headers-kit">Заготовки</a>
        <?php foreach ($navModuleDocs['groups'] as $group): ?>
            <a href="#<?= htmlspecialchars((string) $group['id'], ENT_QUOTES, 'UTF-8') ?>" class="app-api-nav-group" data-api-nav-anchor="<?= htmlspecialchars((string) $group['id'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars((string) $group['title'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</nav>
