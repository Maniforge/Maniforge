<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$pageTitle = 'Профиль — ' . $branding['app_name'];
$activeNav = 'profile';
$minPassword = (int) ($_ENV['RBAC_PASSWORD_MIN_LENGTH'] ?? 12);

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-main-wide">
    <span class="app-kicker">Account</span>
    <h1 class="app-title h2">Профиль</h1>
    <p id="profilePageLead" class="app-lead">Email, телефон и название организации — здесь, после входа. Смена пароля — с step-up re-auth. Нет сессии — <a href="/login">войдите</a>.</p>
</section>

<section id="profileLoginPanel" class="app-panel app-main-wide mt-4" style="max-width: 36rem;">
    <form id="profileLoginForm" class="app-card app-card-stretch">
        <h2 class="app-card-title">Вход</h2>
        <?php
        $phoneFieldIdPrefix = 'profileLogin';
        $phoneLabel = 'Телефон';
        $phoneRequired = true;
        require __DIR__ . '/partials/phone-field.php';
        ?>
        <div class="mb-3">
            <label class="form-label" for="password">Пароль</label>
            <input class="form-control app-field" id="password" type="password" autocomplete="current-password" required>
        </div>
        <div id="profileLoginMessage" class="app-muted small mb-3" role="status"></div>
        <button type="submit" class="app-button">Войти</button>
        <?php if ($registrationEnabled ?? true): ?>
        <p class="app-muted small mt-3 mb-0">Нет аккаунта? <a href="/register">Регистрация</a>.</p>
        <?php endif; ?>
    </form>
</section>

<section id="profilePanel" class="app-main-wide product-section hidden">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-3">
        <div>
            <span class="app-kicker">Signed in</span>
            <h2 class="app-title h5 mb-0" id="profileHeading">Профиль</h2>
        </div>
        <button type="button" id="profileLogoutBtn" class="app-button app-button-secondary">Выйти</button>
    </div>

    <article id="profileOrgCard" class="app-card app-card-stretch mb-4 hidden">
        <h3 class="app-card-title">Мои организации</h3>
        <p class="app-muted small mb-3">Тенанты, к которым привязан ваш телефон. Переключение без повторного входа. Новая регистрация на тот же номер недоступна — только приглашение или вход.</p>
        <div id="profileOrgList" class="mb-3"></div>
        <details class="profile-org-quick">
            <summary class="app-muted small">Быстрый выбор из списка</summary>
            <label class="form-label mt-2" for="profileOrgSwitcher">Контекст</label>
            <select id="profileOrgSwitcher" class="form-select app-field"></select>
        </details>
        <p id="profileOrgHint" class="app-muted small mt-2 mb-0"></p>
        <p class="app-muted small mt-3 mb-0">Создать ещё одну организацию с этим телефоном нельзя. Попросите администратора прислать ссылку-приглашение.</p>
    </article>

    <div class="app-grid app-grid-equal">
        <article class="app-card app-card-stretch">
            <h3 class="app-card-title">Данные</h3>
            <dl class="app-muted small mb-3" id="profileMeta"></dl>
            <form id="profileContactForm">
                <div id="profileOrganizationWrap" class="mb-3 hidden">
                    <label class="form-label" for="profileOrganizationName">Название организации</label>
                    <input class="form-control app-field" id="profileOrganizationName" autocomplete="organization" placeholder="ООО Ромашка">
                    <div class="form-text app-muted small">Для администратора tenant — отображается субъектам как оператор ПДн.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="profileEmail">Email</label>
                    <input class="form-control app-field" id="profileEmail" type="email" autocomplete="email" placeholder="you@example.com">
                </div>
                <?php
                $phoneFieldIdPrefix = 'profile';
                $phoneLabel = 'Телефон';
                $phoneRequired = false;
                require __DIR__ . '/partials/phone-field.php';
                ?>
                <div id="profileContactMessage" class="app-muted small mb-3" role="status"></div>
                <button type="submit" class="app-button app-button-secondary">Сохранить</button>
            </form>
        </article>

        <article class="app-card app-card-stretch">
            <h3 class="app-card-title">Персональные данные (152-ФЗ)</h3>
            <p class="app-muted small">Права субъекта: просмотр данных, согласия, запросы оператору.</p>
            <div id="profilePdMessage" class="app-muted small mb-2" role="status"></div>
            <div id="profilePdConsents" class="app-muted small mb-3"></div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" id="profilePdExport" class="app-button app-button-secondary">Скачать мои данные</button>
                <button type="button" id="profilePdRequestAccess" class="app-button app-button-secondary">Запрос доступа</button>
            </div>
        </article>

        <article class="app-card app-card-stretch">
            <h3 class="app-card-title">Безопасность</h3>
            <p class="app-muted small">MFA: <span id="profileMfa">—</span>. Step-up: <span id="profileStepUp">—</span>.</p>
            <form id="profileReauthForm" class="mt-3">
                <div class="mb-3">
                    <label class="form-label" for="reauthPassword">Re-auth (step-up)</label>
                    <input class="form-control app-field" id="reauthPassword" type="password" autocomplete="current-password">
                </div>
                <button type="submit" class="app-button app-button-secondary">Подтвердить пароль</button>
            </form>
            <form id="profilePasswordForm" class="mt-4">
                <div class="mb-3">
                    <label class="form-label" for="currentPassword">Текущий пароль</label>
                    <input class="form-control app-field" id="currentPassword" type="password" autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="newPassword">Новый пароль</label>
                    <input class="form-control app-field" id="newPassword" type="password" autocomplete="new-password">
                    <div class="form-text app-muted small">Минимум <?= (int) $minPassword ?> символов; нужен свежий step-up.</div>
                </div>
                <div id="profilePasswordMessage" class="app-muted small mb-3" role="status"></div>
                <button type="submit" class="app-button">Сменить пароль</button>
            </form>
        </article>
    </div>
</section>

<script>
(function () {
    const SESSION = window.ManiforgeSession;
    const STORAGE = SESSION.STORAGE;

    const loginPanel = document.getElementById('profileLoginPanel');
    const profilePanel = document.getElementById('profilePanel');
    const pageLead = document.getElementById('profilePageLead');
    const loginForm = document.getElementById('profileLoginForm');
    const loginMessage = document.getElementById('profileLoginMessage');
    const orgCard = document.getElementById('profileOrgCard');
    const orgList = document.getElementById('profileOrgList');
    const orgSelect = document.getElementById('profileOrgSwitcher');
    const orgHint = document.getElementById('profileOrgHint');
    const loginPhoneField = window.ManiforgePhoneField.bind('profileLoginPhonePrefix', 'profileLoginPhoneNumber');
    const phoneField = window.ManiforgePhoneField.bind('profilePhonePrefix', 'profilePhoneNumber');

    let contextsPayload = null;

    function headers(includeAuth) {
        return SESSION.headers(includeAuth);
    }

    function showLoginOnly(message) {
        loginPanel.classList.remove('hidden');
        profilePanel.classList.add('hidden');
        orgCard.classList.add('hidden');
        if (pageLead) {
            pageLead.classList.remove('hidden');
        }
        if (message) {
            loginMessage.textContent = message;
            loginMessage.className = 'small mb-3 text-danger';
        }
    }

    function showProfileOnly() {
        loginPanel.classList.add('hidden');
        profilePanel.classList.remove('hidden');
        if (pageLead) {
            pageLead.classList.add('hidden');
        }
        loginMessage.textContent = '';
        loginMessage.className = 'app-muted small mb-3';
    }

    function clearSession() {
        Object.values(STORAGE).forEach((key) => localStorage.removeItem(key));
        contextsPayload = null;
        showLoginOnly();
    }

    async function loadOrgSwitcher() {
        const result = await SESSION.fetchContexts();
        if (!result.ok) {
            orgCard.classList.add('hidden');
            return;
        }
        contextsPayload = result.payload;
        SESSION.renderOrganizationsList(orgList, contextsPayload, {
            reload: false,
            onMessage: async (text, isError) => {
                orgHint.textContent = text;
                orgHint.className = isError
                    ? 'app-muted small mt-2 mb-0 text-danger'
                    : 'app-muted small mt-2 mb-0 text-success';
                if (!isError) {
                    await loadProfile();
                }
            },
        });
        const meta = SESSION.populateSelect(orgSelect, contextsPayload);
        if (orgSelect.dataset.profileBound !== '1') {
            orgSelect.dataset.profileBound = '1';
            orgSelect.addEventListener('change', async () => {
                const parts = String(orgSelect.value || '').split('\0');
                const tenantId = parts[0];
                const subtenantId = parts[1];
                if (!tenantId || !subtenantId) {
                    return;
                }
                const cur = contextsPayload?.current || {};
                if (tenantId === cur.tenant_id && subtenantId === cur.subtenant_id) {
                    return;
                }
                orgSelect.disabled = true;
                const result = await SESSION.switchContext(tenantId, subtenantId, { reload: false });
                orgSelect.disabled = false;
                if (!result.ok) {
                    orgHint.textContent = result.error || 'Ошибка переключения';
                    orgHint.className = 'app-muted small mt-2 mb-0 text-danger';
                    return;
                }
                await loadProfile();
            });
        }
        const cur = contextsPayload.current || {};
        const kind = cur.kind === 'delegated'
            ? ('делегированный' + (cur.grant_level ? ', ' + cur.grant_level : ''))
            : (cur.kind === 'home' ? 'ваша организация' : (cur.kind || 'session'));
        const label = cur.label || (cur.tenant_id + ' / ' + cur.subtenant_id);
        orgHint.textContent = meta.items.length > 1
            ? 'Организаций: ' + meta.items.length + '. Сейчас: ' + label + ' (' + kind + ').'
            : 'Организация: ' + label + '.';
        orgHint.className = 'app-muted small mt-2 mb-0';
        orgCard.classList.remove('hidden');
        if (typeof SESSION.initNavSwitcher === 'function') {
            SESSION.initNavSwitcher();
        }
    }

    async function loadProfile() {
        const token = localStorage.getItem(STORAGE.access);
        if (!token) {
            clearSession();
            return;
        }
        showProfileOnly();
        document.getElementById('profileMeta').innerHTML = '<p class="app-muted small mb-0">Загрузка…</p>';

        const response = await fetch('/rbac/api/v1/me/profile', { headers: headers(true) });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            clearSession();
            showLoginOnly(payload.error || 'Сессия истекла');
            return;
        }
        const user = payload.user || {};
        document.getElementById('profileHeading').textContent = user.phone || 'Профиль';
        document.getElementById('profileEmail').value = user.email || '';
        phoneField.setFromStored(user.phone || '');
        document.getElementById('profileMfa').textContent = user.mfa_required ? 'обязательна' : 'не требуется';
        document.getElementById('profileStepUp').textContent = (payload.session && payload.session.aal) || '—';
        const rolesList = payload.roles || [];
        const roles = rolesList.join(', ') || '—';
        const orgWrap = document.getElementById('profileOrganizationWrap');
        const orgInput = document.getElementById('profileOrganizationName');
        if (orgWrap && orgInput) {
            const isTenantAdmin = rolesList.indexOf('tenant_admin') >= 0;
            orgWrap.classList.toggle('hidden', !isTenantAdmin);
            if (isTenantAdmin) {
                const opRes = await fetch('/rbac/api/v1/admin/personal-data/operator-profile', { headers: headers(true) });
                const opPayload = await opRes.json().catch(() => ({ ok: false }));
                if (opPayload.ok && opPayload.profile) {
                    orgInput.value = opPayload.profile.operator_name || '';
                }
            }
        }
        await loadOrgSwitcher();
        const cur = contextsPayload?.current || {};
        const scopeTenant = cur.tenant_id || localStorage.getItem(STORAGE.tenant) || '';
        const scopeSubtenant = cur.subtenant_id || localStorage.getItem(STORAGE.subtenant) || '';
        const scopeLabel = cur.label
            ? cur.label + ' <span class="app-muted">(<code>' + scopeTenant + '</code> / <code>' + scopeSubtenant + '</code>)</span>'
            : '<code>' + scopeTenant + '</code> / <code>' + scopeSubtenant + '</code>';
        document.getElementById('profileMeta').innerHTML = `
            <dt>Телефон</dt><dd>${user.phone || '—'}</dd>
            <dt>Текущая организация</dt><dd>${scopeLabel}</dd>
            <dt>Статус</dt><dd>${user.status || ''}</dd>
            <dt>Роли</dt><dd>${roles}</dd>
        `;
        await loadPdConsents();
    }

    loginForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        loginMessage.textContent = 'Вход…';
        loginMessage.className = 'app-muted small mb-3';
        const phone = loginPhoneField.getFullPhone();
        const password = document.getElementById('password').value;
        if (!phone) {
            loginMessage.textContent = 'Укажите телефон';
            loginMessage.className = 'small mb-3 text-danger';
            return;
        }
        const response = await fetch('/rbac/api/v1/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone, password }),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        const session = payload.session || payload.credentials?.session;
        if (!response.ok || !payload.ok || !session) {
            loginMessage.textContent = payload.error || ('HTTP ' + response.status);
            loginMessage.className = 'small mb-3 text-danger';
            return;
        }
        localStorage.setItem(STORAGE.access, session.access_token || '');
        localStorage.setItem(STORAGE.refresh, session.refresh_token || '');
        localStorage.setItem(STORAGE.csrf, payload.csrf_token || '');
        const scope = session.scope || {};
        if (scope.tenant_id) {
            localStorage.setItem(STORAGE.tenant, scope.tenant_id);
        }
        if (scope.subtenant_id) {
            localStorage.setItem(STORAGE.subtenant, scope.subtenant_id);
        }
        await loadProfile();
        const ctx = await SESSION.fetchContexts();
        if (ctx.ok && SESSION.contextCount(ctx.payload) > 1) {
            orgHint.textContent = 'Выберите организацию в списке выше или в шапке сайта.';
            orgSelect.focus();
        }
    });

    document.getElementById('profileContactForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const msg = document.getElementById('profileContactMessage');
        msg.textContent = 'Сохранение…';
        const email = document.getElementById('profileEmail').value.trim();
        const patchBody = { phone: phoneField.getFullPhone() };
        if (email !== '') {
            patchBody.email = email;
        }
        const response = await fetch('/rbac/api/v1/me/profile', {
            method: 'PATCH',
            headers: headers(true),
            body: JSON.stringify(patchBody),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!payload.ok) {
            msg.textContent = payload.error || 'Ошибка';
            msg.className = 'small mb-3 text-danger';
            return;
        }
        const orgWrap = document.getElementById('profileOrganizationWrap');
        const orgName = document.getElementById('profileOrganizationName')?.value.trim() || '';
        if (orgWrap && !orgWrap.classList.contains('hidden') && orgName !== '') {
            const opRes = await fetch('/rbac/api/v1/admin/personal-data/operator-profile', {
                method: 'PUT',
                headers: headers(true),
                body: JSON.stringify({ operator_name: orgName }),
            });
            const opPayload = await opRes.json().catch(() => ({ ok: false }));
            if (!opPayload.ok) {
                msg.textContent = 'Контакты сохранены; организация: ' + (opPayload.error || 'нужен step-up в админке');
                msg.className = 'small mb-3 text-warning';
                await loadProfile();
                return;
            }
        }
        msg.textContent = 'Сохранено';
        msg.className = 'small mb-3 text-success';
        await loadProfile();
    });

    document.getElementById('profileReauthForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const response = await fetch('/rbac/api/v1/auth/reauth', {
            method: 'POST',
            headers: headers(true),
            body: JSON.stringify({ password: document.getElementById('reauthPassword').value }),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        const action = payload.credentials?.action || payload.action;
        if (payload.ok && action && action.token) {
            localStorage.setItem(STORAGE.action, action.token);
            localStorage.setItem(STORAGE.actionExpires, String(Date.now() + (action.ttl_sec || 900) * 1000));
            document.getElementById('profilePasswordMessage').textContent = 'Step-up подтверждён';
            document.getElementById('profilePasswordMessage').className = 'small mb-3 text-success';
        } else {
            document.getElementById('profilePasswordMessage').textContent = payload.error || 'Re-auth не удался';
            document.getElementById('profilePasswordMessage').className = 'small mb-3 text-danger';
        }
    });

    document.getElementById('profilePasswordForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const msg = document.getElementById('profilePasswordMessage');
        msg.textContent = 'Смена пароля…';
        const response = await fetch('/rbac/api/v1/me/security/password', {
            method: 'POST',
            headers: headers(true),
            body: JSON.stringify({
                current_password: document.getElementById('currentPassword').value,
                new_password: document.getElementById('newPassword').value,
            }),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (payload.ok) {
            msg.textContent = payload.message || 'Пароль обновлён. Войдите снова.';
            msg.className = 'small mb-3 text-success';
            clearSession();
        } else {
            msg.textContent = payload.error || 'Ошибка';
            msg.className = 'small mb-3 text-danger';
        }
    });

    async function loadPdConsents() {
        const box = document.getElementById('profilePdConsents');
        const msg = document.getElementById('profilePdMessage');
        if (!box) return;
        const response = await fetch('/rbac/api/v1/me/personal-data/consents', { headers: headers(true) });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!payload.ok) {
            box.textContent = payload.error || 'Согласия недоступны';
            return;
        }
        const items = payload.items || [];
        box.innerHTML = items.length === 0
            ? '<p class="mb-0">Нет записей о согласиях.</p>'
            : '<ul class="mb-0">' + items.map((c) => (
                '<li><code>' + (c.purpose_code || '') + '</code> — ' + (c.status || '') + '</li>'
            )).join('') + '</ul>';
        if (msg) msg.textContent = '';
    }

    document.getElementById('profilePdExport')?.addEventListener('click', async () => {
        const msg = document.getElementById('profilePdMessage');
        const response = await fetch('/rbac/api/v1/me/personal-data', { headers: headers(true) });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            if (msg) msg.textContent = payload.error || 'Ошибка экспорта';
            return;
        }
        const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'my-personal-data.json';
        a.click();
        if (msg) msg.textContent = 'Файл сформирован';
    });

    document.getElementById('profilePdRequestAccess')?.addEventListener('click', async () => {
        const msg = document.getElementById('profilePdMessage');
        const response = await fetch('/rbac/api/v1/me/personal-data/subject-requests', {
            method: 'POST',
            headers: headers(true),
            body: JSON.stringify({ request_type: 'access', note: 'Запрос из профиля' }),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (msg) {
            msg.textContent = payload.ok ? 'Запрос отправлен оператору' : (payload.error || 'Ошибка');
            msg.className = payload.ok ? 'small mb-2 text-success' : 'small mb-2 text-danger';
        }
    });

    document.getElementById('profileLogoutBtn').addEventListener('click', async () => {
        const token = localStorage.getItem(STORAGE.access);
        if (token) {
            await fetch('/rbac/api/v1/auth/logout', {
                method: 'POST',
                headers: headers(true),
                body: '{}',
            }).catch(() => {});
        }
        clearSession();
        SESSION.initNavSwitcher();
    });

    if (localStorage.getItem(STORAGE.access)) {
        showProfileOnly();
    } else {
        showLoginOnly();
    }
    loadProfile();
})();
</script>
<?php require __DIR__ . '/layout/footer.php';
