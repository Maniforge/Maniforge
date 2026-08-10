<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$stack = require __DIR__ . '/data/stack-content.php';
$pageTitle = 'Стек — ' . $branding['app_name'];
$activeNav = 'developers';
$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero landing-sub-hero">
    <span class="app-kicker"><i class="bi bi-layers" aria-hidden="true"></i> Стек</span>
    <h1 class="app-title h2">Технологии проекта</h1>
    <p class="app-lead mb-0"><?= htmlspecialchars((string) $stack['intro'], ENT_QUOTES, 'UTF-8') ?></p>
    <div class="app-actions mt-4">
        <a class="app-button" href="/developers"><i class="bi bi-terminal" aria-hidden="true"></i> Разработчикам</a>
        <a class="app-button app-button-ghost" href="/api"><i class="bi bi-book" aria-hidden="true"></i> Каталог API</a>
    </div>
</section>

<section class="app-main-wide product-section">
    <?php foreach ($stack['groups'] as $group): ?>
        <div class="landing-stack-group" id="stack-<?= htmlspecialchars((string) ($group['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <h2 class="landing-stack-group-title h4">
                <i class="bi bi-<?= htmlspecialchars((string) ($group['icon'] ?? 'box'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                <?= htmlspecialchars((string) ($group['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <div class="landing-tech-grid">
                <?php foreach ($group['items'] as $item): ?>
                    <article class="landing-tech-card">
                        <strong><?= htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="app-muted small"><?= htmlspecialchars((string) ($item['detail'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<?php require __DIR__ . '/layout/footer.php';
