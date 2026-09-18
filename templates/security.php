<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$content = require __DIR__ . '/data/security-content.php';
$pageTitle = 'Безопасность — ' . $branding['app_name'];
$activeNav = 'security';
$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero landing-sub-hero">
    <span class="app-kicker"><i class="bi bi-shield-check" aria-hidden="true"></i> Безопасность</span>
    <h1 class="app-title h2"><?= htmlspecialchars((string) $content['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="app-lead mb-0"><?= htmlspecialchars((string) $content['intro'], ENT_QUOTES, 'UTF-8') ?></p>
</section>

<section class="app-panel app-main-wide product-section">
    <p class="app-lead"><?= htmlspecialchars((string) $content['lead'], ENT_QUOTES, 'UTF-8') ?></p>
    <div class="app-grid app-grid-equal mt-3">
        <?php foreach ($content['items'] as $item): ?>
            <article class="app-card app-card-stretch landing-security-card">
                <?php $icon = (string) ($item['icon'] ?? ''); require __DIR__ . '/partials/landing-icon.php'; ?>
                <h2 class="app-card-title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="app-muted app-card-body-grow mb-2"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!empty($item['href'])): ?>
                    <a href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>" class="small">Подробнее</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="app-main-wide product-section">
    <h2 class="app-title h3">Документация</h2>
    <ul class="landing-doc-links mt-3">
        <?php foreach ($content['docs'] as $doc): ?>
            <li>
                <a href="<?= htmlspecialchars((string) $doc['href'], ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <?= htmlspecialchars((string) $doc['title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="app-actions mt-4">
        <a class="app-button" href="/versioning/admin"><i class="bi bi-clock-history" aria-hidden="true"></i> Журнал версий</a>
        <a class="app-button app-button-secondary" href="/"><i class="bi bi-house" aria-hidden="true"></i> На главную</a>
    </div>
</section>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<?php require __DIR__ . '/layout/footer.php';
