<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$pageTitle = 'Панель оператора — ' . $branding['app_name'];
$activeNav = 'admin';
require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero">
    <span class="app-kicker">Контексты и делегирование</span>
    <h1 class="app-title h2">Панель оператора</h1>
    <p class="app-lead">
        Сотрудник организации-оператора (principal tenant) входит в home, затем переключается на
        <strong>managed tenant</strong> (клиент) через grant — это отдельная организация, не «субтенант».
        Внутри клиента выбирается workspace (<code>subtenant_id</code>, обычно <code>main</code>) и проект.
        <a href="/docs/maniforge-glossary.md">Глоссарий терминов</a>.
    </p>
    <div class="app-actions mt-3">
        <a class="app-button app-button-secondary" href="/admin">Единая админка</a>
        <a class="app-button app-button-secondary hidden" id="agencyPlatformLink" href="/admin/platform">Platform licensing</a>
        <a class="app-button app-button-secondary" href="/api#rbac-me-contexts">API /me/contexts</a>
    </div>
</section>

<section id="agencyLoginPanel" class="app-panel app-main-wide mt-4" style="max-width: 36rem;">
    <form id="agencyLoginForm" class="app-card app-card-stretch">
        <h2 class="app-card-title">Вход</h2>
        <?php
        $phoneFieldIdPrefix = 'agencyLogin';
        $phoneLabel = 'Телефон';
        $phoneRequired = true;
        require __DIR__ . '/partials/phone-field.php';
        ?>
        <div class="mb-3">
            <label class="form-label" for="password">Пароль</label>
            <input class="form-control app-field" id="password" type="password" autocomplete="current-password" required>
        </div>
        <div id="agencyLoginMessage" class="app-muted small mb-3" role="status"></div>
        <button type="submit" class="app-button">Войти</button>
    </form>
</section>

<section id="agencyWorkspacePanel" class="app-main-wide product-section hidden">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-3">
        <div>
            <span class="app-kicker">Контексты</span>
            <h2 class="app-title h4 mb-0">Мои контексты</h2>
        </div>
        <button type="button" id="agencyLogoutBtn" class="app-button app-button-secondary">Выйти</button>
    </div>
    <p id="agencyCurrentContext" class="app-muted small"></p>
    <p class="app-muted small">
        Подробнее о principal / managed / grant_level:
        <a href="/docs/maniforge-tenant-delegation.md">документация по делегированию tenant</a>.
    </p>

    <h3 class="app-title h5 mt-4">Домашние контексты (home)</h3>
    <div class="app-grid app-grid-equal" id="agencyHomeGrid"></div>

    <h3 class="app-title h5 mt-4">Делегированные (managed по grant)</h3>
    <div class="app-grid app-grid-equal" id="agencyDelegatedGrid"></div>

    <section class="app-panel mt-4">
        <span class="app-kicker">Platform</span>
        <h3 class="app-title h5">Управление grant (principal → managed)</h3>
        <p class="app-muted small">
            Создание и отзыв делегирования tenant → tenant — через Tenant Licensing API с токеном платформы
            (<code>TENANT_LICENSING_ADMIN_TOKEN</code>). См. <a href="/api#tl-managed-list">приватные API</a>.
            Лимит <code>max_tenants</code> на плане principal проверяется при создании grant.
        </p>
        <p class="app-muted small mb-0">
            Демо (пример кодов): <code>agency-demo</code> → <code>client-demo</code> после
            <code>php maniforge/rbac/tools/demo_seed.php</code>.
        </p>
    </section>
</section>

<script>
(function () {
    const STORAGE = {
        access: 'maniforge_agency_access_token',
        refresh: 'maniforge_agency_refresh_token',
        csrf: 'maniforge_agency_csrf_token',
        tenant: 'maniforge_agency_tenant_id',
        subtenant: 'maniforge_agency_subtenant_id',
    };

    const GRANT_LABELS = {
        operator: 'Оператор',
        admin: 'Админ',
        read_only: 'Только просмотр',
    };

    const loginPanel = document.getElementById('agencyLoginPanel');
    const workspacePanel = document.getElementById('agencyWorkspacePanel');
    const homeGrid = document.getElementById('agencyHomeGrid');
    const delegatedGrid = document.getElementById('agencyDelegatedGrid');
    const currentContext = document.getElementById('agencyCurrentContext');
    const message = document.getElementById('agencyLoginMessage');
    const form = document.getElementById('agencyLoginForm');
    const platformLink = document.getElementById('agencyPlatformLink');
    const loginPhoneField = window.ManiforgePhoneField.bind('agencyLoginPhonePrefix', 'agencyLoginPhoneNumber');

    function headers(includeAuth) {
        const result = {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        };
        const tenant = localStorage.getItem(STORAGE.tenant);
        const subtenant = localStorage.getItem(STORAGE.subtenant);
        if (tenant) result['X-Tenant-ID'] = tenant;
        if (subtenant) result['X-Subtenant-ID'] = subtenant;
        if (includeAuth) {
            const token = localStorage.getItem(STORAGE.access);
            if (token) {
                result.Authorization = 'Bearer ' + token;
            }
        }
        return result;
    }

    function grantBadge(level) {
        const label = GRANT_LABELS[level] || level || '—';
        return '<span class="badge text-bg-secondary">' + label + '</span>';
    }

    function contextCard(ctx, current, options) {
        const active = ctx.tenant_id === current.tenant_id && ctx.subtenant_id === current.subtenant_id;
        const badge = options.showGrant ? grantBadge(ctx.grant_level) : '';
        return `
            <article class="app-card app-card-stretch">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <span>${options.label}</span>
                    ${badge}
                </div>
                <strong><code>${ctx.tenant_id}</code> / <code>${ctx.subtenant_id}</code></strong>
                ${options.showPrincipal && ctx.principal_tenant_id ? '<p class="app-muted small mb-0">Principal: <code>' + ctx.principal_tenant_id + '</code></p>' : ''}
                <button type="button" class="app-button mt-2" data-tenant="${ctx.tenant_id}" data-subtenant="${ctx.subtenant_id}"${active ? ' disabled' : ''}>
                    ${active ? 'Активен' : 'Переключиться'}
                </button>
            </article>`;
    }

    function bindSwitchButtons(container) {
        container.querySelectorAll('button[data-tenant]').forEach((btn) => {
            btn.addEventListener('click', () => switchContext(btn.dataset.tenant, btn.dataset.subtenant));
        });
    }

    function renderContexts(payload) {
        const current = payload.current || {};
        const kindLabel = current.kind === 'delegated'
            ? ('делегированный, grant: ' + (current.grant_level || '—'))
            : (current.kind || 'session');
        currentContext.textContent = 'Текущий контекст: ' + current.tenant_id + ' / ' + current.subtenant_id
            + ' (' + kindLabel + ')';

        const homeItems = payload.home || [];
        const delegatedItems = payload.delegated || [];

        if (homeItems.length === 0) {
            homeGrid.innerHTML = '<p class="app-muted">Нет home-контекстов.</p>';
        } else {
            homeGrid.innerHTML = homeItems.map((ctx) => contextCard(ctx, current, { label: 'Home', showGrant: false })).join('');
            bindSwitchButtons(homeGrid);
        }

        if (delegatedItems.length === 0) {
            delegatedGrid.innerHTML = '<p class="app-muted">Нет делегированных контекстов (нужен активный grant principal → managed).</p>';
        } else {
            delegatedGrid.innerHTML = delegatedItems.map((ctx) => contextCard(ctx, current, {
                label: 'Delegated',
                showGrant: true,
                showPrincipal: true,
            })).join('');
            bindSwitchButtons(delegatedGrid);
        }
    }

    async function refreshConsoleAccess() {
        const response = await fetch('/rbac/api/v1/me/console-access', { headers: headers(true) });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (response.ok && payload.ok && payload.modules && payload.modules.platform) {
            platformLink.classList.remove('hidden');
        } else {
            platformLink.classList.add('hidden');
        }
    }

    async function switchContext(tenantId, subtenantId) {
        const response = await fetch('/rbac/api/v1/auth/switch-context', {
            method: 'POST',
            headers: {
                ...headers(true),
                'X-CSRF-Token': localStorage.getItem(STORAGE.csrf) || '',
            },
            body: JSON.stringify({ tenant_id: tenantId, subtenant_id: subtenantId }),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            alert(payload.error || ('HTTP ' + response.status));
            return;
        }
        localStorage.setItem(STORAGE.tenant, tenantId);
        localStorage.setItem(STORAGE.subtenant, subtenantId);
        location.reload();
    }

    async function loadContexts() {
        const token = localStorage.getItem(STORAGE.access);
        if (!token) {
            workspacePanel.classList.add('hidden');
            loginPanel.classList.remove('hidden');
            return;
        }
        const response = await fetch('/rbac/api/v1/me/contexts', { headers: headers(true) });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            clearSession();
            message.textContent = payload.error || 'Сессия истекла.';
            return;
        }
        renderContexts(payload);
        await refreshConsoleAccess();
        workspacePanel.classList.remove('hidden');
        loginPanel.classList.add('hidden');
    }

    function clearSession() {
        Object.values(STORAGE).forEach((key) => localStorage.removeItem(key));
        workspacePanel.classList.add('hidden');
        loginPanel.classList.remove('hidden');
        homeGrid.innerHTML = '';
        delegatedGrid.innerHTML = '';
        platformLink.classList.add('hidden');
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
            headers: { 'Content-Type': 'application/json' },
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
        const scope = session.scope || {};
        localStorage.setItem(STORAGE.tenant, scope.tenant_id || '');
        localStorage.setItem(STORAGE.subtenant, scope.subtenant_id || '');
        message.textContent = '';
        await loadContexts();
    });

    document.getElementById('agencyLogoutBtn').addEventListener('click', async () => {
        const token = localStorage.getItem(STORAGE.access);
        if (token) {
            await fetch('/rbac/api/v1/auth/logout', {
                method: 'POST',
                headers: {
                    ...headers(true),
                    'X-CSRF-Token': localStorage.getItem(STORAGE.csrf) || '',
                },
                body: '{}',
            }).catch(() => {});
        }
        clearSession();
    });

    loadContexts();
})();
</script>
<?php require __DIR__ . '/layout/footer.php';
