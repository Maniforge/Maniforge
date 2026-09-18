<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maniforge RBAC • API Reference</title>
    <link href="/assets/css/app.css" rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
            --bg: var(--app-bg);
            --surface: var(--app-surface);
            --surface-soft: var(--app-surface-soft);
            --line: var(--app-border);
            --line-strong: var(--app-border-strong);
            --text: var(--app-text);
            --muted: var(--app-muted);
            --blue: var(--app-primary);
            --blue-soft: var(--app-primary-soft);
            --green: var(--app-success);
            --green-soft: #e8f7ef;
            --orange: var(--app-warning);
            --orange-soft: #fff7e6;
            --red: var(--app-danger);
            --red-soft: #fff0f3;
            --code: #0b1220;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        a { color: var(--blue); text-decoration: none; }
        a:hover { text-decoration: underline; }
        code {
            padding: 2px 6px;
            border-radius: 6px;
            background: #eef4ff;
            color: #174ea6;
            font-size: 13px;
        }
        pre {
            margin: 0;
            padding: 16px;
            overflow: auto;
            border-radius: 12px;
            background: var(--code);
            color: #dbeafe;
            line-height: 1.55;
            font-size: 13px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface);
        }
        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }
        th {
            color: #40516b;
            background: var(--surface-soft);
            font-size: 13px;
        }
        tr:last-child td { border-bottom: 0; }
        .layout {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            min-height: 100vh;
        }
        .sidebar {
            position: sticky;
            top: 0;
            align-self: start;
            height: 100vh;
            overflow: auto;
            padding: 22px 18px;
            border-right: 1px solid var(--line);
            background: var(--surface);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            color: var(--text);
            text-decoration: none;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: var(--blue);
            color: #fff;
            font-weight: 800;
        }
        .brand b { display: block; font-size: 16px; }
        .brand span { display: block; color: var(--muted); font-size: 12px; }
        .side-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 18px;
        }
        .side-actions a {
            padding: 9px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--surface-soft);
            color: var(--text);
            font-size: 13px;
            text-align: center;
        }
        .nav-title {
            margin: 20px 0 8px;
            color: #7a8799;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .nav-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 10px;
            border-radius: 10px;
            color: #31415a;
            font-size: 14px;
        }
        .nav-link:hover {
            background: var(--blue-soft);
            color: var(--blue);
            text-decoration: none;
        }
        .nav-link small { color: var(--muted); font-size: 11px; }
        .content {
            max-width: 1240px;
            width: 100%;
            padding: 28px 34px 56px;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: center;
            margin: -28px -34px 28px;
            padding: 14px 34px;
            border-bottom: 1px solid var(--line);
            background: rgba(244, 247, 251, .92);
            backdrop-filter: blur(14px);
        }
        .search {
            width: 100%;
            min-height: 42px;
            padding: 0 14px;
            border: 1px solid var(--line-strong);
            border-radius: 12px;
            outline: none;
            background: var(--surface);
            color: var(--text);
            font-size: 14px;
        }
        .download {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 12px;
            background: var(--blue);
            color: #fff;
            font-weight: 700;
            white-space: nowrap;
        }
        .download:hover { text-decoration: none; background: #0049cc; }
        .hero {
            padding: 30px;
            border: 1px solid var(--line);
            border-radius: 22px;
            background:
                radial-gradient(circle at 88% 18%, rgba(0, 91, 255, .16), transparent 18rem),
                var(--surface);
        }
        .eyebrow {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 13px;
            font-weight: 700;
        }
        h1 {
            max-width: 760px;
            margin: 18px 0 10px;
            font-size: clamp(34px, 4vw, 56px);
            line-height: 1.02;
            letter-spacing: -.04em;
        }
        h2 {
            margin: 0 0 10px;
            font-size: 28px;
            letter-spacing: -.03em;
        }
        h3 {
            margin: 0 0 10px;
            font-size: 19px;
            letter-spacing: -.02em;
        }
        .lead {
            max-width: 780px;
            margin: 0;
            color: var(--muted);
            font-size: 17px;
            line-height: 1.7;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 22px;
        }
        .meta-card, .section, .endpoint-card, .callout {
            border: 1px solid var(--line);
            border-radius: 18px;
            background: var(--surface);
        }
        .meta-card { padding: 16px; }
        .meta-card b {
            display: block;
            margin-bottom: 4px;
            font-size: 22px;
        }
        .meta-card span { color: var(--muted); font-size: 13px; }
        .section {
            margin-top: 24px;
            padding: 24px;
        }
        .section-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 18px;
        }
        .section-head p {
            max-width: 720px;
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }
        .badge.get { background: var(--green-soft); color: var(--green); }
        .badge.post { background: var(--blue-soft); color: var(--blue); }
        .badge.secure { background: var(--orange-soft); color: var(--orange); }
        .badge.error { background: var(--red-soft); color: var(--red); }
        .badge.gray { background: #eef2f7; color: #526176; }
        .endpoint-card {
            margin-top: 14px;
            overflow: hidden;
        }
        .endpoint-head {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid var(--line);
            background: var(--surface-soft);
        }
        .endpoint-head code {
            overflow-wrap: anywhere;
            background: transparent;
            color: var(--text);
            font-size: 15px;
            padding: 0;
        }
        .endpoint-body {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 420px;
            gap: 0;
        }
        .endpoint-info {
            padding: 18px;
            border-right: 1px solid var(--line);
        }
        .endpoint-info p {
            margin: 0 0 14px;
            color: var(--muted);
            line-height: 1.6;
        }
        .code-panel {
            display: grid;
            gap: 12px;
            padding: 18px;
            background: #f7faff;
        }
        .code-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            color: #40516b;
            font-size: 13px;
            font-weight: 800;
        }
        .copy-btn {
            border: 1px solid var(--line-strong);
            border-radius: 9px;
            padding: 5px 8px;
            background: var(--surface);
            color: #40516b;
            cursor: pointer;
            font-size: 12px;
        }
        .copy-btn:hover { border-color: var(--blue); color: var(--blue); }
        .params {
            display: grid;
            gap: 10px;
        }
        .param {
            display: grid;
            grid-template-columns: 160px 92px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface);
        }
        .param b { overflow-wrap: anywhere; }
        .param span { color: var(--muted); font-size: 13px; }
        .callouts {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        .callout {
            padding: 16px;
            line-height: 1.55;
        }
        .callout b { display: block; margin-bottom: 8px; }
        .callout p { margin: 0; color: var(--muted); }
        .schemas {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .hidden { display: none; }
        @media (max-width: 1080px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar {
                position: static;
                height: auto;
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }
            .endpoint-body { grid-template-columns: 1fr; }
            .endpoint-info { border-right: 0; border-bottom: 1px solid var(--line); }
        }
        @media (max-width: 720px) {
            .content { padding: 20px 14px 36px; }
            .topbar {
                grid-template-columns: 1fr;
                margin: -20px -14px 20px;
                padding: 12px 14px;
            }
            .hero, .section { padding: 18px; }
            .meta-grid, .callouts { grid-template-columns: 1fr; }
            .endpoint-head { grid-template-columns: 1fr; }
            .param { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="rbac-docs">
<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="/rbac/api-docs">
            <span class="logo">Maniforge</span>
            <span>
                <b>RBAC API</b>
                <span>Reference guide</span>
            </span>
        </a>
        <div class="side-actions">
            <a href="/rbac/admin">Консоль</a>
            <a href="/rbac/health">Health</a>
        </div>

        <div class="nav-title">Начало</div>
        <a class="nav-link" href="#overview">Обзор <small>base</small></a>
        <a class="nav-link" href="#auth">Авторизация <small>token</small></a>
        <a class="nav-link" href="#security">Безопасность <small>policy</small></a>

        <div class="nav-title">Методы</div>
        <a class="nav-link" href="#method-login">Login <small>POST</small></a>
        <a class="nav-link" href="#method-reauth">Step-up reauth <small>POST</small></a>
        <a class="nav-link" href="#method-access">My access <small>GET</small></a>
        <a class="nav-link" href="#method-password">Change password <small>POST</small></a>
        <a class="nav-link" href="#method-user-roles-batch">Role batch <small>POST</small></a>
        <a class="nav-link" href="#method-sessions-batch">Session batch <small>POST</small></a>
        <a class="nav-link" href="#method-status-batch">Status batch <small>POST</small></a>
        <a class="nav-link" href="#method-policies">Policies <small>GET/POST</small></a>

        <div class="nav-title">Справочник</div>
        <a class="nav-link" href="#errors">Ошибки <small>4xx</small></a>
        <a class="nav-link" href="#schemas">Схемы <small>OpenAPI</small></a>
    </aside>

    <main class="content">
        <div class="topbar">
            <input id="apiSearch" class="search" placeholder="Поиск по методам: login, batch, policy, password..." aria-label="Поиск по API">
            <a class="download" href="/rbac/api-docs/openapi.yaml">Скачать OpenAPI YAML</a>
        </div>

        <section id="overview" class="hero">
            <span class="eyebrow">OpenAPI 0.2.0 • /rbac • JSON API</span>
            <h1>API описание Maniforge RBAC</h1>
            <p class="lead">
                Справочник по auth, sessions, permissions, role mutations и policy rules.
                Страница оформлена как рабочая документация: назначение метода, параметры,
                требования безопасности, примеры запросов и ожидаемые ответы.
            </p>
            <div class="meta-grid">
                <div class="meta-card"><b>25</b><span>маршрутов в OpenAPI</span></div>
                <div class="meta-card"><b>Bearer</b><span>opaque access token</span></div>
                <div class="meta-card"><b>CSRF</b><span>для state-changing методов</span></div>
                <div class="meta-card"><b>Audit</b><span>reason для mutations</span></div>
            </div>
        </section>

        <section id="auth" class="section">
            <div class="section-head">
                <div>
                    <h2>Авторизация</h2>
                    <p>После login клиент хранит access token, refresh token и CSRF token. Access token передается в `Authorization: Bearer ...`, CSRF token - в `X-CSRF-Token` для POST-запросов.</p>
                </div>
                <span class="badge secure">Bearer + CSRF</span>
            </div>
            <div class="callouts">
                <div class="callout"><b>Access token</b><p>Используется для всех защищенных `GET/POST` методов.</p></div>
                <div class="callout"><b>Refresh token</b><p>Ротируется через `/api/v1/auth/refresh` и выдает новую session pair.</p></div>
                <div class="callout"><b>Step-up</b><p>Нужен для чувствительных операций: password change и admin policy контуров.</p></div>
            </div>
        </section>

        <section id="security" class="section">
            <div class="section-head">
                <div>
                    <h2>Security contract</h2>
                    <p>Admin endpoints работают по принципу deny-by-default: session auth, role gate, permission gate, DB/env policy rules и fresh step-up при необходимости.</p>
                </div>
                <span class="badge secure">deny by default</span>
            </div>
            <table>
                <thead><tr><th>Проверка</th><th>Где применяется</th><th>Что возвращает при отказе</th></tr></thead>
                <tbody>
                <tr><td>Bearer token</td><td>Все `/me/*` и `/admin/*`</td><td><code>401 Не авторизован</code></td></tr>
                <tr><td>Role + permission gate</td><td>Все `/admin/*`</td><td><code>403 Недостаточно прав/permissions</code></td></tr>
                <tr><td>Policy rules</td><td>Admin IP/time-window/step-up</td><td><code>403</code> с причиной policy fail</td></tr>
                <tr><td>Reason enforcement</td><td>Role/session/status mutations</td><td><code>422</code>, если reason пустой</td></tr>
                </tbody>
            </table>
        </section>

        <section class="section api-group" data-search="login auth csrf token">
            <div class="section-head">
                <div>
                    <h2>Auth methods</h2>
                    <p>Базовые методы входа, обновления токенов и подтверждения чувствительных действий.</p>
                </div>
                <span class="badge post">POST</span>
            </div>

            <article id="method-login" class="endpoint-card" data-search="login auth access refresh csrf">
                <div class="endpoint-head">
                    <span class="badge post">POST</span>
                    <code>/api/v1/auth/login</code>
                    <span class="badge gray">public</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Логин пользователя</h3>
                        <p>Создает session, выдает access token, refresh token и CSRF token. Login защищен brute-force lockout.</p>
                        <div class="params">
                            <div class="param"><b>login</b><span>string, required</span><span>Логин пользователя в tenant/subtenant scope.</span></div>
                            <div class="param"><b>password</b><span>string, required</span><span>Пароль пользователя.</span></div>
                        </div>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">Request <button class="copy-btn" data-copy="loginReq">copy</button></div>
                        <pre id="loginReq">{
  "login": "admin",
  "password": "secret"
}</pre>
                        <div class="code-title">200 Response</div>
                        <pre>{
  "ok": true,
  "session": {
    "access_token": "...",
    "refresh_token": "...",
    "expires_in": 43200
  },
  "csrf_token": "..."
}</pre>
                    </div>
                </div>
            </article>

            <article id="method-reauth" class="endpoint-card" data-search="reauth step-up password sensitive">
                <div class="endpoint-head">
                    <span class="badge post">POST</span>
                    <code>/api/v1/auth/reauth</code>
                    <span class="badge secure">Bearer + CSRF</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Step-up reauth</h3>
                        <p>Подтверждает пароль текущего пользователя и делает session пригодной для чувствительных операций в течение окна `RBAC_MFA_STEPUP_MAX_AGE_SEC`.</p>
                        <div class="params">
                            <div class="param"><b>password</b><span>string, required</span><span>Текущий пароль пользователя.</span></div>
                        </div>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">Headers</div>
                        <pre>Authorization: Bearer &lt;access_token&gt;
X-CSRF-Token: &lt;csrf_token&gt;</pre>
                        <div class="code-title">Request</div>
                        <pre>{
  "password": "secret"
}</pre>
                    </div>
                </div>
            </article>
        </section>

        <section class="section api-group" data-search="me access permissions password current user">
            <div class="section-head">
                <div>
                    <h2>Current user</h2>
                    <p>Методы для чтения текущей session/access информации и безопасной смены пароля.</p>
                </div>
                <span class="badge get">GET/POST</span>
            </div>

            <article id="method-access" class="endpoint-card" data-search="me access roles permissions">
                <div class="endpoint-head">
                    <span class="badge get">GET</span>
                    <code>/api/v1/me/access</code>
                    <span class="badge secure">Bearer</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Эффективный доступ текущего пользователя</h3>
                        <p>Возвращает роли и permissions с учетом назначений в текущем scope.</p>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">200 Response</div>
                        <pre>{
  "ok": true,
  "access": {
    "roles": ["tenant_admin"],
    "permissions": ["admin.users.read", "admin.user_roles.bulk"]
  }
}</pre>
                    </div>
                </div>
            </article>

            <article id="method-password" class="endpoint-card" data-search="password security change logout all step-up">
                <div class="endpoint-head">
                    <span class="badge post">POST</span>
                    <code>/api/v1/me/security/password</code>
                    <span class="badge secure">Bearer + CSRF + step-up</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Смена пароля</h3>
                        <p>Требует fresh step-up. После успешной смены password history обновляется, security version повышается, все session/refresh tokens пользователя отзываются.</p>
                        <div class="params">
                            <div class="param"><b>current_password</b><span>string, required</span><span>Текущий пароль.</span></div>
                            <div class="param"><b>new_password</b><span>string, required</span><span>Новый пароль с учетом password policy.</span></div>
                        </div>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">Request</div>
                        <pre>{
  "current_password": "old-secret",
  "new_password": "new-strong-secret"
}</pre>
                    </div>
                </div>
            </article>
        </section>

        <section class="section api-group" data-search="admin batch roles sessions status policies">
            <div class="section-head">
                <div>
                    <h2>Admin batch methods</h2>
                    <p>Все batch методы поддерживают `dry_run`, требуют `reason` и ограничиваются `RBAC_BATCH_MAX_ITEMS`.</p>
                </div>
                <span class="badge secure">admin gate</span>
            </div>

            <article id="method-user-roles-batch" class="endpoint-card" data-search="batch user roles assign revoke reason dry_run guard">
                <div class="endpoint-head">
                    <span class="badge post">POST</span>
                    <code>/api/v1/admin/user-roles/batch</code>
                    <span class="badge secure">admin.user_roles.bulk</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Batch assign/revoke ролей</h3>
                        <p>Атомарно назначает или снимает роли. Guard блокирует self-escalation, self-demotion privileged ролей и снятие последнего scope-admin.</p>
                        <div class="params">
                            <div class="param"><b>reason</b><span>string, required</span><span>Причина изменения для audit trail.</span></div>
                            <div class="param"><b>dry_run</b><span>boolean</span><span>Выполнить проверки и вернуть summary без записи в БД.</span></div>
                            <div class="param"><b>items[].action</b><span>assign|revoke</span><span>Тип изменения роли.</span></div>
                        </div>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">Request <button class="copy-btn" data-copy="roleBatchReq">copy</button></div>
                        <pre id="roleBatchReq">{
  "reason": "quarterly access review",
  "dry_run": true,
  "items": [
    { "user_id": 12, "role_code": "security_auditor", "action": "assign" },
    { "user_id": 18, "role_code": "support_operator", "action": "revoke" }
  ]
}</pre>
                        <div class="code-title">200 Response</div>
                        <pre>{
  "ok": true,
  "dry_run": true,
  "summary": {
    "assigned": 1,
    "revoked": 1,
    "skipped": 0,
    "total": 2
  }
}</pre>
                    </div>
                </div>
            </article>

            <article id="method-sessions-batch" class="endpoint-card" data-search="batch sessions revoke incident reason dry_run">
                <div class="endpoint-head">
                    <span class="badge post">POST</span>
                    <code>/api/v1/admin/sessions/batch-revoke</code>
                    <span class="badge secure">admin.sessions.bulk</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Batch revoke sessions</h3>
                        <p>Массово отзывает активные sessions в текущем scope. Удобно для incident response и принудительного logout.</p>
                        <div class="params">
                            <div class="param"><b>session_ids</b><span>string[], required</span><span>ID сессий для отзыва.</span></div>
                            <div class="param"><b>reason</b><span>string, required</span><span>Причина отзыва.</span></div>
                        </div>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">Request</div>
                        <pre>{
  "reason": "security incident",
  "dry_run": false,
  "session_ids": ["session-id-1", "session-id-2"]
}</pre>
                        <div class="code-title">200 Response</div>
                        <pre>{
  "ok": true,
  "summary": {
    "revoked": 2,
    "skipped": 0,
    "total": 2
  }
}</pre>
                    </div>
                </div>
            </article>

            <article id="method-status-batch" class="endpoint-card" data-search="batch users status locked disabled active dry_run">
                <div class="endpoint-head">
                    <span class="badge post">POST</span>
                    <code>/api/v1/admin/users/batch-status</code>
                    <span class="badge secure">admin.users.status.bulk</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Batch user status changes</h3>
                        <p>Меняет статусы пользователей на `active`, `locked` или `disabled` в одной транзакции.</p>
                        <div class="params">
                            <div class="param"><b>items[].status</b><span>enum</span><span>`active`, `locked` или `disabled`.</span></div>
                            <div class="param"><b>reason</b><span>string, required</span><span>Причина блокировки/разблокировки.</span></div>
                        </div>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">Request</div>
                        <pre>{
  "reason": "policy enforcement",
  "dry_run": true,
  "items": [
    { "user_id": 12, "status": "locked" }
  ]
}</pre>
                        <div class="code-title">200 Response</div>
                        <pre>{
  "ok": true,
  "dry_run": true,
  "summary": {
    "changed": 1,
    "skipped": 0,
    "not_found": 0,
    "total": 1
  }
}</pre>
                    </div>
                </div>
            </article>
        </section>

        <section id="method-policies" class="section api-group" data-search="policies policy rules ip time window step-up">
            <div class="section-head">
                <div>
                    <h2>Policy rules</h2>
                    <p>DB-backed правила для admin-действий: IP allowlist, UTC time window и обязательность step-up.</p>
                </div>
                <span class="badge secure">admin.policies.*</span>
            </div>
            <article class="endpoint-card">
                <div class="endpoint-head">
                    <span class="badge get">GET</span>
                    <code>/api/v1/admin/policies</code>
                    <span class="badge secure">admin.policies.read</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Получить effective policy rules</h3>
                        <p>Возвращает DB rule для scope или fallback из env, если DB правило отсутствует.</p>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">200 Response</div>
                        <pre>{
  "ok": true,
  "rules": {
    "source": "db",
    "allowed_ips": ["127.0.0.1"],
    "allowed_hour_start_utc": 0,
    "allowed_hour_end_utc": 23,
    "require_step_up": true
  }
}</pre>
                    </div>
                </div>
            </article>
            <article class="endpoint-card">
                <div class="endpoint-head">
                    <span class="badge post">POST</span>
                    <code>/api/v1/admin/policies</code>
                    <span class="badge secure">admin.policies.update</span>
                </div>
                <div class="endpoint-body">
                    <div class="endpoint-info">
                        <h3>Обновить policy rules</h3>
                        <p>Валидирует IP, UTC окно и записывает изменение в audit/security events.</p>
                    </div>
                    <div class="code-panel">
                        <div class="code-title">Request</div>
                        <pre>{
  "reason": "restrict admin access",
  "allowed_ips": ["127.0.0.1", "10.0.0.10"],
  "allowed_hour_start_utc": 0,
  "allowed_hour_end_utc": 23,
  "require_step_up": true
}</pre>
                    </div>
                </div>
            </article>
        </section>

        <section id="errors" class="section">
            <div class="section-head">
                <div>
                    <h2>Ошибки</h2>
                    <p>Все ошибки возвращаются JSON-объектом с `ok: false` и полем `error`. Batch validation может дополнительно вернуть `item_index`.</p>
                </div>
                <span class="badge error">ErrorResponse</span>
            </div>
            <table>
                <thead><tr><th>HTTP</th><th>Когда возникает</th><th>Пример</th></tr></thead>
                <tbody>
                <tr><td><code>401</code></td><td>Нет или неверный bearer token</td><td><code>{"ok":false,"error":"Не авторизован"}</code></td></tr>
                <tr><td><code>403</code></td><td>Недостаточно ролей, permissions, step-up или policy guard</td><td><code>{"ok":false,"error":"Недостаточно permissions"}</code></td></tr>
                <tr><td><code>422</code></td><td>Неверный payload, пустой reason, превышен batch limit</td><td><code>{"ok":false,"error":"Неверный элемент batch","item_index":0}</code></td></tr>
                <tr><td><code>429</code></td><td>Login lockout после brute-force попыток</td><td><code>{"ok":false,"locked_until":"..."}</code></td></tr>
                </tbody>
            </table>
        </section>

        <section id="schemas" class="section">
            <div class="section-head">
                <div>
                    <h2>OpenAPI schemas</h2>
                    <p>Основные схемы уже вынесены в `components.schemas`, чтобы использовать их в клиентах, smoke-тестах и контрактных проверках.</p>
                </div>
                <span class="badge gray">components</span>
            </div>
            <div class="schemas">
                <span class="badge gray">LoginResponse</span>
                <span class="badge gray">RefreshResponse</span>
                <span class="badge gray">SessionToken</span>
                <span class="badge gray">SessionInfo</span>
                <span class="badge gray">AccessSnapshot</span>
                <span class="badge gray">RoleMutation</span>
                <span class="badge gray">RoleBatchSummary</span>
                <span class="badge gray">SessionBatchRevokeSummary</span>
                <span class="badge gray">UserStatusBatchSummary</span>
                <span class="badge gray">PolicyRules</span>
                <span class="badge gray">ErrorResponse</span>
            </div>
        </section>
    </main>
</div>

<script>
const search = document.getElementById('apiSearch');
const searchable = Array.from(document.querySelectorAll('.api-group, .endpoint-card'));
search.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    searchable.forEach((node) => {
        const haystack = (node.textContent + ' ' + (node.dataset.search || '')).toLowerCase();
        node.classList.toggle('hidden', query !== '' && !haystack.includes(query));
    });
});

document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copy);
        if (!target || !navigator.clipboard) {
            return;
        }
        await navigator.clipboard.writeText(target.textContent);
        const original = button.textContent;
        button.textContent = 'copied';
        setTimeout(() => { button.textContent = original; }, 1200);
    });
});
</script>
</body>
</html>
