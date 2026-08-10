<?php
declare(strict_types=1);

$branding = require __DIR__ . '/data/branding.php';
$pageTitle = 'Tenant admin — ' . $branding['app_name'];
$activeNav = 'admin';

require __DIR__ . '/layout/header.php';
?>
<section class="app-page-head app-main-wide">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
        <div>
            <span class="app-kicker">Tenant scope</span>
            <h1 class="app-title h4 mb-0">RBAC tenant admin</h1>
        </div>
        <a class="app-button app-button-secondary" href="/admin">← К модулям админки</a>
    </div>
</section>

<section class="app-panel app-main-wide mt-4" style="max-width: 40rem;">
    <article class="app-card app-card-stretch">
        <h2 class="app-card-title">Ссылки регистрации</h2>
        <p class="app-muted small">
            Создайте приглашение после входа в админку (tenant admin). Пользователь откроет
            <code>/register?invite=TOKEN</code> — tenant/subtenant вводить не нужно.
        </p>
        <div class="mb-3">
            <label class="form-label" for="inviteType">Тип приглашения</label>
            <select class="form-select app-field" id="inviteType">
                <option value="user">Пользователь в текущий subtenant</option>
                <option value="subtenant">Новый subtenant (создаётся при регистрации)</option>
            </select>
        </div>
        <div class="mb-3 hidden" id="inviteSubtenantNameWrap">
            <label class="form-label" for="inviteSubtenantName">Название нового subtenant</label>
            <input class="form-control app-field" id="inviteSubtenantName" placeholder="Отдел продаж">
        </div>
        <div class="mb-3">
            <label class="form-label" for="inviteRole">Роль (необязательно)</label>
            <input class="form-control app-field" id="inviteRole" placeholder="user">
        </div>
        <div id="inviteMessage" class="app-muted small mb-3" role="status"></div>
        <button type="button" class="app-button" id="inviteCreateBtn">Создать ссылку</button>
        <div class="mt-3 hidden" id="inviteResultWrap">
            <label class="form-label" for="inviteUrl">Ссылка для отправки</label>
            <input class="form-control app-field" id="inviteUrl" readonly>
        </div>
    </article>
</section>

<iframe
    class="app-admin-frame app-main-wide"
    title="RBAC tenant admin"
    src="/rbac/admin#access"
></iframe>
<script>
(function () {
    const STORAGE = {
        access: 'maniforge_admin_access_token',
        csrf: 'maniforge_admin_csrf_token',
        action: 'maniforge_admin_action_token',
        actionExpires: 'maniforge_admin_action_token_expires',
    };

    const inviteType = document.getElementById('inviteType');
    const subtenantNameWrap = document.getElementById('inviteSubtenantNameWrap');
    const message = document.getElementById('inviteMessage');
    const resultWrap = document.getElementById('inviteResultWrap');
    const inviteUrl = document.getElementById('inviteUrl');

    function headers() {
        const result = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        };
        const token = localStorage.getItem(STORAGE.access);
        if (token) result.Authorization = 'Bearer ' + token;
        const csrf = localStorage.getItem(STORAGE.csrf);
        if (csrf) result['X-CSRF-Token'] = csrf;
        const actionExp = Number(localStorage.getItem(STORAGE.actionExpires) || '0');
        const action = localStorage.getItem(STORAGE.action);
        if (action && actionExp > Date.now()) result['X-Action-Token'] = action;
        return result;
    }

    function syncInviteType() {
        const isSubtenant = inviteType.value === 'subtenant';
        subtenantNameWrap.classList.toggle('hidden', !isSubtenant);
    }
    inviteType.addEventListener('change', syncInviteType);
    syncInviteType();

    document.getElementById('inviteCreateBtn').addEventListener('click', async () => {
        message.textContent = 'Создание…';
        message.className = 'app-muted small mb-3';
        resultWrap.classList.add('hidden');
        const body = { invite_type: inviteType.value };
        const role = document.getElementById('inviteRole').value.trim();
        if (role) body.role_code = role;
        if (inviteType.value === 'subtenant') {
            body.subtenant_name = document.getElementById('inviteSubtenantName').value.trim();
            if (!body.subtenant_name) {
                message.textContent = 'Укажите название нового subtenant';
                message.className = 'small mb-3 text-danger';
                return;
            }
        }
        const token = localStorage.getItem(STORAGE.access);
        if (!token) {
            message.textContent = 'Сначала войдите через /admin';
            message.className = 'small mb-3 text-danger';
            return;
        }
        try {
            const response = await fetch('/rbac/api/v1/admin/registration-invites', {
                method: 'POST',
                headers: headers(),
                body: JSON.stringify(body),
            });
            const payload = await response.json().catch(() => ({ ok: false }));
            if (!response.ok || !payload.ok) {
                message.textContent = payload.error || ('HTTP ' + response.status);
                message.className = 'small mb-3 text-danger';
                return;
            }
            const url = payload.register_url || '';
            inviteUrl.value = url;
            resultWrap.classList.remove('hidden');
            message.textContent = 'Ссылка создана. Срок действия: ' + (payload.invite?.expires_at || '—');
            message.className = 'small mb-3 text-success';
        } catch (error) {
            message.textContent = String(error);
            message.className = 'small mb-3 text-danger';
        }
    });
})();
</script>
<?php require __DIR__ . '/layout/footer.php';
