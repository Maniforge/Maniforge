<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maniforge RBAC Admin Console</title>
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body class="rbac-admin">
<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="/admin">
            <span class="brand-mark">Maniforge</span>
            <span>
                <b>RBAC Admin</b>
                <span>Production console</span>
            </span>
        </a>
        <div class="nav-title">Операции</div>
        <a class="nav-link" href="#access">Доступ и вход</a>
        <a class="nav-link" href="#overview">Обзор и метрики</a>
        <a class="nav-link" href="#users-admin">Пользователи</a>
        <a class="nav-link" href="#sessions">Сессии</a>
        <a class="nav-link" href="#roles">Роли пользователей</a>
        <a class="nav-link" href="#policy">Policy rules</a>
        <a class="nav-link" href="#status">Статусы пользователей</a>
        <a class="nav-link" href="#pd-compliance">152-ФЗ / ПДн</a>
        <a class="nav-link" href="#events">Audit и events</a>
        <a class="nav-link" href="#entity-delegation">Доступ сущностей (grant)</a>
        <div class="nav-title">Справка</div>
        <a class="nav-link" href="/rbac/api-docs">API Reference</a>
        <a class="nav-link" href="/rbac/health">Health endpoint</a>
    </aside>

    <main class="content">
        <div class="topbar">
            <div>
                <h1>Maniforge RBAC Admin Console</h1>
                <p>Управление пользователями, ролями, сессиями и security policy в текущем tenant scope.</p>
            </div>
            <div class="status">
                <span id="authBadge" class="badge danger">Не авторизован</span>
                <span id="stepBadge" class="badge warn">Step-up не выполнен</span>
                <span id="actionBadge" class="badge warn">Action token нет</span>
                <span id="scopeBadge" class="badge">Scope неизвестен</span>
            </div>
        </div>

        <section id="access" class="grid two">
            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Вход администратора</h2>
                        <p>После входа консоль получает access, refresh и CSRF token. Токены не выводятся полностью.</p>
                    </div>
                    <span class="badge">Auth</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="login">Login</label>
                            <input id="login" autocomplete="username" placeholder="admin">
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <input id="password" type="password" autocomplete="current-password" placeholder="password">
                        </div>
                    </div>
                    <div class="actions">
                        <button id="btnLogin" class="btn">Войти</button>
                        <button id="btnLogout" class="btn secondary">Logout текущей сессии</button>
                        <button id="btnLogoutAll" class="btn danger">Logout all</button>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Step-up</h2>
                        <p>Нужен для чувствительных admin-действий, если policy требует fresh reauth.</p>
                    </div>
                    <span class="badge warn">Sensitive</span>
                </div>
                <div class="card-body">
                    <div class="field">
                        <label for="reauthPassword">Повторите пароль</label>
                        <input id="reauthPassword" type="password" autocomplete="current-password" placeholder="password">
                    </div>
                    <div class="actions">
                        <button id="btnReauth" class="btn">Выполнить step-up</button>
                        <button id="btnMe" class="btn secondary">Проверить текущую сессию</button>
                    </div>
                </div>
            </article>
        </section>

        <section id="overview" class="section card">
            <div class="card-head">
                <div>
                    <h2>Операционный обзор (scope сессии)</h2>
                    <p>Счётчики по текущему tenant/subtenant. Для admin-мутаций: reauth → action token в заголовке X-Action-Token.</p>
                </div>
                <div class="actions">
                    <button id="btnRefreshOps" class="btn secondary">Обновить метрики</button>
                    <button id="btnUsers" class="btn">Список пользователей</button>
                </div>
            </div>
            <div class="card-body">
                <div class="grid three">
                    <p id="opsUsers" class="badge">Users: —</p>
                    <p id="opsSessions" class="badge">Sessions: —</p>
                    <p id="opsAudit" class="badge">Audit (50): —</p>
                    <p id="opsSec" class="badge">Security (50): —</p>
                    <p id="opsStepUp" class="badge">Step-up policy: —</p>
                    <p id="usersCount" class="badge">Users list: не загружено</p>
                </div>
                <div class="actions section">
                    <button id="btnMyPermissions" class="btn secondary">Permissions</button>
                    <button id="btnMyAccess" class="btn secondary">Access snapshot</button>
                    <button id="btnRoles" class="btn secondary">Роли</button>
                    <button id="btnPermissions" class="btn secondary">Permissions</button>
                </div>
            </div>
        </section>

        <section class="section card">
            <div class="card-head">
                <div>
                    <h2>Пользователи в scope</h2>
                    <p>Список нужен для выбора target user в role/status операциях. Create/activate проверяет лицензию и seats, если Tenant Licensing доступен.</p>
                </div>
            </div>
            <div class="card-body">
                <div id="usersTable" class="table-wrap hidden"></div>
                <pre id="usersEmpty">Пользователи ещё не загружены.</pre>
            </div>
        </section>

        <section id="users-admin" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Создать пользователя</h2>
                        <p>Пользователь создаётся только в текущем tenant/subtenant scope.</p>
                    </div>
                    <span class="badge warn">Seats check</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="newUserLogin">Login</label>
                            <input id="newUserLogin" placeholder="operator">
                        </div>
                        <div class="field">
                            <label for="newUserEmail">Email</label>
                            <input id="newUserEmail" placeholder="operator@example.test">
                        </div>
                    </div>
                    <div class="field-grid">
                        <div class="field">
                            <label for="newUserPassword">Password</label>
                            <input id="newUserPassword" type="password" placeholder="temporary password">
                        </div>
                        <div class="field">
                            <label for="newUserStatus">Status</label>
                            <input id="newUserStatus" placeholder="active / locked / disabled" value="active">
                        </div>
                    </div>
                    <div class="field">
                        <label><input id="newUserMfa" type="checkbox"> MFA required</label>
                    </div>
                    <div class="field">
                        <label for="newUserReason">Reason</label>
                        <input id="newUserReason" placeholder="tenant admin request">
                    </div>
                    <button id="btnCreateUser" class="btn">Создать пользователя</button>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Обновить / удалить пользователя</h2>
                        <p>Пустые поля update игнорируются; password меняет security_version.</p>
                    </div>
                    <span class="badge danger">Scoped</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="editUserId">User ID</label>
                            <input id="editUserId" placeholder="1">
                        </div>
                        <div class="field">
                            <label for="editUserStatus">Status</label>
                            <input id="editUserStatus" placeholder="active / locked / disabled">
                        </div>
                    </div>
                    <div class="field-grid">
                        <div class="field">
                            <label for="editUserEmail">Email</label>
                            <input id="editUserEmail" placeholder="new-email@example.test">
                        </div>
                        <div class="field">
                            <label for="editUserPassword">New password</label>
                            <input id="editUserPassword" type="password" placeholder="optional">
                        </div>
                    </div>
                    <div class="field">
                        <label><input id="editUserMfa" type="checkbox"> Set MFA required</label>
                    </div>
                    <div class="field">
                        <label for="editUserReason">Reason</label>
                        <input id="editUserReason" placeholder="access lifecycle request">
                    </div>
                    <div class="actions">
                        <button id="btnUpdateUser" class="btn">Обновить</button>
                        <button id="btnDeleteUser" class="btn danger">Удалить</button>
                    </div>
                </div>
            </article>
        </section>

        <section id="sessions" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Сессии</h2>
                        <p>Просмотр и отзыв активных сессий в текущем tenant/subtenant scope.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="actions">
                        <button id="btnSessions" class="btn">Загрузить сессии</button>
                    </div>
                    <div id="sessionsTable" class="table-wrap section hidden"></div>
                </div>
            </article>
            <article class="card">
                <div class="card-head">
                    <h2>Revoke sessions</h2>
                    <span class="badge warn">Audit reason</span>
                </div>
                <div class="card-body">
                    <div class="field">
                        <label for="revokeSessionId">Session ID</label>
                        <input id="revokeSessionId" placeholder="session id">
                    </div>
                    <div class="field">
                        <label for="sessionReason">Reason</label>
                        <input id="sessionReason" placeholder="incident / policy reason">
                    </div>
                    <div class="actions">
                        <button id="btnRevokeSession" class="btn danger">Отозвать одну</button>
                    </div>
                    <div class="field section">
                        <label><input id="sessionBatchDryRun" type="checkbox"> dry_run</label>
                    </div>
                    <div class="field">
                        <label for="sessionBatchIds">Session IDs JSON</label>
                        <textarea id="sessionBatchIds" rows="4" placeholder='["session-id-1","session-id-2"]'></textarea>
                    </div>
                    <div class="actions">
                        <button id="btnBatchRevokeSessions" class="btn danger">Batch revoke</button>
                    </div>
                </div>
            </article>
        </section>

        <section id="roles" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Роли пользователя</h2>
                        <p>Назначение и снятие ролей защищено hierarchy guard, scope check и last-admin guard.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="targetUserId">Target user ID</label>
                            <input id="targetUserId" placeholder="1">
                        </div>
                        <div class="field">
                            <label for="targetRoleCode">Role code</label>
                            <input id="targetRoleCode" placeholder="security_auditor">
                        </div>
                    </div>
                    <div class="field">
                        <label for="roleReason">Reason</label>
                        <input id="roleReason" placeholder="why this change is needed">
                    </div>
                    <div class="actions">
                        <button id="btnUserRoles" class="btn secondary">Показать роли</button>
                        <button id="btnEffectiveAccess" class="btn secondary">Effective access</button>
                        <button id="btnAssignRole" class="btn">Assign</button>
                        <button id="btnRevokeRole" class="btn danger">Revoke</button>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <h2>Batch role mutations</h2>
                    <span class="badge">Dry run</span>
                </div>
                <div class="card-body">
                    <div class="field">
                        <label for="batchReason">Batch reason</label>
                        <input id="batchReason" placeholder="quarterly access review">
                    </div>
                    <div class="field">
                        <label><input id="batchDryRun" type="checkbox" checked> dry_run</label>
                    </div>
                    <div class="field">
                        <label for="batchItems">Items JSON</label>
                        <textarea id="batchItems" rows="6" placeholder='[{"user_id":1,"role_code":"security_auditor","action":"assign"}]'></textarea>
                    </div>
                    <button id="btnBatchRoles" class="btn">Запустить batch</button>
                </div>
            </article>
        </section>

        <section class="section grid two">
            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Custom role CRUD</h2>
                        <p>Код роли автоматически неймспейсится текущим tenant/subtenant scope.</p>
                    </div>
                    <span class="badge">Tenant role</span>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="customRoleCode">Role code</label>
                            <input id="customRoleCode" placeholder="analyst">
                        </div>
                        <div class="field">
                            <label for="customRoleName">Role name</label>
                            <input id="customRoleName" placeholder="Tenant Analyst">
                        </div>
                    </div>
                    <div class="field">
                        <label for="customRoleReason">Reason</label>
                        <input id="customRoleReason" placeholder="tenant role lifecycle">
                    </div>
                    <div class="actions">
                        <button id="btnCreateRole" class="btn">Create role</button>
                        <button id="btnUpdateRole" class="btn secondary">Update role</button>
                        <button id="btnDeleteRole" class="btn danger">Delete role</button>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Role permissions</h2>
                        <p>Заменяет permissions у custom-роли текущего scope.</p>
                    </div>
                    <span class="badge warn">Replace</span>
                </div>
                <div class="card-body">
                    <div class="field">
                        <label for="permRoleCode">Role code</label>
                        <input id="permRoleCode" placeholder="analyst">
                    </div>
                    <div class="field">
                        <label for="permRoleItems">Permissions JSON</label>
                        <textarea id="permRoleItems" rows="6" placeholder='["admin.users.read","admin.roles.read"]'></textarea>
                    </div>
                    <div class="field">
                        <label for="permRoleReason">Reason</label>
                        <input id="permRoleReason" placeholder="permission review">
                    </div>
                    <div class="actions">
                        <button id="btnRolePermissions" class="btn secondary">Показать permissions</button>
                        <button id="btnReplaceRolePermissions" class="btn">Replace permissions</button>
                    </div>
                </div>
            </article>
        </section>

        <section id="policy" class="section card">
            <div class="card-head">
                <div>
                    <h2>Admin policy rules</h2>
                    <p>IP allowlist, UTC окно и require step-up. Отключение step-up разрешено только super_admin.</p>
                </div>
                <span class="badge warn">Policy</span>
            </div>
            <div class="card-body">
                <div class="actions">
                    <button id="btnLoadPolicies" class="btn secondary">Загрузить policy</button>
                </div>
                <div class="field section">
                    <label for="policyReason">Reason</label>
                    <input id="policyReason" placeholder="why policy is changed">
                </div>
                <div class="field">
                    <label for="policyAllowedIps">Allowed IPs, через запятую</label>
                    <input id="policyAllowedIps" placeholder="127.0.0.1,10.0.0.10">
                </div>
                <div class="field-grid">
                    <div class="field">
                        <label for="policyHourStart">Hour start UTC</label>
                        <input id="policyHourStart" type="number" min="0" max="23" value="0">
                    </div>
                    <div class="field">
                        <label for="policyHourEnd">Hour end UTC</label>
                        <input id="policyHourEnd" type="number" min="0" max="23" value="23">
                    </div>
                </div>
                <div class="field">
                    <label><input id="policyRequireStepUp" type="checkbox" checked> require step-up for admin actions</label>
                </div>
                <button id="btnSavePolicies" class="btn">Сохранить policy</button>
            </div>
        </section>

        <section id="status" class="section card">
            <div class="card-head">
                <div>
                    <h2>Batch user status changes</h2>
                    <p>Блокировка/разблокировка пользователей. При `locked` и `disabled` активные сессии отзываются.</p>
                </div>
                <span class="badge warn">Destructive</span>
            </div>
            <div class="card-body">
                <div class="field">
                    <label for="userStatusReason">Reason</label>
                    <input id="userStatusReason" placeholder="incident-456 / policy enforcement">
                </div>
                <div class="field">
                    <label><input id="userStatusDryRun" type="checkbox" checked> dry_run</label>
                </div>
                <div class="field">
                    <label for="userStatusItems">Items JSON</label>
                    <textarea id="userStatusItems" rows="6" placeholder='[{"user_id":1,"status":"locked"}]'></textarea>
                </div>
                <button id="btnBatchUserStatus" class="btn danger">Запустить status batch</button>
            </div>
        </section>

        <section id="pd-onboarding" class="section card">
            <div class="card-head">
                <div>
                    <h2>Онбординг оператора ПДн (152-ФЗ)</h2>
                    <p>Клиент — оператор, Maniforge — обработчик. Заполните профиль до приёма пользователей.</p>
                </div>
                <button id="btnPdComplianceStatus" class="btn secondary">Статус</button>
            </div>
            <div class="card-body">
                <pre id="pdComplianceStatus" class="mb-3">Нажмите «Статус».</pre>
                <div class="field-grid">
                    <div class="field">
                        <label for="pdOperatorName">Название оператора</label>
                        <input id="pdOperatorName" placeholder="ООО Клиент">
                    </div>
                    <div class="field">
                        <label for="pdOperatorInn">ИНН</label>
                        <input id="pdOperatorInn" placeholder="7700000000">
                    </div>
                </div>
                <div class="field">
                    <label for="pdPrivacyUrl">URL политики конфиденциальности</label>
                    <input id="pdPrivacyUrl" placeholder="https://client.example/privacy">
                </div>
                <div class="field-grid">
                    <div class="field">
                        <label for="pdDpoName">DPO (ФИО)</label>
                        <input id="pdDpoName">
                    </div>
                    <div class="field">
                        <label for="pdDpoEmail">DPO email</label>
                        <input id="pdDpoEmail" type="email">
                    </div>
                </div>
                <div class="field-grid">
                    <div class="field">
                        <label for="pdStorageRegion">Регион хранения</label>
                        <input id="pdStorageRegion" value="RU">
                    </div>
                    <div class="field">
                        <label for="pdRknDate">Дата уведомления РКН</label>
                        <input id="pdRknDate" type="date">
                    </div>
                </div>
                <div class="row-actions">
                    <button id="btnPdLoadProfile" class="btn secondary">Загрузить профиль</button>
                    <button id="btnPdSaveProfile" class="btn">Сохранить профиль</button>
                    <button id="btnPdDpaAck" class="btn secondary">Подтвердить DPA (клиент)</button>
                </div>
            </div>
        </section>

        <section id="pd-compliance" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <div>
                        <h2>Запросы субъектов ПДн</h2>
                        <p>Очередь запросов по 152-ФЗ в текущем tenant/subtenant. Для resolve нужен step-up.</p>
                    </div>
                    <button id="btnPdRequests" class="btn secondary">Загрузить</button>
                </div>
                <div class="card-body">
                    <div id="pdRequestsTable" class="table-wrap hidden"></div>
                    <pre id="pdRequestsEmpty">Запросы не загружены.</pre>
                </div>
            </article>
            <article class="card">
                <div class="card-head">
                    <h2>Обработать запрос</h2>
                </div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label for="pdResolveId">Request ID</label>
                            <input id="pdResolveId" type="number" min="1" placeholder="1">
                        </div>
                        <div class="field">
                            <label for="pdResolveStatus">Статус</label>
                            <select id="pdResolveStatus">
                                <option value="completed">completed</option>
                                <option value="in_progress">in_progress</option>
                                <option value="rejected">rejected</option>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label for="pdResolveNote">Комментарий оператора</label>
                        <input id="pdResolveNote" placeholder="Обработано по регламенту">
                    </div>
                    <button id="btnPdResolve" class="btn">Resolve</button>
                </div>
            </article>
        </section>

        <section id="entity-delegation" class="section card">
            <div class="card-head">
                <div>
                    <h2>Доступ сущностей: родитель ↔ клиент (grant)</h2>
                    <p>
                        Сущности (склады и др.) разделены по <strong>tenant + project</strong> (default <code>main</code>).
                        Доступ peer-tenant по grant настраивается на узле Warehouses (<code>PATCH /warehouses/api/v1/stocks/{id}</code>).
                    </p>
                </div>
                <span class="badge">tenant_admin</span>
            </div>
            <div class="card-body">
                <p class="app-muted small mb-2">
                    Поля: <code>delegation_share_tenant_ids</code>, <code>share_with_principal</code>, <code>share_with_managed</code>.
                    Список peer: <code>GET /warehouses/api/v1/delegation/grant-peers</code> (Bearer из RBAC admin).
                </p>
                <button type="button" id="btnGrantPeers" class="btn secondary">Загрузить grant peers</button>
                <pre id="grantPeersOut" class="mt-2">—</pre>
                <p class="app-muted small mt-2 mb-0">
                    <a href="/docs/maniforge-entity-scope.md">MANIFORGE_ENTITY_SCOPE</a> ·
                    <a href="/api#wh-stocks">Warehouses API</a>
                </p>
            </div>
        </section>

        <section id="events" class="section grid two">
            <article class="card">
                <div class="card-head">
                    <h2>Audit log</h2>
                    <button id="btnAudit" class="btn secondary">Загрузить</button>
                    <button id="btnAuditExport" class="btn secondary">Export JSON</button>
                </div>
                <div class="card-body">
                    <div id="auditTable" class="table-wrap hidden"></div>
                    <pre id="auditEmpty">Audit events ещё не загружены.</pre>
                </div>
            </article>
            <article class="card">
                <div class="card-head">
                    <h2>Security events</h2>
                    <button id="btnSecEvents" class="btn secondary">Загрузить</button>
                </div>
                <div class="card-body">
                    <div id="secEventsTable" class="table-wrap hidden"></div>
                    <pre id="secEventsEmpty">Security events ещё не загружены.</pre>
                </div>
            </article>
        </section>

        <section class="section card">
            <div class="card-head">
                <div>
                    <h2>Операционный ответ</h2>
                    <p>Здесь показывается последний raw JSON для диагностики.</p>
                </div>
                <span id="lastStatus" class="badge">ready</span>
            </div>
            <div class="card-body">
                <pre id="out">Готово к работе.</pre>
            </div>
        </section>
    </main>
</div>

<script>
const UNIFIED_STORAGE = {
    access: 'maniforge_admin_access_token',
    refresh: 'maniforge_admin_refresh_token',
    csrf: 'maniforge_admin_csrf_token',
    action: 'maniforge_admin_action_token',
    actionExpires: 'maniforge_admin_action_token_expires',
};

let accessToken = localStorage.getItem(UNIFIED_STORAGE.access) || '';
let refreshToken = localStorage.getItem(UNIFIED_STORAGE.refresh) || '';
let csrfToken = localStorage.getItem(UNIFIED_STORAGE.csrf) || '';
let actionToken = localStorage.getItem(UNIFIED_STORAGE.action) || '';
let actionTokenExpiresAt = Number(localStorage.getItem(UNIFIED_STORAGE.actionExpires) || '0');
let currentSession = null;

function persistUnifiedTokens() {
    if (accessToken) {
        localStorage.setItem(UNIFIED_STORAGE.access, accessToken);
        localStorage.setItem(UNIFIED_STORAGE.refresh, refreshToken);
        localStorage.setItem(UNIFIED_STORAGE.csrf, csrfToken);
        if (actionToken && actionTokenExpiresAt > Date.now()) {
            localStorage.setItem(UNIFIED_STORAGE.action, actionToken);
            localStorage.setItem(UNIFIED_STORAGE.actionExpires, String(actionTokenExpiresAt));
        } else {
            actionToken = '';
            actionTokenExpiresAt = 0;
            localStorage.removeItem(UNIFIED_STORAGE.action);
            localStorage.removeItem(UNIFIED_STORAGE.actionExpires);
        }
        return;
    }
    localStorage.removeItem(UNIFIED_STORAGE.access);
    localStorage.removeItem(UNIFIED_STORAGE.refresh);
    localStorage.removeItem(UNIFIED_STORAGE.csrf);
    localStorage.removeItem(UNIFIED_STORAGE.action);
    localStorage.removeItem(UNIFIED_STORAGE.actionExpires);
}

function actionTokenValid() {
    return actionToken !== '' && actionTokenExpiresAt > Date.now();
}

function applySessionCredentials(sessionPayload, userPayload) {
    accessToken = sessionPayload.access_token || '';
    refreshToken = sessionPayload.refresh_token || '';
    const scope = sessionPayload.scope || {};
    currentSession = {
        id: sessionPayload.session_id || '',
        tenant_id: scope.tenant_id || userPayload?.tenant_id || 'current',
        subtenant_id: scope.subtenant_id || userPayload?.subtenant_id || 'scope',
    };
    persistUnifiedTokens();
    updateAuthState();
}

function requiresActionToken(method, path) {
    if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
        return false;
    }
    if (path.startsWith('/api/v1/auth/login') || path.startsWith('/api/v1/auth/refresh')) {
        return false;
    }
    if (path.startsWith('/api/v1/auth/logout')) {
        return false;
    }
    if (path.startsWith('/api/v1/auth/reauth')) {
        return false;
    }
    return path.startsWith('/api/v1/admin/');
}

function $(id) {
    return document.getElementById(id);
}

function setBadge(id, text, tone) {
    const node = $(id);
    node.textContent = text;
    node.className = 'badge' + (tone ? ' ' + tone : '');
}

function print(data, status) {
    $('out').textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
    $('lastStatus').textContent = status ? 'HTTP ' + status : 'ready';
    $('lastStatus').className = 'badge' + (status >= 400 ? ' danger' : status >= 300 ? ' warn' : ' ok');
}

function updateAuthState() {
    if (accessToken) {
        setBadge('authBadge', 'Авторизован', 'ok');
    } else {
        setBadge('authBadge', 'Не авторизован', 'danger');
    }
    if (currentSession) {
        setBadge('scopeBadge', currentSession.tenant_id + ' / ' + currentSession.subtenant_id, '');
    }
    if (actionTokenValid()) {
        const left = Math.max(0, Math.floor((actionTokenExpiresAt - Date.now()) / 1000));
        setBadge('actionBadge', 'Action token ' + left + 's', 'ok');
    } else {
        setBadge('actionBadge', 'Action token нет', 'warn');
    }
}

function preview(value) {
    return value ? value.slice(0, 10) + '...' : null;
}

async function api(path, method = 'GET', body = null) {
    const headers = { 'Accept': 'application/json' };
    if (body !== null) headers['Content-Type'] = 'application/json';
    if (accessToken) headers['Authorization'] = 'Bearer ' + accessToken;
    if (csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
        headers['X-CSRF-Token'] = csrfToken;
    }
    if (requiresActionToken(method, path)) {
        if (!actionTokenValid()) {
            const err = { ok: false, error: 'Нужен action token: выполните step-up (reauth)', code: 'step_up_required' };
            print(err, 403);
            return { status: 403, data: err };
        }
        headers['X-Action-Token'] = actionToken;
    }

    const response = await fetch('/rbac' + path, {
        method,
        headers,
        body: body !== null ? JSON.stringify(body) : undefined
    });
    const data = await response.json().catch(() => ({ ok: false, error: 'Invalid JSON response' }));
    print(data, response.status);
    return { status: response.status, data };
}

function parseJsonField(id, fallback) {
    const raw = $(id).value.trim();
    if (raw === '') return fallback;
    return JSON.parse(raw);
}

function renderTable(targetId, emptyId, rows, columns) {
    const target = $(targetId);
    const empty = $(emptyId);
    if (!Array.isArray(rows) || rows.length === 0) {
        target.classList.add('hidden');
        if (empty) empty.classList.remove('hidden');
        return;
    }

    const head = columns.map((column) => '<th>' + column.label + '</th>').join('');
    const body = rows.map((row) => {
        const cells = columns.map((column) => {
            const value = column.value(row);
            return '<td>' + String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char])) + '</td>';
        }).join('');
        return '<tr>' + cells + '</tr>';
    }).join('');

    target.innerHTML = '<table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';
    target.classList.remove('hidden');
    if (empty) empty.classList.add('hidden');
}

$('btnLogin').addEventListener('click', async () => {
    const res = await api('/api/v1/auth/login', 'POST', {
        login: $('login').value.trim(),
        password: $('password').value
    });
    if (res.data && res.data.session) {
        csrfToken = res.data.csrf_token || '';
        applySessionCredentials(res.data.session, res.data.user || null);
        print({ ...res.data, token_preview: preview(accessToken), refresh_preview: preview(refreshToken) }, res.status);
        await refreshOpsSummary();
    }
});

$('btnLogout').addEventListener('click', async () => {
    const res = await api('/api/v1/auth/logout', 'POST', {});
    if (res.status < 400) {
        accessToken = '';
        refreshToken = '';
        csrfToken = '';
        actionToken = '';
        actionTokenExpiresAt = 0;
        currentSession = null;
        persistUnifiedTokens();
        setBadge('stepBadge', 'Step-up не выполнен', 'warn');
        updateAuthState();
    }
});

$('btnLogoutAll').addEventListener('click', async () => {
    await api('/api/v1/auth/logout-all', 'POST', {});
});

$('btnReauth').addEventListener('click', async () => {
    const res = await api('/api/v1/auth/reauth', 'POST', { password: $('reauthPassword').value });
    if (res.status < 400 && res.data.step_up) {
        setBadge('stepBadge', 'Step-up активен', 'ok');
        const action = res.data.credentials?.action || {};
        if (action.action_token) {
            actionToken = action.action_token;
            actionTokenExpiresAt = Date.now() + (Number(action.expires_in || 900) * 1000);
            persistUnifiedTokens();
            updateAuthState();
        }
    }
});

$('btnMe').addEventListener('click', async () => {
    const res = await api('/api/v1/me', 'GET');
    if (res.data && res.data.session) {
        currentSession = {
            id: res.data.session.id || '',
            tenant_id: res.data.session.tenant_id || '',
            subtenant_id: res.data.session.subtenant_id || '',
        };
        updateAuthState();
    }
});

async function refreshOpsSummary() {
    if (!accessToken) {
        return;
    }
    const res = await api('/api/v1/admin/ops-summary', 'GET');
    if (res.status >= 400 || !res.data.summary) {
        return;
    }
    const s = res.data.summary;
    $('opsUsers').textContent = 'Users: ' + s.users_active + ' active / ' + s.users_total + ' total';
    $('opsSessions').textContent = 'Sessions active: ' + s.sessions_active;
    $('opsAudit').textContent = 'Audit (last 50): ' + s.audit_recent;
    $('opsSec').textContent = 'Security (last 50): ' + s.security_events_recent;
    $('opsStepUp').textContent = 'Step-up policy: ' + (s.step_up_required ? 'required' : 'off');
    $('usersCount').textContent = 'Users list: ' + s.users_total + ' in scope';
}

$('btnRefreshOps').addEventListener('click', refreshOpsSummary);

$('btnUsers').addEventListener('click', async () => {
    const res = await api('/api/v1/admin/users', 'GET');
    const users = res.data.items || [];
    $('usersCount').textContent = Array.isArray(users) ? users.length + ' users' : 'Ошибка';
    renderTable('usersTable', 'usersEmpty', users, [
        { label: 'ID', value: (row) => row.id },
        { label: 'Login', value: (row) => row.login },
        { label: 'Email', value: (row) => row.email },
        { label: 'Status', value: (row) => row.status },
        { label: 'MFA', value: (row) => row.mfa_required ? 'yes' : 'no' },
        { label: 'Security version', value: (row) => row.security_version }
    ]);
});

$('btnCreateUser').addEventListener('click', async () => {
    await api('/api/v1/admin/users', 'POST', {
        login: $('newUserLogin').value.trim(),
        email: $('newUserEmail').value.trim(),
        password: $('newUserPassword').value,
        status: $('newUserStatus').value.trim() || 'active',
        mfa_required: $('newUserMfa').checked,
        reason: $('newUserReason').value.trim()
    });
});

$('btnUpdateUser').addEventListener('click', async () => {
    const body = {
        user_id: Number($('editUserId').value.trim()),
        reason: $('editUserReason').value.trim(),
        mfa_required: $('editUserMfa').checked
    };
    const status = $('editUserStatus').value.trim();
    const email = $('editUserEmail').value.trim();
    const password = $('editUserPassword').value;
    if (status) body.status = status;
    if (email) body.email = email;
    if (password) body.password = password;
    await api('/api/v1/admin/users', 'PATCH', body);
});

$('btnDeleteUser').addEventListener('click', async () => {
    await api('/api/v1/admin/users', 'DELETE', {
        user_id: Number($('editUserId').value.trim()),
        reason: $('editUserReason').value.trim()
    });
});

$('btnSessions').addEventListener('click', async () => {
    const res = await api('/api/v1/admin/sessions', 'GET');
    renderTable('sessionsTable', null, res.data.items || [], [
        { label: 'Session ID', value: (row) => row.id },
        { label: 'User', value: (row) => row.user_id },
        { label: 'AAL', value: (row) => row.aal },
        { label: 'MFA verified', value: (row) => row.mfa_verified_at || '-' },
        { label: 'Expires', value: (row) => row.expires_at },
        { label: 'Revoked', value: (row) => row.revoked_at || '-' }
    ]);
});

$('btnRevokeSession').addEventListener('click', async () => {
    await api('/api/v1/admin/sessions/revoke', 'POST', {
        session_id: $('revokeSessionId').value.trim(),
        reason: $('sessionReason').value.trim()
    });
});

$('btnBatchRevokeSessions').addEventListener('click', async () => {
    try {
        await api('/api/v1/admin/sessions/batch-revoke', 'POST', {
            reason: $('sessionReason').value.trim(),
            dry_run: $('sessionBatchDryRun').checked,
            session_ids: parseJsonField('sessionBatchIds', [])
        });
    } catch (e) {
        print({ ok: false, error: 'Invalid JSON in session IDs' }, 0);
    }
});

$('btnMyPermissions').addEventListener('click', () => api('/api/v1/me/permissions', 'GET'));
$('btnMyAccess').addEventListener('click', () => api('/api/v1/me/access', 'GET'));
$('btnRoles').addEventListener('click', () => api('/api/v1/admin/roles', 'GET'));
$('btnPermissions').addEventListener('click', () => api('/api/v1/admin/permissions', 'GET'));

$('btnCreateRole').addEventListener('click', async () => {
    await api('/api/v1/admin/roles', 'POST', {
        code: $('customRoleCode').value.trim(),
        name: $('customRoleName').value.trim(),
        reason: $('customRoleReason').value.trim()
    });
});

$('btnUpdateRole').addEventListener('click', async () => {
    await api('/api/v1/admin/roles', 'PATCH', {
        code: $('customRoleCode').value.trim(),
        name: $('customRoleName').value.trim(),
        reason: $('customRoleReason').value.trim()
    });
});

$('btnDeleteRole').addEventListener('click', async () => {
    await api('/api/v1/admin/roles', 'DELETE', {
        code: $('customRoleCode').value.trim(),
        reason: $('customRoleReason').value.trim()
    });
});

$('btnRolePermissions').addEventListener('click', async () => {
    const roleCode = $('permRoleCode').value.trim();
    await api('/api/v1/admin/role-permissions?role_code=' + encodeURIComponent(roleCode), 'GET');
});

$('btnReplaceRolePermissions').addEventListener('click', async () => {
    try {
        await api('/api/v1/admin/role-permissions', 'PUT', {
            role_code: $('permRoleCode').value.trim(),
            permissions: parseJsonField('permRoleItems', []),
            reason: $('permRoleReason').value.trim()
        });
    } catch (e) {
        print({ ok: false, error: 'Invalid JSON in role permissions' }, 0);
    }
});

$('btnUserRoles').addEventListener('click', async () => {
    const userId = $('targetUserId').value.trim();
    await api('/api/v1/admin/user-roles?user_id=' + encodeURIComponent(userId), 'GET');
});

$('btnEffectiveAccess').addEventListener('click', async () => {
    const userId = $('targetUserId').value.trim();
    await api('/api/v1/admin/effective-access?user_id=' + encodeURIComponent(userId), 'GET');
});

$('btnAssignRole').addEventListener('click', async () => {
    await api('/api/v1/admin/user-roles/assign', 'POST', {
        user_id: Number($('targetUserId').value.trim()),
        role_code: $('targetRoleCode').value.trim(),
        reason: $('roleReason').value.trim()
    });
});

$('btnRevokeRole').addEventListener('click', async () => {
    await api('/api/v1/admin/user-roles/revoke', 'POST', {
        user_id: Number($('targetUserId').value.trim()),
        role_code: $('targetRoleCode').value.trim(),
        reason: $('roleReason').value.trim()
    });
});

$('btnBatchRoles').addEventListener('click', async () => {
    try {
        await api('/api/v1/admin/user-roles/batch', 'POST', {
            reason: $('batchReason').value.trim(),
            dry_run: $('batchDryRun').checked,
            items: parseJsonField('batchItems', [])
        });
    } catch (e) {
        print({ ok: false, error: 'Invalid JSON in batch items' }, 0);
    }
});

$('btnLoadPolicies').addEventListener('click', async () => {
    const res = await api('/api/v1/admin/policies', 'GET');
    if (res.data && res.data.rules) {
        const rules = res.data.rules;
        $('policyAllowedIps').value = Array.isArray(rules.allowed_ips) ? rules.allowed_ips.join(',') : '';
        $('policyHourStart').value = Number(rules.allowed_hour_start_utc ?? 0);
        $('policyHourEnd').value = Number(rules.allowed_hour_end_utc ?? 23);
        $('policyRequireStepUp').checked = Boolean(rules.require_step_up);
    }
});

$('btnSavePolicies').addEventListener('click', async () => {
    const rawIps = $('policyAllowedIps').value.trim();
    await api('/api/v1/admin/policies', 'POST', {
        reason: $('policyReason').value.trim(),
        allowed_ips: rawIps === '' ? [] : rawIps.split(',').map((value) => value.trim()).filter(Boolean),
        allowed_hour_start_utc: Number($('policyHourStart').value),
        allowed_hour_end_utc: Number($('policyHourEnd').value),
        require_step_up: $('policyRequireStepUp').checked
    });
});

$('btnBatchUserStatus').addEventListener('click', async () => {
    try {
        await api('/api/v1/admin/users/batch-status', 'POST', {
            reason: $('userStatusReason').value.trim(),
            dry_run: $('userStatusDryRun').checked,
            items: parseJsonField('userStatusItems', [])
        });
    } catch (e) {
        print({ ok: false, error: 'Invalid JSON in user status items' }, 0);
    }
});

async function loadPdComplianceStatus() {
    const res = await api('/api/v1/admin/personal-data/compliance-status', 'GET');
    $('pdComplianceStatus').textContent = JSON.stringify(res.data.compliance || res.data, null, 2);
}

$('btnPdComplianceStatus').addEventListener('click', loadPdComplianceStatus);

$('btnPdLoadProfile').addEventListener('click', async () => {
    const res = await api('/api/v1/admin/personal-data/operator-profile', 'GET');
    const p = res.data.profile || {};
    $('pdOperatorName').value = p.operator_name || '';
    $('pdOperatorInn').value = p.operator_inn || '';
    $('pdPrivacyUrl').value = p.privacy_policy_url || '';
    $('pdDpoName').value = p.dpo_name || '';
    $('pdDpoEmail').value = p.dpo_email || '';
    $('pdStorageRegion').value = p.data_storage_region || 'RU';
    $('pdRknDate').value = p.roskomnadzor_notified_at || '';
});

$('btnPdSaveProfile').addEventListener('click', async () => {
    await api('/api/v1/admin/personal-data/operator-profile', 'PUT', {
        operator_name: $('pdOperatorName').value.trim(),
        operator_inn: $('pdOperatorInn').value.trim(),
        privacy_policy_url: $('pdPrivacyUrl').value.trim(),
        dpo_name: $('pdDpoName').value.trim(),
        dpo_email: $('pdDpoEmail').value.trim(),
        data_storage_region: $('pdStorageRegion').value.trim() || 'RU',
        roskomnadzor_notified_at: $('pdRknDate').value || null,
    });
    await loadPdComplianceStatus();
});

$('btnPdDpaAck').addEventListener('click', async () => {
    await api('/api/v1/admin/personal-data/dpa-acknowledge', 'POST', {});
    await loadPdComplianceStatus();
});

$('btnPdRequests').addEventListener('click', async () => {
    const res = await api('/api/v1/admin/personal-data/subject-requests', 'GET');
    const items = res.data.items || [];
    renderTable('pdRequestsTable', 'pdRequestsEmpty', items, [
        { label: 'ID', value: (row) => row.id },
        { label: 'User', value: (row) => row.user_id },
        { label: 'Type', value: (row) => row.request_type },
        { label: 'Status', value: (row) => row.status },
        { label: 'Due', value: (row) => row.due_at },
        { label: 'Created', value: (row) => row.created_at },
    ]);
});

$('btnPdResolve').addEventListener('click', async () => {
    await api('/api/v1/admin/personal-data/subject-requests/resolve', 'POST', {
        request_id: Number($('pdResolveId').value.trim()),
        status: $('pdResolveStatus').value,
        handler_note: $('pdResolveNote').value.trim(),
    });
});

$('btnGrantPeers')?.addEventListener('click', async () => {
    const out = $('grantPeersOut');
    out.textContent = 'Загрузка…';
    try {
        const res = await fetch('/warehouses/api/v1/delegation/grant-peers', {
            headers: { Accept: 'application/json', Authorization: 'Bearer ' + (accessToken || '') },
        });
        const data = await res.json();
        out.textContent = JSON.stringify(data, null, 2);
    } catch (e) {
        out.textContent = String(e);
    }
});

$('btnAuditExport').addEventListener('click', async () => {
    await api('/api/v1/admin/audit/export?limit=5000', 'GET');
});

$('btnAudit').addEventListener('click', async () => {
    const res = await api('/api/v1/admin/audit', 'GET');
    renderTable('auditTable', 'auditEmpty', res.data.items || [], [
        { label: 'ID', value: (row) => row.id },
        { label: 'Event', value: (row) => row.event_type },
        { label: 'Actor', value: (row) => row.actor_user_id || '-' },
        { label: 'Correlation', value: (row) => row.correlation_id || '-' },
        { label: 'Created', value: (row) => row.created_at }
    ]);
});

$('btnSecEvents').addEventListener('click', async () => {
    const res = await api('/api/v1/admin/security-events', 'GET');
    renderTable('secEventsTable', 'secEventsEmpty', res.data.items || [], [
        { label: 'ID', value: (row) => row.id },
        { label: 'Event', value: (row) => row.event_type },
        { label: 'Severity', value: (row) => row.severity },
        { label: 'Correlation', value: (row) => row.correlation_id || '-' },
        { label: 'Created', value: (row) => row.created_at }
    ]);
});

updateAuthState();
if (accessToken) {
    refreshOpsSummary();
}
</script>
</body>
</html>
