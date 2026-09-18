<?php
declare(strict_types=1);

$branding = require dirname(__DIR__) . '/data/branding.php';
$activeNav = $activeNav ?? '';
$activeZone = $activeZone ?? 'marketing';
$registrationEnabled = $registrationEnabled ?? true;

$navConfigPath = dirname(__DIR__, 2) . '/public/assets/data/site-nav.json';
$navConfig = is_file($navConfigPath)
    ? json_decode((string) file_get_contents($navConfigPath), true)
    : [];
$zones = is_array($navConfig['zones'] ?? null) ? $navConfig['zones'] : [];
$marketingLinks = is_array($navConfig['marketingLinks'] ?? null) ? $navConfig['marketingLinks'] : [];
$brand = is_array($navConfig['brand'] ?? null) ? $navConfig['brand'] : [];
$brandName = (string) ($brand['name'] ?? $branding['app_name'] ?? 'Maniforge');
$brandMark = (string) ($brand['mark'] ?? $branding['app_mark'] ?? 'M');
$brandHref = (string) ($brand['href'] ?? '/');

$isMarketingHeader = $activeZone === 'marketing';
$authEntryHref = $registrationEnabled ? '/register' : '/login';
$authEntryLabel = $registrationEnabled ? 'Вход / Регистрация' : 'Вход';
?>
<header class="mf-site-nav app-navbar<?= $isMarketingHeader ? ' mf-site-nav--marketing' : '' ?>">
    <div class="mf-site-nav-inner">
    <a class="mf-site-nav-brand app-brand" href="<?= htmlspecialchars($brandHref, ENT_QUOTES, 'UTF-8') ?>">
        <span class="mf-site-nav-brand-mark app-brand-mark"><?= htmlspecialchars($brandMark, ENT_QUOTES, 'UTF-8') ?></span>
        <span><?= htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') ?></span>
    </a>

    <?php if ($isMarketingHeader): ?>
    <button
        type="button"
        class="mf-site-nav-toggle"
        aria-expanded="false"
        aria-controls="mfSiteNavMenu"
        aria-label="Открыть меню"
    >
        <span class="mf-site-nav-toggle-bars" aria-hidden="true"></span>
    </button>
    <?php endif; ?>

    <div id="mfSiteNavMenu" class="mf-site-nav-menu">
        <?php if (!$isMarketingHeader): ?>
        <nav class="mf-site-nav-zones" aria-label="Приложения Maniforge">
            <?php foreach ($zones as $zone): ?>
                <?php
                $zoneId = (string) ($zone['id'] ?? '');
                $isActive = $zoneId === $activeZone;
                $icon = (string) ($zone['icon'] ?? '');
                ?>
                <a
                    class="mf-site-nav-zone<?= $isActive ? ' is-active' : '' ?>"
                    href="<?= htmlspecialchars((string) ($zone['href'] ?? '/'), ENT_QUOTES, 'UTF-8') ?>"
                    <?= $isActive ? ' aria-current="page"' : '' ?>
                >
                    <?php if ($icon !== ''): ?>
                        <i class="bi bi-<?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars((string) ($zone['label'] ?? $zoneId), ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <nav class="mf-site-nav-links" aria-label="Маркетинг">
            <?php foreach ($marketingLinks as $link): ?>
                <?php $linkId = (string) ($link['id'] ?? ''); ?>
                <a
                    href="<?= htmlspecialchars((string) ($link['href'] ?? '/'), ENT_QUOTES, 'UTF-8') ?>"
                    <?= $linkId !== '' && $activeNav === $linkId ? ' aria-current="page"' : '' ?>
                ><?= htmlspecialchars((string) ($link['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="mf-site-nav-actions">
            <span id="navGuestLinks" class="maniforge-nav-guest">
                <?php if ($isMarketingHeader): ?>
                <a
                    href="<?= htmlspecialchars($authEntryHref, ENT_QUOTES, 'UTF-8') ?>"
                    class="mf-site-nav-auth-btn<?= in_array($activeNav, ['login', 'register'], true) ? ' is-active' : '' ?>"
                    <?= in_array($activeNav, ['login', 'register'], true) ? ' aria-current="page"' : '' ?>
                ><?= htmlspecialchars($authEntryLabel, ENT_QUOTES, 'UTF-8') ?></a>
                <?php else: ?>
                <span class="maniforge-nav-auth-switch" role="group" aria-label="Вход и регистрация">
                    <a
                        href="/login"
                        class="maniforge-nav-auth-tab<?= $activeNav === 'login' ? ' is-active' : '' ?>"
                        <?= $activeNav === 'login' ? ' aria-current="page"' : '' ?>
                    >Вход</a>
                    <?php if ($registrationEnabled): ?>
                    <a
                        href="/register"
                        class="maniforge-nav-auth-tab<?= $activeNav === 'register' ? ' is-active' : '' ?>"
                        <?= $activeNav === 'register' ? ' aria-current="page"' : '' ?>
                    >Регистрация</a>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </span>

            <span id="navAuthLinks" class="maniforge-nav-auth hidden">
                <div id="navOrgSwitcherWrap" class="maniforge-org-switcher-wrap hidden">
                    <label class="visually-hidden" for="navOrgSwitcher">Организация</label>
                    <select id="navOrgSwitcher" class="form-select form-select-sm app-field maniforge-org-switcher" title="Организация"></select>
                </div>
                <a href="/app"<?= $activeNav === 'app' ? ' aria-current="page"' : '' ?>>Admin</a>
                <a href="/admin"<?= in_array($activeNav, ['admin', 'profile'], true) ? ' aria-current="page"' : '' ?>>Legacy</a>
                <button type="button" id="navLogoutBtn" class="app-nav-logout">Выход</button>
            </span>
        </div>
    </div>
    </div>
</header>
