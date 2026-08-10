<?php
declare(strict_types=1);

use App\Maniforge\Rbac\Security\RegistrationService;

$branding = require dirname(__DIR__) . '/data/branding.php';
$activeNav = $activeNav ?? '';
$metaDescription = $metaDescription ?? '';
$registrationEnabled = (new RegistrationService())->isEnabled();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? (string) $branding['app_title_short'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
    <?php if (!empty($metaDescription)): ?>
    <meta name="description" content="<?= htmlspecialchars((string) $metaDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
    <link href="/assets/css/site-nav.css" rel="stylesheet">
    <?php foreach ($extraStylesheets ?? [] as $stylesheetHref): ?>
        <link href="<?= htmlspecialchars((string) $stylesheetHref, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" rel="stylesheet">
    <?php endforeach; ?>
    <script>
    (function () {
        try {
            if (localStorage.getItem('maniforge_admin_access_token')) {
                document.documentElement.classList.add('maniforge-has-session');
            }
            <?php if ($activeNav === 'api'): ?>
            var heroPref = localStorage.getItem('api-docs-hero-collapsed');
            var visited = localStorage.getItem('api-docs-visited');
            if (heroPref === '1' || (heroPref !== '0' && visited === '1')) {
                document.documentElement.classList.add('api-docs-compact');
            }
            <?php endif; ?>
        } catch (e) {}
    })();
    </script>
    <script src="/assets/js/maniforge-session.js"></script>
</head>
<body class="app-site">
<?php if ($activeNav === 'api'): ?>
<script>
(function () {
    try {
        if (document.documentElement.classList.contains('api-docs-compact')) {
            document.body.classList.add('api-docs-compact');
        }
    } catch (e) {}
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/site-nav.php'; ?>
<script src="/assets/js/site-nav.js" defer></script>
<main class="app-main app-main-wide">
