<?php
declare(strict_types=1);

use App\Maniforge\Rbac\Security\RegistrationService;

$branding = require __DIR__ . '/data/branding.php';
$dev = require __DIR__ . '/data/developers-content.php';
$pageTitle = 'Разработчикам — ' . $branding['app_name'];
$activeNav = 'developers';
$registrationEnabled = (new RegistrationService())->isEnabled();
$cta = $dev['cta'];
$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero landing-sub-hero">
    <span class="app-kicker"><i class="bi bi-terminal" aria-hidden="true"></i> Разработчикам</span>
    <h1 class="app-title h2">Документация и интеграция</h1>
    <p class="app-lead mb-0"><?= htmlspecialchars((string) $dev['intro'], ENT_QUOTES, 'UTF-8') ?></p>
    <div class="app-actions mt-4">
        <a class="app-button" href="/api"><i class="bi bi-book" aria-hidden="true"></i> Справочник API</a>
        <?php if ($registrationEnabled): ?>
            <a class="app-button app-button-ghost" href="/register"><i class="bi bi-person-plus" aria-hidden="true"></i> Попробовать Free</a>
        <?php else: ?>
            <a class="app-button app-button-ghost" href="/login"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Вход</a>
        <?php endif; ?>
    </div>
</section>

<section class="app-main-wide product-section">
    <h2 class="app-title h3">Разделы</h2>
    <div class="landing-explore-grid mt-3">
        <?php foreach ($dev['links'] as $item): ?>
            <a class="landing-explore-card" href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>">
                <?php $icon = (string) ($item['icon'] ?? ''); require __DIR__ . '/partials/landing-icon.php'; ?>
                <strong><?= htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="app-muted small"><?= htmlspecialchars((string) $item['description'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="landing-cta-band app-main-wide product-section">
    <div class="landing-cta-inner">
        <h2 class="app-title h3 mb-2"><?= htmlspecialchars((string) $cta['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="app-lead mb-0"><?= htmlspecialchars((string) $cta['lead'], ENT_QUOTES, 'UTF-8') ?></p>
        <div class="app-actions mt-4">
            <?php if ($registrationEnabled): ?>
                <a class="app-button" href="<?= htmlspecialchars((string) $cta['primary_href'], ENT_QUOTES, 'UTF-8') ?>">
                    <i class="bi bi-person-plus" aria-hidden="true"></i>
                    <?= htmlspecialchars((string) $cta['primary_label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php else: ?>
                <a class="app-button" href="/login"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Вход</a>
            <?php endif; ?>
            <a class="app-button app-button-secondary" href="<?= htmlspecialchars((string) $cta['secondary_href'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-tags" aria-hidden="true"></i>
                <?= htmlspecialchars((string) $cta['secondary_label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<?php require __DIR__ . '/layout/footer.php';
