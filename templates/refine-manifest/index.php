<?php
declare(strict_types=1);

$branding = require dirname(__DIR__) . '/data/branding.php';
$pageTitle = 'Manifest UI — ' . $branding['app_name'];
$activeNav = 'admin';
$meBase = rtrim((string) ($_ENV['MANIFORGE_MANIFEST_ENGINE_URL'] ?? 'http://127.0.0.1:8095'), '/');
require dirname(__DIR__) . '/layout/header.php';
?>
<section class="app-page-head app-page-head-dark app-main-wide product-hero">
    <span class="app-kicker">Manifest Engine</span>
    <h1 class="app-title h2">Refine UI scaffold</h1>
    <p class="app-lead mb-0">
        Браузерный CRUD по manifest без сборки React. Токен — из сессии RBAC
        (<code>maniforge_admin_access_token</code>). Экспорт Refine-проекта:
        <code>make manifest-refine-gen</code> → <code>templates/refine-manifest/generated/</code>.
    </p>
</section>

<section class="app-panel app-main-wide mt-4">
    <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
        <div>
            <label class="form-label" for="mfEntity">Manifest</label>
            <select id="mfEntity" class="form-select app-field" style="min-width:14rem"></select>
        </div>
        <button type="button" class="app-button" id="mfReload">Обновить</button>
        <button type="button" class="app-button app-button-secondary" id="mfCreateBtn">Создать запись</button>
    </div>
    <div id="mfStatus" class="app-muted small mb-2" role="status"></div>
    <div class="app-api-table-wrap">
        <table class="table table-sm align-middle" id="mfTable">
            <thead id="mfHead"></thead>
            <tbody id="mfBody"></tbody>
        </table>
    </div>
    <p id="mfMeta" class="app-muted small mb-0"></p>
</section>

<div class="modal fade" id="mfModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="mfModalTitle">Запись</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <form id="mfForm" class="modal-body">
                <div id="mfFields"></div>
            </form>
            <div class="modal-footer">
                <button type="button" class="app-button app-button-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" form="mfForm" class="app-button" id="mfSaveBtn">Сохранить</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const ME_BASE = <?= json_encode($meBase, JSON_UNESCAPED_UNICODE) ?>;
    const STORAGE = { access: 'maniforge_admin_access_token' };
    let manifests = [];
    let currentManifest = null;
    let editId = null;

    function token() {
        return localStorage.getItem(STORAGE.access) || '';
    }

    async function api(method, path, body) {
        const opts = {
            method,
            headers: {
                Accept: 'application/json',
                Authorization: 'Bearer ' + token(),
            },
        };
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(ME_BASE + path, opts);
        const json = await res.json().catch(() => ({ ok: false }));
        if (!res.ok || !json.ok) {
            throw new Error(json.error || ('HTTP ' + res.status));
        }
        return json;
    }

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function setStatus(msg) {
        document.getElementById('mfStatus').textContent = msg || '';
    }

    async function loadManifests() {
        const json = await api('GET', '/api/v1/manifests');
        manifests = json.manifests || [];
        const sel = document.getElementById('mfEntity');
        sel.innerHTML = '';
        manifests.forEach((m) => {
            const opt = document.createElement('option');
            opt.value = m.code;
            opt.textContent = m.name + ' (' + m.code + ')';
            sel.appendChild(opt);
        });
        if (manifests.length) {
            currentManifest = manifests.find((m) => m.code === sel.value) || manifests[0];
        }
    }

    function fieldNames() {
        return (currentManifest?.fields || []).map((f) => f.name);
    }

    async function loadRecords() {
        if (!currentManifest) {
            setStatus('Нет manifest. Создайте через API или manifest-journey.');
            return;
        }
        const json = await api('GET', '/api/data/' + encodeURIComponent(currentManifest.code) + '?limit=50&offset=0');
        const fields = fieldNames();
        const head = document.getElementById('mfHead');
        head.innerHTML = '<tr><th>ID</th>' + fields.map((f) => '<th>' + esc(f) + '</th>').join('') +
            '<th></th></tr>';
        const body = document.getElementById('mfBody');
        body.innerHTML = '';
        (json.records || []).forEach((rec) => {
            const tr = document.createElement('tr');
            let cells = '<td><code>' + esc(rec.id) + '</code></td>';
            fields.forEach((f) => {
                const v = rec.data?.[f];
                cells += '<td>' + esc(typeof v === 'object' ? JSON.stringify(v) : v) + '</td>';
            });
            cells += '<td class="text-nowrap">' +
                '<button type="button" class="btn btn-sm btn-outline-primary mf-edit" data-id="' + rec.id + '">Изменить</button> ' +
                '<button type="button" class="btn btn-sm btn-outline-danger mf-del" data-id="' + rec.id + '">Удалить</button></td>';
            tr.innerHTML = cells;
            body.appendChild(tr);
        });
        const meta = json.meta || {};
        document.getElementById('mfMeta').textContent =
            'Показано ' + (meta.count ?? json.records?.length ?? 0) + ' из ' + (meta.total ?? '—');
        body.querySelectorAll('.mf-edit').forEach((btn) => {
            btn.addEventListener('click', () => openEdit(Number(btn.dataset.id), json.records));
        });
        body.querySelectorAll('.mf-del').forEach((btn) => {
            btn.addEventListener('click', () => removeRecord(Number(btn.dataset.id)));
        });
    }

    function openForm(title, values) {
        document.getElementById('mfModalTitle').textContent = title;
        const wrap = document.getElementById('mfFields');
        wrap.innerHTML = '';
        (currentManifest?.fields || []).forEach((f) => {
            const div = document.createElement('div');
            div.className = 'mb-3';
            const val = values?.[f.name] ?? '';
            const inputType = f.type === 'number' ? 'number' : (f.type === 'boolean' ? 'checkbox' : 'text');
            if (f.type === 'boolean') {
                div.innerHTML = '<div class="form-check"><input class="form-check-input" type="checkbox" id="mf_' + esc(f.name) + '" name="' + esc(f.name) + '"' +
                    (val ? ' checked' : '') + '><label class="form-check-label" for="mf_' + esc(f.name) + '">' + esc(f.name) + '</label></div>';
            } else {
                div.innerHTML = '<label class="form-label" for="mf_' + esc(f.name) + '">' + esc(f.name) +
                    (f.required ? ' *' : '') + '</label><input class="form-control app-field" id="mf_' + esc(f.name) + '" name="' + esc(f.name) + '" type="' + inputType + '" value="' + esc(val) + '">';
            }
            wrap.appendChild(div);
        });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('mfModal')).show();
    }

    function collectForm() {
        const data = {};
        (currentManifest?.fields || []).forEach((f) => {
            const el = document.querySelector('[name="' + f.name + '"]');
            if (!el) return;
            if (f.type === 'boolean') {
                data[f.name] = el.checked;
            } else if (f.type === 'number') {
                data[f.name] = el.value === '' ? null : Number(el.value);
            } else {
                data[f.name] = el.value;
            }
        });
        return data;
    }

    function openCreate() {
        editId = null;
        openForm('Создать — ' + (currentManifest?.name || ''), {});
    }

    function openEdit(id, records) {
        editId = id;
        const rec = (records || []).find((r) => r.id === id);
        openForm('Изменить #' + id, rec?.data || {});
    }

    async function saveRecord(ev) {
        ev.preventDefault();
        const data = collectForm();
        if (editId) {
            await api('PATCH', '/api/data/' + currentManifest.code + '/' + editId, data);
        } else {
            await api('POST', '/api/data/' + currentManifest.code, data);
        }
        bootstrap.Modal.getInstance(document.getElementById('mfModal'))?.hide();
        await loadRecords();
    }

    async function removeRecord(id) {
        if (!confirm('Удалить запись #' + id + '?')) return;
        await api('DELETE', '/api/data/' + currentManifest.code + '/' + id);
        await loadRecords();
    }

    document.getElementById('mfEntity').addEventListener('change', (e) => {
        currentManifest = manifests.find((m) => m.code === e.target.value) || null;
        loadRecords().catch((err) => setStatus(err.message));
    });
    document.getElementById('mfReload').addEventListener('click', () => {
        loadRecords().catch((err) => setStatus(err.message));
    });
    document.getElementById('mfCreateBtn').addEventListener('click', openCreate);
    document.getElementById('mfForm').addEventListener('submit', (ev) => {
        saveRecord(ev).catch((err) => setStatus(err.message));
    });

    (async function init() {
        if (!token()) {
            setStatus('Нет access_token. Войдите через /login или /admin.');
            return;
        }
        try {
            await loadManifests();
            await loadRecords();
        } catch (e) {
            setStatus(e.message || String(e));
        }
    })();
})();
</script>
<?php require dirname(__DIR__) . '/layout/footer.php'; ?>
