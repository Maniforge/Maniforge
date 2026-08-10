<?php
declare(strict_types=1);

use App\Maniforge\Rbac\Security\RegistrationService;

$branding = require __DIR__ . '/data/branding.php';
$pageTitle = 'Вход — ' . $branding['app_name'];
$activeNav = 'login';
$authMode = 'login';
$registrationEnabled = (new RegistrationService())->isEnabled();
$inviteToken = '';
$minPassword = (int) ($_ENV['RBAC_PASSWORD_MIN_LENGTH'] ?? 12);

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-main-wide">
    <span class="app-kicker">Auth</span>
    <h1 id="authPageTitle" class="app-title h2">Вход и регистрация</h1>
    <p id="authPageLead" class="app-lead">Телефон и пароль. При регистрации справа — блок 152-ФЗ и согласия.</p>
</section>

<?php require __DIR__ . '/partials/auth-module.php'; ?>

<script src="/assets/js/auth-module.js"></script>
<?php require __DIR__ . '/layout/footer.php';
