<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$pageTitle = 'Админка — ' . $branding['app_name'];
$activeNav = 'admin';
$activeZone = 'admin';
require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero">
    <span class="app-kicker">Operations</span>
    <h1 class="app-title h2">Единая админка</h1>
    <p class="app-lead">
        Один вход через RBAC. После авторизации откроются только те разделы, которые разрешены
        ролями и политиками доступа (tenant admin, platform operator).
    </p>
</section>

<section id="adminLoginPanel" class="app-panel app-main-wide mt-4" style="max-width: 36rem;">
    <form id="adminLoginForm" class="app-card app-card-stretch">
        <h2 class="app-card-title">Вход</h2>
        <?php
        $phoneFieldIdPrefix = 'adminLogin';
        $phoneLabel = 'Телефон';
        $phoneRequired = true;
        require __DIR__ . '/partials/phone-field.php';
        ?>
        <div class="mb-3">
            <label class="form-label" for="password">Пароль</label>
            <input class="form-control app-field" id="password" type="password" autocomplete="current-password" required>
        </div>
        <div id="adminLoginMessage" class="app-muted small mb-3" role="status"></div>
        <button type="submit" class="app-button">Войти в админку</button>
        <?php if ($registrationEnabled ?? true): ?>
        <p class="app-muted small mt-3 mb-0">Нет аккаунта? <a href="/register">Зарегистрация новой организации</a>.</p>
        <?php endif; ?>
    </form>
</section>

<section id="adminContextsPanel" class="app-main-wide product-section hidden">
    <span class="app-kicker">Контексты</span>
    <h2 class="app-title h5">Мои контексты</h2>
    <p id="adminContextsHint" class="app-muted small mb-2"></p>
    <div class="app-grid app-grid-equal mb-3" id="adminContextGrid"></div>
    <p class="app-muted small mb-2">
        Полная панель переключения: <a href="/operator">/operator</a>.
        Делегированные клиенты настраиваются через <a href="/admin/platform">platform licensing</a>.
    </p>
    <button type="button" id="adminContextsContinueBtn" class="app-button hidden">Продолжить с текущим контекстом</button>
</section>

<section id="adminModulesPanel" class="app-main-wide product-section hidden">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-3">
        <div>
            <span class="app-kicker">Доступные модули</span>
            <h2 class="app-title h4 mb-0">Выберите раздел</h2>
        </div>
        <button type="button" id="adminLogoutBtn" class="app-button app-button-secondary">Выйти</button>
    </div>
    <div class="app-grid app-grid-equal" id="adminModuleGrid"></div>
</section>

<script>
(function () {
    const STORAGE = {
        access: 'maniforge_admin_access_token',
        refresh: 'maniforge_admin_refresh_token',
        csrf: 'maniforge_admin_csrf_token',
        action: 'maniforge_admin_action_token',
        actionExpires: 'maniforge_admin_action_token_expires',
        tenant: 'maniforge_admin_tenant_id',
        subtenant: 'maniforge_admin_subtenant_id',
        platformToken: 'maniforge_platform_admin_token',
    };

    const loginPanel = document.getElementById('adminLoginPanel');
    const contextsPanel = document.getElementById('adminContextsPanel');
    const contextGrid = document.getElementById('adminContextGrid');
    const contextsHint = document.getElementById('adminContextsHint');
    const modulesPanel = document.getElementById('adminModulesPanel');
    const moduleGrid = document.getElementById('adminModuleGrid');
    const message = document.getElementById('adminLoginMessage');
    const form = document.getElementById('adminLoginForm');
    const contextsContinueBtn = document.getElementById('adminContextsContinueBtn');
    const loginPhoneField = window.ManiforgePhoneField.bind('adminLoginPhonePrefix', 'adminLoginPhoneNumber');

    function headers(includeAuth) {
        const result = {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        };
        if (includeAuth) {
            const token = localStorage.getItem(STORAGE.access);
            if (token) {
                result.Authorization = 'Bearer ' + token;
            }
            const csrf = localStorage.getItem(STORAGE.csrf);
            if (csrf) {
                result['X-CSRF-Token'] = csrf;
            }
            const actionExp = Number(localStorage.getItem(STORAGE.actionExpires) || '0');
            const action = localStorage.getItem(STORAGE.action);
            if (action && actionExp > Date.now()) {
                result['X-Action-Token'] = action;
            }
        }
        return result;
    }

    function renderModules(modules) {
        const cards = [];
        cards.push({
            title: 'Профиль',
            status: 'Account',
            description: 'Email, телефон, смена пароля и данные учётной записи.',
            href: '/profile',
        });
        cards.push({
            title: 'Admin SPA (React)',
            status: 'New',
            description: 'Новая консоль: /app — dashboard, Manifest, switch-context. Общая сессия с PHP.',
            href: '/app',
        });
        if (modules.tenant) {
            cards.push({
                title: 'Tenant admin',
                status: 'RBAC',
                description: 'Пользователи, роли, permissions, сессии и security policies в scope tenant.',
                href: '/admin/tenant',
            });
            cards.push({
                title: 'Проекты',
                status: 'Projects',
                description: 'Проекты tenant/subtenant, глобальные переменные и переключение project scope сессии.',
                href: '/projects',
            });
            cards.push({
                title: 'Versioning',
                status: 'History',
                description: 'История изменений записей: проекты, переменные, пользователи в scope tenant.',
                href: '/versioning/admin',
            });
            cards.push({
                title: 'Manifest UI',
                status: 'Refine',
                description: 'CRUD по manifest records в браузере; экспорт Refine scaffold (make manifest-refine-gen).',
                href: '/refine-manifest',
            });
        }
        if (modules.platform) {
            cards.push({
                title: 'Platform licensing',
                status: 'Licensing',
                description: 'Tenants, subtenants, тарифы, лицензии, entitlements и квоты платформы.',
                href: '/admin/platform',
            });
        }
        if (cards.length === 0) {
            cards.push({
                title: 'Нет доступных модулей',
                status: 'info',
                description: 'У учётной записи нет прав tenant/platform admin. Обратитесь к оператору платформы.',
                href: '#',
                disabled: true,
            });
        }
        moduleGrid.innerHTML = cards.map((card) => `
            <a class="product-link-card app-card-stretch${card.disabled ? ' disabled' : ''}" href="${card.href}"${card.disabled ? ' tabindex="-1" aria-disabled="true"' : ''}>
                <span>${card.status}</span>
                <strong>${card.title}</strong>
                <small class="app-card-body-grow">${card.description}</small>
            </a>
        `).join('');
        modulesPanel.classList.remove('hidden');
        loginPanel.classList.add('hidden');
    }

    async function switchContext(tenantId, subtenantId) {
        const result = await window.ManiforgeSession.switchContext(tenantId, subtenantId);
        if (!result.ok) {
            message.textContent = result.error || 'Не удалось переключить контекст';
            message.className = 'small mb-3 text-danger';
        }
    }

    function bindContextSwitchButtons() {
        contextGrid.querySelectorAll('button[data-tenant]').forEach((btn) => {
            btn.addEventListener('click', () => switchContext(btn.dataset.tenant, btn.dataset.subtenant));
        });
    }

    async function loadContexts() {
        const response = await fetch('/rbac/api/v1/me/contexts', { headers: headers(true) });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            contextsPanel.classList.add('hidden');
            return false;
        }
        const items = [];
        (payload.home || []).forEach((c) => items.push({ ...c, tag: 'home' }));
        (payload.delegated || []).forEach((c) => items.push({ ...c, tag: 'delegated' }));
        const cur = payload.current || {};
        contextsHint.textContent = 'Текущий: ' + cur.tenant_id + ' / ' + cur.subtenant_id
            + (items.length > 1 ? ' — выберите организацию или продолжите к модулям ниже.' : '');
        if (items.length === 0) {
            contextGrid.innerHTML = '<p class="app-muted small mb-0">Только текущий scope входа.</p>';
        } else {
            contextGrid.innerHTML = items.map((c) => {
                const active = c.tenant_id === cur.tenant_id && c.subtenant_id === cur.subtenant_id;
                return `
                <article class="app-card app-card-stretch">
                    <span>${c.tag}${c.grant_level ? ' · ' + c.grant_level : ''}</span>
                    <strong><code>${c.tenant_id}</code> / <code>${c.subtenant_id}</code></strong>
                    <button type="button" class="app-button app-button-secondary mt-2" data-tenant="${c.tenant_id}" data-subtenant="${c.subtenant_id}"${active ? ' disabled' : ''}>
                        ${active ? 'Активен' : 'Переключиться'}
                    </button>
                </article>`;
            }).join('');
            bindContextSwitchButtons();
        }
        contextsPanel.classList.remove('hidden');
        return items.length > 1;
    }

    async function loadModules(skipContextGate) {
        const token = localStorage.getItem(STORAGE.access);
        if (!token) {
            modulesPanel.classList.add('hidden');
            contextsPanel.classList.add('hidden');
            loginPanel.classList.remove('hidden');
            return;
        }
        loginPanel.classList.add('hidden');
        const multipleContexts = await loadContexts();
        if (multipleContexts && !skipContextGate) {
            modulesPanel.classList.add('hidden');
            contextsContinueBtn.classList.remove('hidden');
            if (window.ManiforgeSession) {
                window.ManiforgeSession.initNavSwitcher();
            }
            return;
        }
        contextsContinueBtn.classList.add('hidden');
        const response = await fetch('/rbac/api/v1/me/console-access', { headers: headers(true) });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            clearSession();
            message.textContent = payload.error || 'Сессия истекла, войдите снова.';
            return;
        }
        if (payload.platform_licensing_token) {
            localStorage.setItem(STORAGE.platformToken, payload.platform_licensing_token);
            localStorage.setItem('maniforge_tl_admin_token', payload.platform_licensing_token);
        }
        if (localStorage.getItem(STORAGE.platformToken)) {
            payload.modules = payload.modules || {};
            payload.modules.platform = true;
        }
        renderModules(payload.modules || {});
        if (window.ManiforgeSession) {
            window.ManiforgeSession.initNavSwitcher();
        }
    }

    function clearSession() {
        Object.values(STORAGE).forEach((key) => localStorage.removeItem(key));
        modulesPanel.classList.add('hidden');
        contextsPanel.classList.add('hidden');
        contextsContinueBtn.classList.add('hidden');
        loginPanel.classList.remove('hidden');
        moduleGrid.innerHTML = '';
        contextGrid.innerHTML = '';
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        message.textContent = 'Вход…';
        const phone = loginPhoneField.getFullPhone();
        const password = document.getElementById('password').value;
        if (!phone) {
            message.textContent = 'Укажите телефон';
            message.className = 'small mb-3 text-danger';
            return;
        }
        const response = await fetch('/rbac/api/v1/auth/login', {
            method: 'POST',
            headers: headers(false),
            body: JSON.stringify({ phone, password }),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        const session = payload.session || payload.credentials?.session;
        if (!response.ok || !payload.ok || !session) {
            message.textContent = payload.error || ('HTTP ' + response.status);
            message.className = 'small mb-3 text-danger';
            return;
        }
        localStorage.setItem(STORAGE.access, session.access_token || '');
        localStorage.setItem(STORAGE.refresh, session.refresh_token || '');
        localStorage.setItem(STORAGE.csrf, payload.csrf_token || '');
        const accessRes = await fetch('/rbac/api/v1/me/console-access', { headers: headers(true) });
        const accessPayload = await accessRes.json().catch(() => ({}));
        if (accessPayload.platform_licensing_token) {
            localStorage.setItem(STORAGE.platformToken, accessPayload.platform_licensing_token);
            localStorage.setItem('maniforge_tl_admin_token', accessPayload.platform_licensing_token);
        }
        const scope = session.scope || {};
        localStorage.setItem(STORAGE.tenant, scope.tenant_id || '');
        localStorage.setItem(STORAGE.subtenant, scope.subtenant_id || '');
        message.textContent = '';
        await loadModules();
    });

    document.getElementById('adminLogoutBtn').addEventListener('click', async () => {
        const token = localStorage.getItem(STORAGE.access);
        if (token) {
            await fetch('/rbac/api/v1/auth/logout', {
                method: 'POST',
                headers: headers(true),
                body: '{}',
            }).catch(() => {});
        }
        clearSession();
    });

    document.getElementById('adminContextsContinueBtn').addEventListener('click', () => {
        document.getElementById('adminContextsContinueBtn').classList.add('hidden');
        loadModules(true);
    });

    if (localStorage.getItem(STORAGE.access)) {
        loginPanel.classList.add('hidden');
    }

    loadModules();
})();
</script>
<?php require __DIR__ . '/layout/footer.php';
