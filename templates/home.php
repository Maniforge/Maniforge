<?php
declare(strict_types=1);

use App\Maniforge\Rbac\Security\RegistrationService;

$branding = require __DIR__ . '/data/branding.php';
$landing = require __DIR__ . '/data/landing-content.php';
$pageTitle = (string) ($branding['meta_title'] ?? $branding['app_name']);
$metaDescription = (string) ($branding['meta_description'] ?? '');
$activeNav = 'home';
$registrationEnabled = (new RegistrationService())->isEnabled();
$quickLinks = [
    ['label' => 'Модули', 'href' => '/modules', 'kind' => 'ghost', 'icon' => 'grid'],
    ['label' => 'Как начать', 'href' => '/get-started', 'kind' => 'ghost', 'icon' => 'signpost-split'],
];
if ($registrationEnabled) {
    $quickLinks[] = ['label' => 'Регистрация', 'href' => '/register', 'kind' => 'primary', 'icon' => 'person-plus'];
} else {
    $quickLinks[] = ['label' => 'Вход', 'href' => '/login', 'kind' => 'primary', 'icon' => 'box-arrow-in-right'];
}
$quickLinks[] = ['label' => 'Разработчикам', 'href' => '/developers', 'kind' => 'ghost', 'icon' => 'terminal'];

$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

require __DIR__ . '/layout/header.php';
?>
<section class="landing-hero app-page-head app-page-head-dark app-main-wide product-hero">
    <p class="landing-hero-eyebrow"><?= htmlspecialchars((string) $branding['app_eyebrow'], ENT_QUOTES, 'UTF-8') ?></p>
    <h1 class="app-title display-5 landing-hero-title"><?= htmlspecialchars((string) $branding['app_headline'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="landing-hero-tagline"><?= htmlspecialchars((string) $branding['app_tagline'], ENT_QUOTES, 'UTF-8') ?></p>
    <p class="app-lead landing-hero-lead">
        <?= htmlspecialchars((string) $branding['app_lead'], ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div class="app-actions mt-4">
        <?php foreach ($quickLinks as $link): ?>
            <a
                class="app-button <?= $link['kind'] === 'secondary' ? 'app-button-secondary' : ($link['kind'] === 'ghost' ? 'app-button-ghost' : '') ?>"
                href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"
            >
                <?php if (!empty($link['icon'])): ?>
                    <i class="bi bi-<?= htmlspecialchars((string) $link['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                <?php endif; ?>
                <?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="landing-stats app-main-wide" aria-label="Платформа в цифрах">
    <div class="landing-stats-grid">
        <?php foreach ($landing['stats'] as $stat): ?>
            <a class="landing-stat landing-stat-link" href="<?= htmlspecialchars((string) $stat['href'], ENT_QUOTES, 'UTF-8') ?>">
                <?php $icon = (string) ($stat['icon'] ?? ''); require __DIR__ . '/partials/landing-icon.php'; ?>
                <strong><?= htmlspecialchars((string) $stat['value'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="landing-stat-label"><?= htmlspecialchars((string) $stat['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if (!empty($stat['hint'])): ?>
                    <small class="landing-stat-hint"><?= htmlspecialchars((string) $stat['hint'], ENT_QUOTES, 'UTF-8') ?></small>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="app-main-wide product-section">
    <div class="landing-explore-grid">
        <?php foreach ($landing['explore'] as $item): ?>
            <a class="landing-explore-card" href="<?= htmlspecialchars((string) $item['href'], ENT_QUOTES, 'UTF-8') ?>">
                <?php $icon = (string) ($item['icon'] ?? ''); require __DIR__ . '/partials/landing-icon.php'; ?>
                <strong><?= htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="app-muted small"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section id="plans" class="app-main-wide product-section">
    <div class="landing-modules-teaser app-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <span class="app-kicker"><i class="bi bi-tags" aria-hidden="true"></i> Тарифы</span>
                <h2 class="app-title h4 mb-2"><?= htmlspecialchars((string) $landing['pricing_teaser']['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="app-muted mb-0"><?= htmlspecialchars((string) $landing['pricing_teaser']['text'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a class="app-button" href="/pricing"><i class="bi bi-arrow-right" aria-hidden="true"></i> Все тарифы</a>
        </div>
    </div>
</section>

<section class="landing-cta-band app-main-wide product-section">
    <?php $cta = $landing['cta']; ?>
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
                <a class="app-button" href="/login"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Войти</a>
            <?php endif; ?>
            <a class="app-button app-button-secondary" href="<?= htmlspecialchars((string) $cta['secondary_href'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-signpost-split" aria-hidden="true"></i>
                <?= htmlspecialchars((string) $cta['secondary_label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<?php require __DIR__ . '/layout/footer.php';
