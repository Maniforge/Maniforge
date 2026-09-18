<?php
declare(strict_types=1);

/** @var array<string, mixed> $module */
$status = (string) ($module['status'] ?? 'scaffold');
$statusLabel = $status === 'active' ? 'API готов' : 'каркас';
$runtime = (string) ($module['runtime'] ?? '');
$runtimeLabel = match ($runtime) {
    'go' => 'Go',
    'php' => 'PHP',
    'dual' => 'Go + PHP',
    default => '',
};
$ui = (string) ($module['ui'] ?? 'none');
$uiLabel = match ($ui) {
    'admin' => 'есть админка',
    'prototype' => 'прототип UI',
    default => 'только API',
};
$healthHref = (string) ($module['health_href'] ?? '#');
$icon = (string) ($module['icon'] ?? 'box');
$port = $module['port'] ?? null;
?>
<article class="app-card app-card-stretch maniforge-module-card landing-module-card">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-2">
        <div class="landing-module-head">
            <?php require __DIR__ . '/landing-icon.php'; ?>
            <h3 class="app-card-title mb-0"><?= htmlspecialchars((string) ($module['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
        </div>
        <div class="landing-module-badges">
            <?php if ($runtimeLabel !== ''): ?>
                <span class="landing-badge landing-badge-runtime"><?= htmlspecialchars($runtimeLabel, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <span class="maniforge-module-status maniforge-module-status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
    </div>
    <p class="app-muted app-card-body-grow mb-3">
        <?= htmlspecialchars((string) ($module['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
    </p>
    <p class="small mb-2 landing-module-meta">
        <code><?= htmlspecialchars((string) ($module['prefix'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
        <?php if ($port !== null): ?>
            <span class="text-muted"> · </span>
            <span class="landing-meta-port">:<?= (int) $port ?></span>
        <?php endif; ?>
        <span class="text-muted"> · </span>
        <span class="landing-meta-ui"><?= htmlspecialchars($uiLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </p>
    <div class="app-actions flex-wrap gap-2">
        <a class="app-button app-button-secondary" href="<?= htmlspecialchars($healthHref, ENT_QUOTES, 'UTF-8') ?>">Health</a>
        <?php if (!empty($module['admin_href'])): ?>
            <a class="app-button app-button-secondary" href="<?= htmlspecialchars((string) $module['admin_href'], ENT_QUOTES, 'UTF-8') ?>">UI</a>
        <?php endif; ?>
        <?php if (!empty($module['api_href'])): ?>
            <a class="app-button app-button-secondary" href="<?= htmlspecialchars((string) $module['api_href'], ENT_QUOTES, 'UTF-8') ?>">Документация</a>
        <?php endif; ?>
        <?php if (!empty($module['openapi_href'])): ?>
            <a class="app-button app-button-secondary" href="<?= htmlspecialchars((string) $module['openapi_href'], ENT_QUOTES, 'UTF-8') ?>">OpenAPI</a>
        <?php endif; ?>
    </div>
</article>
