<?php
declare(strict_types=1);

use App\Maniforge\Rbac\Security\RegistrationService;

$branding = require __DIR__ . '/data/branding.php';
$content = require __DIR__ . '/data/modules-content.php';
$maniforgeModules = require __DIR__ . '/data/maniforge-modules.php';
$pageTitle = 'Модули — ' . $branding['app_name'];
$activeNav = 'modules';
$registrationEnabled = (new RegistrationService())->isEnabled();
$extraStylesheets = [
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    '/assets/css/landing.css',
];

$modulesByGroup = [];
foreach ($maniforgeModules['modules'] as $module) {
    $groupId = (string) ($module['group'] ?? 'platform');
    $modulesByGroup[$groupId][] = $module;
}

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero landing-sub-hero">
    <span class="app-kicker"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i> Модули</span>
    <h1 class="app-title h2">Что входит в платформу</h1>
    <p class="app-lead mb-0"><?= htmlspecialchars((string) $content['intro'], ENT_QUOTES, 'UTF-8') ?></p>
    <div class="app-actions mt-4">
        <?php if ($registrationEnabled): ?>
            <a class="app-button" href="/register"><i class="bi bi-person-plus" aria-hidden="true"></i> Попробовать</a>
        <?php else: ?>
            <a class="app-button" href="/login"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Вход</a>
        <?php endif; ?>
        <a class="app-button app-button-ghost" href="/pricing"><i class="bi bi-tags" aria-hidden="true"></i> Тарифы</a>
    </div>
</section>

<section class="app-main-wide product-section">
    <h2 class="app-title h3">Сводка</h2>
    <?php
    $modules = $maniforgeModules['modules'];
    require __DIR__ . '/partials/landing-module-table.php';
    ?>
</section>

<?php foreach ($maniforgeModules['groups'] as $group): ?>
    <?php
    $groupId = (string) ($group['id'] ?? '');
    $groupModules = $modulesByGroup[$groupId] ?? [];
    if ($groupModules === []) {
        continue;
    }
    $groupLead = (string) ($group['lead'] ?? '');
    if ($groupId === 'supply-chain') {
        $groupLead .= ' ' . (string) $content['supply_chain'];
    }
    ?>
    <section class="app-panel app-main-wide mt-4 product-section" id="group-<?= htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') ?>">
        <h2 class="app-title h3">
            <i class="bi bi-<?= $groupId === 'supply-chain' ? 'box-seam' : 'layers' ?>" aria-hidden="true"></i>
            <?= htmlspecialchars((string) ($group['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p class="app-lead mb-0"><?= htmlspecialchars(trim($groupLead), ENT_QUOTES, 'UTF-8') ?></p>
        <div class="app-grid app-grid-equal mt-3">
            <?php foreach ($groupModules as $module): ?>
                <?php require __DIR__ . '/partials/maniforge-module-card-marketing.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<section class="landing-cta-band app-main-wide product-section">
    <div class="landing-cta-inner">
        <h2 class="app-title h3 mb-2">С чего начать</h2>
        <p class="app-lead mb-0">Free-тариф для локальной разработки — все модули в одном tenant.</p>
        <div class="app-actions mt-4">
            <a class="app-button" href="/get-started"><i class="bi bi-signpost-split" aria-hidden="true"></i> Как начать</a>
            <a class="app-button app-button-secondary" href="/why"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Почему Maniforge</a>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<?php require __DIR__ . '/layout/footer.php';
