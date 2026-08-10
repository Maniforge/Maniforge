<?php
declare(strict_types=1);

/** @var array<string, mixed> $module */

$ui = (string) ($module['ui'] ?? 'none');
$uiLabel = match ($ui) {
    'admin' => '✓ Админка',
    'prototype' => '⚡ Прототип UI',
    default => 'API',
};
$icon = (string) ($module['icon'] ?? 'box');
$text = (string) ($module['pitch'] ?? $module['description'] ?? '');
$apiHref = (string) ($module['api_href'] ?? '');
?>
<article class="app-card app-card-stretch landing-module-card landing-module-card-marketing">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-2">
        <div class="landing-module-head">
            <?php require __DIR__ . '/landing-icon.php'; ?>
            <h3 class="app-card-title mb-0"><?= htmlspecialchars((string) ($module['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
        </div>
        <span class="landing-modules-table-ui"><?= htmlspecialchars($uiLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <p class="app-muted app-card-body-grow mb-0"><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($apiHref !== ''): ?>
        <div class="app-actions mt-3">
            <a class="app-button app-button-secondary" href="<?= htmlspecialchars($apiHref, ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-book" aria-hidden="true"></i> API
            </a>
        </div>
    <?php endif; ?>
</article>
