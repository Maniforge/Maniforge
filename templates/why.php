<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$content = require __DIR__ . '/data/why-content.php';
$pageTitle = 'Почему Maniforge — ' . $branding['app_name'];
$activeNav = 'why';
$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero landing-sub-hero">
    <span class="app-kicker"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Почему Maniforge</span>
    <h1 class="app-title h2"><?= htmlspecialchars((string) $content['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="app-lead mb-0"><?= htmlspecialchars((string) $content['intro'], ENT_QUOTES, 'UTF-8') ?></p>
    <div class="app-actions mt-4">
        <a class="app-button" href="/modules"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i> Модули</a>
        <a class="app-button app-button-ghost" href="/get-started"><i class="bi bi-signpost-split" aria-hidden="true"></i> Как начать</a>
    </div>
</section>

<section class="app-panel app-main-wide product-section landing-about">
    <span class="app-kicker"><i class="bi bi-info-circle" aria-hidden="true"></i> О платформе</span>
    <p class="app-lead"><?= htmlspecialchars((string) $content['about']['lead'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php foreach ($content['about']['paragraphs'] as $paragraph): ?>
        <p class="app-muted mb-3"><?= htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endforeach; ?>
    <div class="app-grid app-grid-equal mt-2">
        <?php foreach ($content['pillars'] as $value): ?>
            <article class="app-card app-card-stretch landing-value-card">
                <?php $icon = (string) ($value['icon'] ?? ''); require __DIR__ . '/partials/landing-icon.php'; ?>
                <span class="landing-value-label"><?= htmlspecialchars((string) $value['label'], ENT_QUOTES, 'UTF-8') ?></span>
                <p class="app-muted mb-0"><?= htmlspecialchars((string) $value['text'], ENT_QUOTES, 'UTF-8') ?></p>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="app-main-wide product-section">
    <h2 class="app-title h3">Боль → решение</h2>
    <p class="app-lead"><?= htmlspecialchars((string) $content['lead'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php
    $rows = $content['rows'];
    require __DIR__ . '/partials/landing-why-table.php';
    ?>
    <p class="app-muted small mt-3 mb-0"><?= htmlspecialchars((string) $content['note'], ENT_QUOTES, 'UTF-8') ?></p>
</section>

<section class="app-main-wide product-section">
    <div class="landing-section-head">
        <span class="app-kicker"><i class="bi bi-person-workspace" aria-hidden="true"></i> Для кого</span>
        <h2 class="app-title h3">Кому заходит прямо сейчас</h2>
    </div>
    <div class="app-grid app-grid-equal mt-4">
        <?php foreach ($content['personas'] as $persona): ?>
            <a class="product-link-card app-card-stretch landing-persona-card" href="<?= htmlspecialchars((string) $persona['href'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="landing-card-icon-wrap">
                    <?php $icon = (string) ($persona['icon'] ?? ''); require __DIR__ . '/partials/landing-icon.php'; ?>
                </div>
                <span><?= htmlspecialchars((string) $persona['tag'], ENT_QUOTES, 'UTF-8') ?></span>
                <strong><?= htmlspecialchars((string) $persona['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars((string) $persona['text'], ENT_QUOTES, 'UTF-8') ?></small>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="landing-cta-band app-main-wide product-section">
    <div class="landing-cta-inner">
        <h2 class="app-title h3 mb-2">Готовы сравнить с roadmap?</h2>
        <p class="app-lead mb-0">Каталог модулей, стек и тарифы — решите, что подключать первым.</p>
        <div class="app-actions mt-4">
            <a class="app-button" href="/modules"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i> Каталог модулей</a>
            <a class="app-button app-button-secondary" href="/"><i class="bi bi-house" aria-hidden="true"></i> На главную</a>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<?php require __DIR__ . '/layout/footer.php';
