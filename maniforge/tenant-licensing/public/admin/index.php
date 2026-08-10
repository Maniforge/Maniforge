<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maniforge Tenant Licensing Admin</title>
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body class="rbac-admin">
<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="/admin">
            <span class="brand-mark">Maniforge</span>
            <span>
                <b>Tenant Licensing</b>
                <span>Platform console</span>
            </span>
        </a>
        <div class="nav-title">Platform</div>
        <a class="nav-link" href="#access">Service token</a>
        <a class="nav-link" href="#ops">Метрики платформы</a>
        <a class="nav-link" href="#tenants">Tenants</a>
        <a class="nav-link" href="#subtenants">Subtenants</a>
        <a class="nav-link" href="#licenses">Licenses</a>
        <a class="nav-link" href="#quota">Entitlements и quota</a>
        <div class="nav-title">Справка</div>
        <a class="nav-link" href="/">Главная</a>
        <a class="nav-link" href="/admin">Единая админка</a>
        <a class="nav-link" href="/tenant-licensing/api-docs">API Reference</a>
        <a class="nav-link" href="/tenant-licensing/health">Health endpoint</a>
        <a class="nav-link" href="/admin/tenant">Tenant admin</a>
    </aside>

    <main class="content">
        <div class="topbar">
            <div>
                <h1>Maniforge Tenant Licensing Admin</h1>
                <p>Управление tenant, subtenant, тарифами, лицензиями, entitlements и лимитами.</p>
            </div>
            <div class="status">
                <span id="tokenBadge" class="badge warn">Token не задан</span>
                <span id="healthBadge" class="badge">Health не проверен</span>
                <span id="opsBadge" class="badge">Ops не загружены</span>
            </div>
        </div>

        <section id="ops" class="section card">
            <div class="card-head">
                <div>
                    <h2>Метрики платформы</h2>
                    <p>Сводка по всей licensing БД (не tenant scope). Pending events — сигнал для cron dispatch_events.</p>
                </div>
                <button id="btnOpsSummary" class="btn secondary">Обновить</button>
            </div>
            <div class="card-body">
                <div class="grid three">
                    <p id="opsTenants" class="badge">Tenants: —</p>
                    <p id="opsSubtenants" class="badge">Subtenants: —</p>
                    <p id="opsLicenses" class="badge">Active licenses: —</p>
                    <p id="opsPending" class="badge">Pending events: —</p>
                    <p id="opsGrants" class="badge">Active grants: —</p>
                    <p id="opsOldest" class="badge">Oldest pending: —</p>
                </div>
            </div>
        </section>

        <section id="access" class="grid two">
            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Доступ оператора</h2>
                        <p>Введите Bearer token из <code>TENANT_LICENSING_ADMIN_TOKEN</code>. В local/test окружении API может работать без токена.</p>
                    </div>
                    <span class="badge">Admin API</span>
                </div>
                <div class="card-body">
                    <div class="field">
                        <label for="adminToken">Admin token</label>
                        <input id="adminToken" type="password" autocomplete="off" placeholder="Bearer token">
                    </div>
                    <div class="field">
                        <label for="actor">Actor</label>
                        <input id="actor" placeholder="platform-operator" value="platform-operator">
                    </div>
                    <div class="actions">
                        <button id="btnSaveToken" class="btn">Сохранить локально</button>
                        <button id="btnHealth" class="btn secondary">Проверить health</button>
                        <button id="btnLoadAll" class="btn secondary">Загрузить данные</button>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Операционный сценарий</h2>
                        <p>Минимальный production pilot: tenant, subtenant, active license, затем пользователи в RBAC admin.</p>
                    </div>
                    <span class="badge ok">MVP</span>
                </div>
                <div class="card-body">
                    <ol class="app-feature-list">
                        <li>Создать tenant и subtenant.</li>
                        <li>Выбрать plan и назначить test license.</li>
                        <li>Проверить entitlements, quota и access-state.</li>
                        <li>Перейти в RBAC admin и создать пользователей/роли.</li>
                    </ol>
                </div>
            </article>
        </section>

        <section id="tenants" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <h2>Создать tenant</h2>
                    <span class="badge">Create</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="tenantCode">Code</label>
                            <input id="tenantCode" placeholder="acme">
                        </div>
                        <div class="field">
                            <label for="tenantName">Name</label>
                            <input id="tenantName" placeholder="Acme Corp">
                        </div>
                    </div>
                    <div class="actions">
                        <button id="btnCreateTenant" class="btn">Создать tenant</button>
                        <button id="btnTenants" class="btn secondary">Обновить список</button>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2>Статус tenant</h2>
                    <span class="badge warn">Lifecycle</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="tenantStatusCode">Tenant code</label>
                            <input id="tenantStatusCode" placeholder="acme">
                        </div>
                        <div class="field">
                            <label for="tenantStatus">Status</label>
                            <input id="tenantStatus" placeholder="active / suspended / disabled">
                        </div>
                    </div>
                    <div class="field">
                        <label for="tenantStatusName">Name</label>
                        <input id="tenantStatusName" placeholder="оставьте пустым, чтобы не менять">
                    </div>
                    <div class="actions">
                        <button id="btnUpdateTenant" class="btn">Обновить tenant</button>
                    </div>
                </div>
            </article>
        </section>

        <section class="section card">
            <div class="card-head">
                <h2>Tenants</h2>
                <span class="badge">Registry</span>
            </div>
            <div class="card-body">
                <div id="tenantsTable" class="table-wrap hidden"></div>
                <pre id="tenantsEmpty">Tenants ещё не загружены.</pre>
            </div>
        </section>

        <section id="subtenants" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <h2>Создать subtenant</h2>
                    <span class="badge">Scope</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="subtenantTenantCode">Tenant code</label>
                            <input id="subtenantTenantCode" placeholder="acme">
                        </div>
                        <div class="field">
                            <label for="subtenantCode">Subtenant code</label>
                            <input id="subtenantCode" placeholder="main">
                        </div>
                    </div>
                    <div class="field">
                        <label for="subtenantName">Name</label>
                        <input id="subtenantName" placeholder="Main workspace">
                    </div>
                    <div class="actions">
                        <button id="btnCreateSubtenant" class="btn">Создать subtenant</button>
                        <button id="btnSubtenants" class="btn secondary">Загрузить subtenants</button>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2>Plans</h2>
                    <span class="badge">Catalog</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="planCode">Plan code</label>
                            <input id="planCode" placeholder="starter">
                        </div>
                        <div class="field">
                            <label for="planName">Plan name</label>
                            <input id="planName" placeholder="Starter">
                        </div>
                    </div>
                    <div class="field-grid">
                        <div class="field">
                            <label for="planStatus">Status</label>
                            <input id="planStatus" placeholder="active / disabled" value="active">
                        </div>
                        <div class="field">
                            <label for="planLimits">Limits JSON</label>
                            <input id="planLimits" placeholder='{"max_users":25}'>
                        </div>
                    </div>
                    <div class="field">
                        <label for="planFeatures">Features JSON</label>
                        <input id="planFeatures" placeholder='{"rbac":true,"admin_api":true}'>
                    </div>
                    <div class="actions">
                        <button id="btnUpsertPlan" class="btn">Создать/update plan</button>
                        <button id="btnPlans" class="btn">Загрузить plans</button>
                    </div>
                    <div id="plansTable" class="table-wrap section hidden"></div>
                </div>
            </article>
        </section>

        <section class="section card">
            <div class="card-head">
                <h2>Subtenants</h2>
                <span class="badge">Registry</span>
            </div>
            <div class="card-body">
                <div id="subtenantsTable" class="table-wrap hidden"></div>
                <pre id="subtenantsEmpty">Subtenants ещё не загружены.</pre>
            </div>
        </section>

        <section id="licenses" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <h2>Назначить лицензию</h2>
                    <span class="badge ok">Active</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="licenseTenantCode">Tenant code</label>
                            <input id="licenseTenantCode" placeholder="acme">
                        </div>
                        <div class="field">
                            <label for="licensePlanCode">Plan code</label>
                            <input id="licensePlanCode" placeholder="starter">
                        </div>
                    </div>
                    <div class="field-grid">
                        <div class="field">
                            <label for="licenseSeats">Seats max</label>
                            <input id="licenseSeats" type="number" min="1" placeholder="25">
                        </div>
                        <div class="field">
                            <label for="licenseExpires">Expires at</label>
                            <input id="licenseExpires" placeholder="2026-12-31 23:59:59">
                        </div>
                    </div>
                    <div class="actions">
                        <button id="btnAssignLicense" class="btn">Назначить лицензию</button>
                        <button id="btnLicenses" class="btn secondary">Список licenses</button>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2>Отозвать лицензию</h2>
                    <span class="badge danger">Revoke</span>
                </div>
                <div class="card-body">
                    <div class="field">
                        <label for="revokeTenantCode">Tenant code</label>
                        <input id="revokeTenantCode" placeholder="acme">
                    </div>
                    <div class="field">
                        <label for="revokeReason">Reason</label>
                        <input id="revokeReason" placeholder="pilot finished">
                    </div>
                    <div class="actions">
                        <button id="btnRevokeLicense" class="btn danger">Отозвать active license</button>
                    </div>
                </div>
            </article>
        </section>

        <section id="quota" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <h2>Entitlements и quota</h2>
                    <span class="badge">Read</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="quotaTenantCode">Tenant code</label>
                            <input id="quotaTenantCode" placeholder="acme">
                        </div>
                        <div class="field">
                            <label for="quotaMetric">Metric</label>
                            <input id="quotaMetric" placeholder="users">
                        </div>
                    </div>
                    <div class="actions">
                        <button id="btnEntitlements" class="btn">Entitlements</button>
                        <button id="btnQuota" class="btn secondary">Quota</button>
                        <button id="btnAudit" class="btn secondary">Audit</button>
                        <button id="btnEvents" class="btn secondary">Events</button>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2>Результат</h2>
                    <span class="badge">JSON</span>
                </div>
                <div class="card-body">
                    <pre id="output" class="rbac-output">Готово к работе.</pre>
                </div>
            </article>
        </section>
    </main>
</div>

<script>
'use strict';

const state = {
    token: localStorage.getItem('maniforge_tl_admin_token') || '',
    actor: localStorage.getItem('maniforge_tl_actor') || 'platform-operator',
};

const $ = (id) => document.getElementById(id);
const output = $('output');

function setOutput(payload) {
    output.textContent = typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
}

function headers() {
    const result = {
        'Content-Type': 'application/json',
        'X-Actor': $('actor').value.trim() || 'platform-operator',
    };
    if (state.token !== '') {
        result.Authorization = `Bearer ${state.token}`;
    }
    return result;
}

async function request(path, options = {}) {
    const response = await fetch(`/tenant-licensing${path}`, {
        ...options,
        headers: { ...headers(), ...(options.headers || {}) },
    });
    const text = await response.text();
    let payload;
    try {
        payload = text === '' ? {} : JSON.parse(text);
    } catch (error) {
        payload = { ok: false, error: text };
    }
    if (!response.ok) {
        payload.http_status = response.status;
    }
    setOutput(payload);
    return payload;
}

function table(targetId, emptyId, rows) {
    const target = $(targetId);
    const empty = $(emptyId);
    if (!Array.isArray(rows) || rows.length === 0) {
        target.classList.add('hidden');
        if (empty) empty.classList.remove('hidden');
        return;
    }
    const keys = Array.from(rows.reduce((set, row) => {
        Object.keys(row).forEach((key) => set.add(key));
        return set;
    }, new Set()));
    target.innerHTML = `<table><thead><tr>${keys.map((key) => `<th>${escapeHtml(key)}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${keys.map((key) => `<td>${escapeHtml(formatValue(row[key]))}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
    target.classList.remove('hidden');
    if (empty) empty.classList.add('hidden');
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}

function formatValue(value) {
    if (value === null || value === undefined) return '';
    if (typeof value === 'object') return JSON.stringify(value);
    return value;
}

function input(id) {
    return $(id).value.trim();
}

function body(payload) {
    return JSON.stringify(payload);
}

function jsonField(id) {
    const value = input(id);
    if (value === '') return {};
    try {
        return JSON.parse(value);
    } catch (error) {
        setOutput({ ok: false, error: `${id} должен быть валидным JSON` });
        throw error;
    }
}

function updateTokenBadge() {
    $('tokenBadge').className = state.token === '' ? 'badge warn' : 'badge ok';
    $('tokenBadge').textContent = state.token === '' ? 'Token не задан' : 'Token сохранён';
}

$('adminToken').value = state.token;
$('actor').value = state.actor;
updateTokenBadge();

async function refreshPlatformOps() {
    if (state.token === '') {
        $('opsBadge').textContent = 'Задайте token';
        $('opsBadge').className = 'badge warn';
        return;
    }
    const payload = await request('/api/v1/ops/summary');
    if (!payload.ok || !payload.summary) {
        $('opsBadge').textContent = 'Ops error';
        $('opsBadge').className = 'badge danger';
        return;
    }
    const s = payload.summary;
    $('opsTenants').textContent = 'Tenants: ' + s.tenants_active + ' active / ' + s.tenants_total;
    $('opsSubtenants').textContent = 'Subtenants: ' + s.subtenants_total;
    $('opsLicenses').textContent = 'Active licenses: ' + s.licenses_active;
    $('opsPending').textContent = 'Pending events: ' + s.events_pending;
    $('opsGrants').textContent = 'Active grants: ' + s.grants_active;
    $('opsOldest').textContent = 'Oldest pending: ' + (s.events_pending_oldest_created_at || '—');
    $('opsBadge').textContent = 'Ops ' + (s.checked_at || '');
    $('opsBadge').className = s.events_pending > 0 ? 'badge warn' : 'badge ok';
}

$('btnOpsSummary').addEventListener('click', refreshPlatformOps);

$('btnSaveToken').addEventListener('click', async () => {
    state.token = $('adminToken').value.trim();
    state.actor = input('actor') || 'platform-operator';
    localStorage.setItem('maniforge_tl_admin_token', state.token);
    localStorage.setItem('maniforge_tl_actor', state.actor);
    updateTokenBadge();
    setOutput({ ok: true, saved: true });
    await refreshPlatformOps();
});

$('btnHealth').addEventListener('click', async () => {
    const payload = await request('/health', { headers: {} });
    $('healthBadge').className = payload.ok ? 'badge ok' : 'badge danger';
    $('healthBadge').textContent = payload.ok ? 'Health OK' : 'Health error';
});

$('btnLoadAll').addEventListener('click', async () => {
    await refreshPlatformOps();
    await loadTenants();
    await loadPlans();
});

async function loadTenants() {
    const payload = await request('/api/v1/tenants');
    table('tenantsTable', 'tenantsEmpty', payload.items || []);
}

async function loadPlans() {
    const payload = await request('/api/v1/plans');
    table('plansTable', null, payload.items || []);
}

$('btnTenants').addEventListener('click', loadTenants);
$('btnPlans').addEventListener('click', loadPlans);

$('btnUpsertPlan').addEventListener('click', async () => {
    await request('/api/v1/plans', {
        method: 'POST',
        body: body({
            code: input('planCode'),
            name: input('planName'),
            status: input('planStatus') || 'active',
            features: jsonField('planFeatures'),
            limits: jsonField('planLimits'),
        }),
    });
    await loadPlans();
});

$('btnCreateTenant').addEventListener('click', async () => {
    await request('/api/v1/tenants', {
        method: 'POST',
        body: body({ code: input('tenantCode'), name: input('tenantName') }),
    });
    await loadTenants();
});

$('btnUpdateTenant').addEventListener('click', async () => {
    const payload = { status: input('tenantStatus') };
    if (input('tenantStatusName') !== '') payload.name = input('tenantStatusName');
    await request(`/api/v1/tenants/${encodeURIComponent(input('tenantStatusCode'))}`, {
        method: 'PATCH',
        body: body(payload),
    });
    await loadTenants();
});

$('btnCreateSubtenant').addEventListener('click', async () => {
    await request(`/api/v1/tenants/${encodeURIComponent(input('subtenantTenantCode'))}/subtenants`, {
        method: 'POST',
        body: body({ code: input('subtenantCode'), name: input('subtenantName') }),
    });
    await loadSubtenants();
});

async function loadSubtenants() {
    const tenantCode = input('subtenantTenantCode') || input('quotaTenantCode') || input('licenseTenantCode');
    if (tenantCode === '') {
        setOutput({ ok: false, error: 'Укажите tenant code для загрузки subtenants' });
        return;
    }
    const payload = await request(`/api/v1/tenants/${encodeURIComponent(tenantCode)}/subtenants`);
    table('subtenantsTable', 'subtenantsEmpty', payload.items || []);
}

$('btnSubtenants').addEventListener('click', loadSubtenants);

$('btnAssignLicense').addEventListener('click', async () => {
    await request('/api/v1/licenses/assign', {
        method: 'POST',
        body: body({
            tenant_code: input('licenseTenantCode'),
            plan_code: input('licensePlanCode'),
            seats_max: input('licenseSeats') === '' ? null : Number(input('licenseSeats')),
            expires_at: input('licenseExpires') || null,
        }),
    });
});

$('btnLicenses').addEventListener('click', async () => {
    const payload = await request('/api/v1/licenses');
    table('plansTable', null, payload.items || []);
});

$('btnRevokeLicense').addEventListener('click', async () => {
    await request('/api/v1/licenses/revoke', {
        method: 'POST',
        body: body({
            tenant_code: input('revokeTenantCode'),
            reason: input('revokeReason') || 'manual_revoke',
        }),
    });
});

$('btnEntitlements').addEventListener('click', () => {
    request(`/api/v1/tenants/${encodeURIComponent(input('quotaTenantCode'))}/entitlements`);
});

$('btnQuota').addEventListener('click', () => {
    const metric = input('quotaMetric');
    const qs = metric === '' ? '' : `?metric=${encodeURIComponent(metric)}`;
    request(`/api/v1/tenants/${encodeURIComponent(input('quotaTenantCode'))}/quota${qs}`);
});

$('btnAudit').addEventListener('click', () => {
    const tenantCode = input('quotaTenantCode');
    const qs = tenantCode === '' ? '' : `?tenant_code=${encodeURIComponent(tenantCode)}`;
    request(`/api/v1/audit${qs}`);
});

$('btnEvents').addEventListener('click', () => {
    const tenantCode = input('quotaTenantCode');
    const qs = tenantCode === '' ? '' : `?tenant_code=${encodeURIComponent(tenantCode)}`;
    request(`/api/v1/events${qs}`);
});

if (state.token !== '') {
    refreshPlatformOps();
}
</script>
</body>
</html>
