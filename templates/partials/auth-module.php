<?php
declare(strict_types=1);

/** @var string $authMode 'login' | 'register' */
/** @var bool $registrationEnabled */
/** @var string $inviteToken */
/** @var int $minPassword */

$authMode = ($authMode ?? 'login') === 'register' ? 'register' : 'login';
$registrationEnabled = $registrationEnabled ?? true;
$inviteToken = trim((string) ($inviteToken ?? ''));
$isInvite = $inviteToken !== '';
$minPassword = (int) ($minPassword ?? ($_ENV['RBAC_PASSWORD_MIN_LENGTH'] ?? 12));
$canRegister = $registrationEnabled;
?>
<section class="app-panel app-main-wide mt-4 auth-module-wrap">
    <?php if (!$registrationEnabled && !$isInvite): ?>
    <div class="app-card app-card-stretch">
        <p class="app-muted mb-0">Самостоятельная регистрация отключена. Подключение к организации — только по ссылке-приглашению.</p>
    </div>
    <?php else: ?>
    <div
        id="authModule"
        class="auth-module app-card app-card-stretch<?= $authMode === 'register' ? ' is-register' : ' is-login' ?>"
        data-mode="<?= htmlspecialchars($authMode, ENT_QUOTES, 'UTF-8') ?>"
        data-invite="<?= htmlspecialchars($inviteToken, ENT_QUOTES, 'UTF-8') ?>"
        data-min-password="<?= (int) $minPassword ?>"
        data-default-tenant="<?= htmlspecialchars(strtolower(trim((string) ($_ENV['DEFAULT_TENANT_ID'] ?? 'default'))), ENT_QUOTES, 'UTF-8') ?>"
        data-default-subtenant="<?= htmlspecialchars(strtolower(trim((string) ($_ENV['DEFAULT_SUBTENANT_ID'] ?? 'main'))), ENT_QUOTES, 'UTF-8') ?>"
    >
        <form id="authForm" class="auth-module-form" novalidate>
            <div class="auth-module-viewport">
                <div class="auth-module-track">
                    <div class="auth-module-core">
                        <?php
                        $phoneFieldIdPrefix = 'auth';
                        $phoneLabel = 'Телефон';
                        $phoneRequired = true;
                        require __DIR__ . '/phone-field.php';
                        ?>
                        <div class="mb-0">
                            <label class="form-label" for="authPassword">Пароль</label>
                            <input class="form-control app-field" id="authPassword" type="password" autocomplete="current-password" required>
                            <div class="form-text app-muted small auth-password-hint">Минимум <?= (int) $minPassword ?> символов при регистрации.</div>
                        </div>
                    </div>
                    <div class="auth-module-divider" aria-hidden="true"></div>
                    <div class="auth-module-extra" aria-hidden="<?= $authMode === 'login' ? 'true' : 'false' ?>">
                        <div id="authPrivacyBlock" class="auth-privacy-block" hidden>
                            <p class="form-label mb-1">Обработка персональных данных (152-ФЗ)</p>
                            <p id="authPrivacyOperator" class="app-muted small mb-2"></p>
                            <div id="authPrivacyPurposes" class="auth-privacy-purposes"></div>
                            <p class="app-muted small mb-2">
                                <a href="/docs/152FZ_COMPLIANCE.md" target="_blank" rel="noopener">152-ФЗ / ПДн</a>
                                <span aria-hidden="true"> · </span>
                                <a href="/docs/maniforge-pd-processor-platform.md" target="_blank" rel="noopener">Обработчик SaaS</a>
                                <span aria-hidden="true"> · </span>
                                <a id="authPrivacyPolicyLink" href="/docs/152FZ_COMPLIANCE.md" target="_blank" rel="noopener">Политика оператора</a>
                                <span id="authProcessorHint"></span>
                            </p>
                            <label class="auth-privacy-dpa small d-flex gap-2 align-items-start">
                                <input type="checkbox" id="authPlatformDpa" value="1">
                                <span id="authPlatformDpaLabel">Принимаю поручение обработки ПДн с платформой Maniforge (обработчик) для работы сервиса по подписке.</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div id="authMessage" class="auth-module-message" role="status"></div>
            <div class="auth-module-actions" role="group" aria-label="Вход и регистрация">
                <button type="button" id="authBtnLogin" class="auth-action-btn<?= $authMode === 'login' ? ' is-active is-grow' : '' ?>">Войти</button>
                <?php if ($canRegister): ?>
                <button type="button" id="authBtnRegister" class="auth-action-btn<?= $authMode === 'register' ? ' is-active is-grow' : '' ?>">Регистрация</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>
</section>
