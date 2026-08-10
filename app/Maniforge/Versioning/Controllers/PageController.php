<?php
declare(strict_types=1);

namespace App\Maniforge\Versioning\Controllers;

final class PageController
{
    public function admin(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $apiBase = '/versioning/api/v1';
        ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Versioning — история изменений</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .ver-toolbar { display:flex; flex-wrap:wrap; gap:.75rem; margin-bottom:1rem; align-items:end; }
        .ver-toolbar label { display:flex; flex-direction:column; gap:.25rem; font-size:.82rem; font-weight:700; color:var(--app-muted); }
        .ver-toolbar input, .ver-toolbar select { min-width:10rem; padding:.45rem .6rem; border:1px solid var(--app-border-strong); border-radius:var(--app-radius-sm); }
        .ver-table { width:100%; border-collapse:collapse; font-size:.88rem; }
        .ver-table th, .ver-table td { border-bottom:1px solid var(--app-border); padding:.55rem .45rem; text-align:left; vertical-align:top; }
        .ver-table th { font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; color:var(--app-muted); }
        .ver-op { font-family:monospace; font-weight:700; }
        .ver-json { font-family:monospace; font-size:.75rem; white-space:pre-wrap; word-break:break-all; max-height:8rem; overflow:auto; background:#fff; border:1px solid var(--app-border); border-radius:.4rem; padding:.45rem; }
        .ver-empty { color:var(--app-muted); padding:1rem 0; }
    </style>
</head>
<body>
<main class="app-shell app-main" style="max-width:1180px;margin:0 auto;padding:1.5rem 1rem 3rem;">
    <section class="app-page-head">
        <span class="app-kicker">Maniforge Versioning</span>
        <h1 class="app-title h2">История изменений записей</h1>
        <p class="app-lead mb-0">Журнал версий по таблицам в scope текущей сессии. Требуется Bearer access_token и permission <code>versioning.read</code>.</p>
    </section>

    <section class="app-panel mt-4">
        <div class="ver-toolbar">
            <label>Таблица
                <select id="verTable"><option value="">Все</option></select>
            </label>
            <label>ID записи
                <input id="verEntityId" type="text" placeholder="123">
            </label>
            <label>Операция
                <select id="verOperation">
                    <option value="">Все</option>
                    <option value="insert">insert</option>
                    <option value="update">update</option>
                    <option value="delete">delete</option>
                </select>
            </label>
            <button type="button" class="app-button" id="verReload">Обновить</button>
        </div>
        <div id="verStatus" class="app-muted small mb-2"></div>
        <div class="app-api-table-wrap">
            <table class="ver-table">
                <thead>
                    <tr>
                        <th>Когда</th>
                        <th>Таблица</th>
                        <th>Запись</th>
                        <th>Операция</th>
                        <th>До / После</th>
                    </tr>
                </thead>
                <tbody id="verBody"></tbody>
            </table>
        </div>
    </section>
</main>
<script>
(function () {
    const STORAGE = { access: 'maniforge_admin_access_token' };
    const apiBase = <?= json_encode($apiBase, JSON_UNESCAPED_UNICODE) ?>;

    function token() {
        return localStorage.getItem(STORAGE.access) || '';
    }

    async function api(path) {
        const response = await fetch(apiBase + path, {
            headers: {
                Accept: 'application/json',
                Authorization: 'Bearer ' + token(),
            },
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || ('HTTP ' + response.status));
        }
        return payload;
    }

    function esc(text) {
        return String(text ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function renderJson(value) {
        if (value == null) return '—';
        return '<pre class="ver-json">' + esc(JSON.stringify(value, null, 2)) + '</pre>';
    }

    async function loadRegistry() {
        const payload = await api('/registry');
        const select = document.getElementById('verTable');
        payload.items.forEach((item) => {
            const opt = document.createElement('option');
            opt.value = item.entity_table;
            opt.textContent = item.entity_label + ' (' + item.entity_table + ')';
            select.appendChild(opt);
        });
    }

    async function loadChanges() {
        const params = new URLSearchParams();
        const table = document.getElementById('verTable').value;
        const entityId = document.getElementById('verEntityId').value.trim();
        const operation = document.getElementById('verOperation').value;
        if (table) params.set('entity_table', table);
        if (entityId) params.set('entity_id', entityId);
        if (operation) params.set('operation', operation);
        params.set('limit', '100');

        const payload = await api('/changes?' + params.toString());
        const body = document.getElementById('verBody');
        body.innerHTML = '';
        if (!payload.items.length) {
            body.innerHTML = '<tr><td colspan="5" class="ver-empty">Изменений не найдено</td></tr>';
        } else {
            payload.items.forEach((row) => {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + esc(row.changed_at) + '</td>' +
                    '<td><code>' + esc(row.entity_table) + '</code></td>' +
                    '<td><code>' + esc(row.entity_id) + '</code>' +
                        (row.entity_label ? '<div class="app-muted small">' + esc(row.entity_label) + '</div>' : '') +
                    '</td>' +
                    '<td class="ver-op">' + esc(row.operation) + '</td>' +
                    '<td><div class="small app-muted">до</div>' + renderJson(row.before) +
                        '<div class="small app-muted mt-1">после</div>' + renderJson(row.after) + '</td>';
                body.appendChild(tr);
            });
        }
        document.getElementById('verStatus').textContent =
            'Показано ' + payload.items.length + ' из ' + payload.total;
    }

    async function init() {
        if (!token()) {
            document.getElementById('verStatus').textContent =
                'Нет access_token в localStorage (maniforge_admin_access_token). Войдите через /login или admin.';
            return;
        }
        try {
            await loadRegistry();
            await loadChanges();
        } catch (e) {
            document.getElementById('verStatus').textContent = e.message || String(e);
        }
    }

    document.getElementById('verReload').addEventListener('click', () => {
        loadChanges().catch((e) => {
            document.getElementById('verStatus').textContent = e.message || String(e);
        });
    });
    init();
})();
</script>
</body>
</html>
        <?php
    }
}
