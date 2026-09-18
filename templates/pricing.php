<?php
declare(strict_types=1);

use App\Maniforge\Rbac\Security\RegistrationService;

$branding = require __DIR__ . '/data/branding.php';
$pricing = require __DIR__ . '/data/pricing-content.php';
$pageTitle = 'Тарифы — ' . $branding['app_name'];
$activeNav = 'pricing';
$registrationEnabled = (new RegistrationService())->isEnabled();
$plans = require __DIR__ . '/data/product-plans.php';
$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero landing-sub-hero">
    <span class="app-kicker"><i class="bi bi-tags" aria-hidden="true"></i> Тарифы</span>
    <h1 class="app-title h2">Планы для любого этапа</h1>
    <p class="app-lead mb-0">
        <?= htmlspecialchars((string) $pricing['intro'], ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div class="app-actions mt-4">
        <?php if ($registrationEnabled): ?>
            <a class="app-button" href="/register"><i class="bi bi-person-plus" aria-hidden="true"></i> Попробовать Free</a>
        <?php else: ?>
            <a class="app-button" href="/login"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Вход</a>
        <?php endif; ?>
        <a class="app-button app-button-ghost" href="/modules"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i> Модули</a>
    </div>
</section>

<section class="app-main-wide product-section">
    <div class="app-plan-grid mt-2">
        <?php foreach ($plans as $plan): ?>
            <?php require __DIR__ . '/partials/plan-card-marketing.php'; ?>
        <?php endforeach; ?>
    </div>
</section>

<section class="app-panel app-main-wide product-section">
    <span class="app-kicker"><i class="bi bi-question-circle" aria-hidden="true"></i> FAQ</span>
    <h2 class="app-title h3">Частые вопросы</h2>
    <div class="landing-faq mt-3">
        <?php foreach ($pricing['faq'] as $item): ?>
            <details class="landing-faq-item">
                <summary><?= htmlspecialchars((string) $item['q'], ENT_QUOTES, 'UTF-8') ?></summary>
                <p class="app-muted mb-2"><?= htmlspecialchars((string) $item['a'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!empty($item['href'])): ?>
                    <a href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>" class="small">Подробнее</a>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    </div>
</section>

<section class="landing-cta-band app-main-wide product-section">
    <div class="landing-cta-inner">
        <h2 class="app-title h3 mb-2">Начните с Free</h2>
        <p class="app-lead mb-0">Зарегистрируйтесь и подключите платформу локально — без скрытых лимитов на этапе разработки.</p>
        <div class="app-actions mt-4">
            <?php if ($registrationEnabled): ?>
                <a class="app-button" href="/register"><i class="bi bi-person-plus" aria-hidden="true"></i> Регистрация</a>
            <?php else: ?>
                <a class="app-button" href="/login"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Вход</a>
            <?php endif; ?>
            <a class="app-button app-button-secondary" href="/get-started">
                <i class="bi bi-signpost-split" aria-hidden="true"></i> Как начать
            </a>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<?php require __DIR__ . '/layout/footer.php';
