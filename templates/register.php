<?php
declare(strict_types=1);

use App\Maniforge\Rbac\Security\RegistrationService;

$branding = require __DIR__ . '/data/branding.php';
$pageTitle = 'Регистрация — ' . $branding['app_name'];
$activeNav = 'register';
$authMode = 'register';
$registrationEnabled = (new RegistrationService())->isEnabled();
$inviteToken = trim((string) ($_GET['invite'] ?? ''));
$isInvite = $inviteToken !== '';
$minPassword = (int) ($_ENV['RBAC_PASSWORD_MIN_LENGTH'] ?? 12);

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-main-wide">
    <span class="app-kicker">Auth</span>
    <h1 id="authPageTitle" class="app-title h2">Вход и регистрация</h1>
    <p id="authPageLead" class="app-lead">Слева — телефон и пароль. Справа при регистрации — согласия и сведения по 152-ФЗ. Email и название организации заполняются в <a href="/profile">профиле</a> после входа.</p>
</section>

<?php require __DIR__ . '/partials/auth-module.php'; ?>

<script src="/assets/js/auth-module.js"></script>
<?php require __DIR__ . '/layout/footer.php';
