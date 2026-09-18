<?php
declare(strict_types=1);

use App\Maniforge\Rbac\Security\RegistrationService;

$branding = require __DIR__ . '/data/branding.php';
$content = require __DIR__ . '/data/get-started-content.php';
$pageTitle = 'Как начать — ' . $branding['app_name'];
$activeNav = 'get-started';
$registrationEnabled = (new RegistrationService())->isEnabled();
$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero landing-sub-hero">
    <span class="app-kicker"><i class="bi bi-signpost-split" aria-hidden="true"></i> Как начать</span>
    <h1 class="app-title h2"><?= htmlspecialchars((string) $content['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="app-lead mb-0"><?= htmlspecialchars((string) $content['intro'], ENT_QUOTES, 'UTF-8') ?></p>
    <div class="app-actions mt-4">
        <?php if ($registrationEnabled): ?>
            <a class="app-button" href="/register"><i class="bi bi-person-plus" aria-hidden="true"></i> Регистрация</a>
        <?php else: ?>
            <a class="app-button" href="/login"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Вход</a>
        <?php endif; ?>
        <a class="app-button app-button-ghost" href="/pricing"><i class="bi bi-tags" aria-hidden="true"></i> Тарифы</a>
    </div>
</section>

<section class="app-panel app-main-wide product-section">
    <div class="landing-steps">
        <?php foreach ($content['steps'] as $step): ?>
            <article class="landing-step">
                <div class="landing-step-badge">
                    <?php $icon = (string) ($step['icon'] ?? ''); require __DIR__ . '/partials/landing-icon.php'; ?>
                    <span class="landing-step-num"><?= htmlspecialchars((string) $step['step'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div>
                    <h2 class="h5 mb-2"><?= htmlspecialchars((string) $step['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="app-muted mb-0"><?= htmlspecialchars((string) $step['text'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="app-muted small mt-4 mb-0">
        <?= htmlspecialchars((string) $content['note'], ENT_QUOTES, 'UTF-8') ?>
        <a href="/developers">Разработчикам</a>.
    </p>
</section>

<section class="app-main-wide product-section">
    <h2 class="app-title h3">Дальше</h2>
    <div class="app-grid app-grid-equal mt-3">
        <?php foreach ($content['next'] as $item): ?>
            <a class="product-link-card app-card-stretch landing-persona-card" href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="landing-card-icon-wrap">
                    <?php $icon = (string) ($item['icon'] ?? ''); require __DIR__ . '/partials/landing-icon.php'; ?>
                </div>
                <strong><?= htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars((string) $item['text'], ENT_QUOTES, 'UTF-8') ?></small>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="landing-cta-band app-main-wide product-section">
    <div class="landing-cta-inner">
        <h2 class="app-title h3 mb-2">Готовы попробовать?</h2>
        <p class="app-lead mb-0">Тариф Free — 0 ₽, одна организация, все модули для старта.</p>
        <div class="app-actions mt-4">
            <?php if ($registrationEnabled): ?>
                <a class="app-button" href="/register"><i class="bi bi-person-plus" aria-hidden="true"></i> Попробовать</a>
            <?php else: ?>
                <a class="app-button" href="/login"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Войти</a>
            <?php endif; ?>
            <a class="app-button app-button-secondary" href="/"><i class="bi bi-house" aria-hidden="true"></i> На главную</a>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<?php require __DIR__ . '/layout/footer.php';
