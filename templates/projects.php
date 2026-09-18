<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$pageTitle = 'Проекты — ' . $branding['app_name'];
$activeNav = 'admin';

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-main-wide">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <div>
            <span class="app-kicker">Projects</span>
            <h1 class="app-title h4 mb-0">Проекты организации</h1>
        </div>
        <a class="app-button app-button-secondary" href="/admin">← К админке</a>
    </div>
    <p class="app-muted small mt-2 mb-0">
        Проекты уровня <strong>workspace</strong> (<code>subtenant</code>) или всей организации (<code>tenant_level</code>).
        Клиент-оператор — отдельный <strong>managed tenant</strong>, не subtenant: <a href="/docs/maniforge-glossary.md">глоссарий</a>.
        Переключение: <code>POST /rbac/api/v1/auth/switch-project</code>.
    </p>
</section>

<section id="projectsLoginPanel" class="app-panel app-main-wide mt-4" style="max-width: 40rem;">
    <article class="app-card app-card-stretch">
        <h2 class="app-card-title">Вход (RBAC)</h2>
        <p class="app-muted small">Используйте те же учётные данные, что и для <a href="/admin">/admin</a>.</p>
        <?php
        $phoneFieldIdPrefix = 'projectsLogin';
        $phoneLabel = 'Телефон';
        $phoneRequired = true;
        require __DIR__ . '/partials/phone-field.php';
        ?>
        <div class="mb-3">
            <label class="form-label" for="password">Пароль</label>
            <input class="form-control app-field" id="password" type="password" autocomplete="current-password" required>
        </div>
        <div id="loginMessage" class="app-muted small mb-3"></div>
        <button type="button" class="app-button" id="loginBtn">Войти</button>
    </article>
</section>

<section id="projectsPanel" class="app-main-wide product-section hidden mt-4">
    <div class="app-grid app-grid-equal mb-4">
        <article class="app-card app-card-stretch">
            <h2 class="app-card-title">Создать проект</h2>
            <div class="mb-2">
                <label class="form-label" for="projCode">Code</label>
                <input class="form-control app-field" id="projCode" placeholder="calc-2026">
            </div>
            <div class="mb-2">
                <label class="form-label" for="projName">Название</label>
                <input class="form-control app-field" id="projName" placeholder="Расчёт Q2">
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="projTenantLevel">
                <label class="form-check-label" for="projTenantLevel">Уровень tenant (без subtenant)</label>
            </div>
            <button type="button" class="app-button" id="createProjBtn">Создать</button>
        </article>
        <article class="app-card app-card-stretch">
            <h2 class="app-card-title">Переменная scope</h2>
            <div class="mb-2">
                <label class="form-label" for="varKey">Key</label>
                <input class="form-control app-field" id="varKey" placeholder="default_currency">
            </div>
            <div class="mb-2">
                <label class="form-label" for="varValue">Value</label>
                <input class="form-control app-field" id="varValue" placeholder="RUB">
            </div>
            <div class="mb-2">
                <label class="form-label" for="varScope">Scope</label>
                <select class="form-select app-field" id="varScope">
                    <option value="tenant">tenant (все subtenant и проекты)</option>
                    <option value="subtenant">subtenant</option>
                </select>
            </div>
            <button type="button" class="app-button app-button-secondary" id="createVarBtn">Сохранить</button>
        </article>
    </div>

    <h2 class="app-title h5">Список проектов</h2>
    <div id="projectList" class="app-grid app-grid-equal mb-4"></div>

    <h2 class="app-title h5">Глобальные переменные (видимые в сессии)</h2>
    <pre id="varList" class="app-card app-card-stretch small mb-4"></pre>

    <h2 class="app-title h5">Текущий проект сессии</h2>
    <p id="currentProject" class="app-muted small"></p>
    <button type="button" class="app-button app-button-secondary" id="clearProjectBtn">Без проекта</button>
</section>

<script>
(function () {
    const STORAGE = {
        access: 'maniforge_admin_access_token',
        csrf: 'maniforge_admin_csrf_token',
        action: 'maniforge_admin_action_token',
        actionExpires: 'maniforge_admin_action_token_expires',
    };
    const API = '/rbac/api/v1';
    const panel = document.getElementById('projectsPanel');
    const loginPanel = document.getElementById('projectsLoginPanel');
    const loginMessage = document.getElementById('loginMessage');
    const loginPhoneField = window.ManiforgePhoneField.bind('projectsLoginPhonePrefix', 'projectsLoginPhoneNumber');

    function showProjectsWorkspace() {
        loginPanel.classList.add('hidden');
        panel.classList.remove('hidden');
        if (window.ManiforgeSession) {
            window.ManiforgeSession.initNavSwitcher();
        }
    }

    function showProjectsLogin() {
        panel.classList.add('hidden');
        loginPanel.classList.remove('hidden');
    }

    function headers() {
        const h = { Accept: 'application/json', 'Content-Type': 'application/json' };
        const t = localStorage.getItem(STORAGE.access);
        if (t) h.Authorization = 'Bearer ' + t;
        const csrf = localStorage.getItem(STORAGE.csrf);
        if (csrf) h['X-CSRF-Token'] = csrf;
        const exp = Number(localStorage.getItem(STORAGE.actionExpires) || '0');
        const act = localStorage.getItem(STORAGE.action);
        if (act && exp > Date.now()) h['X-Action-Token'] = act;
        return h;
    }

    async function login() {
        loginMessage.textContent = 'Вход…';
        const phone = loginPhoneField.getFullPhone();
        const password = document.getElementById('password').value;
        if (!phone) {
            loginMessage.textContent = 'Укажите телефон';
            return;
        }
        const res = await fetch(API + '/auth/login', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone, password }),
        });
        const data = await res.json().catch(() => ({}));
        const session = data.session || data.credentials?.session;
        if (!res.ok || !data.ok || !session) {
            loginMessage.textContent = data.error || ('HTTP ' + res.status);
            return;
        }
        localStorage.setItem(STORAGE.access, session.access_token || '');
        localStorage.setItem(STORAGE.csrf, data.csrf_token || '');
        loginMessage.textContent = '';
        showProjectsWorkspace();
        await refresh();
    }

    async function refresh() {
        const [projRes, varRes, meRes] = await Promise.all([
            fetch(API + '/projects', { headers: headers() }),
            fetch(API + '/global-variables', { headers: headers() }),
            fetch(API + '/me', { headers: headers() }),
        ]);
        const projects = await projRes.json().catch(() => ({}));
        const vars = await varRes.json().catch(() => ({}));
        const me = await meRes.json().catch(() => ({}));
        const list = document.getElementById('projectList');
        const items = projects.items || [];
        if (items.length === 0) {
            list.innerHTML = '<p class="app-muted small">Нет проектов</p>';
        } else {
            list.innerHTML = items.map((p) => `
                <article class="app-card app-card-stretch">
                    <span>${p.scope || 'subtenant'}</span>
                    <strong><code>${p.code}</code></strong>
                    <small>${p.name}</small>
                    <button type="button" class="app-button app-button-secondary mt-2" data-project-id="${p.id}">Выбрать</button>
                </article>
            `).join('');
            list.querySelectorAll('[data-project-id]').forEach((btn) => {
                btn.addEventListener('click', () => switchProject(Number(btn.getAttribute('data-project-id'))));
            });
        }
        document.getElementById('varList').textContent = JSON.stringify(vars.items || [], null, 2);
        const sid = me.session?.project_id;
        document.getElementById('currentProject').textContent = sid
            ? 'project_id=' + sid
            : 'Без проекта (tenant/subtenant scope)';
    }

    async function switchProject(id) {
        await fetch(API + '/auth/switch-project', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({ project_id: id }),
        });
        await refresh();
    }

    document.getElementById('loginBtn').addEventListener('click', login);
    document.getElementById('clearProjectBtn').addEventListener('click', () => switchProject(null));
    document.getElementById('createProjBtn').addEventListener('click', async () => {
        const body = {
            code: document.getElementById('projCode').value.trim(),
            name: document.getElementById('projName').value.trim(),
        };
        if (document.getElementById('projTenantLevel').checked) {
            body.tenant_level = true;
        }
        await fetch(API + '/projects', { method: 'POST', headers: headers(), body: JSON.stringify(body) });
        await refresh();
    });
    document.getElementById('createVarBtn').addEventListener('click', async () => {
        await fetch(API + '/global-variables', {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({
                key: document.getElementById('varKey').value.trim(),
                value: document.getElementById('varValue').value,
                scope_level: document.getElementById('varScope').value,
            }),
        });
        await refresh();
    });

    if (localStorage.getItem(STORAGE.access)) {
        showProjectsWorkspace();
        refresh();
    } else {
        showProjectsLogin();
    }
})();
</script>
<?php require __DIR__ . '/layout/footer.php';
