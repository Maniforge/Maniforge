'use strict';

(function (global) {
    const STORAGE = {
        access: 'maniforge_admin_access_token',
        refresh: 'maniforge_admin_refresh_token',
        csrf: 'maniforge_admin_csrf_token',
        action: 'maniforge_admin_action_token',
        actionExpires: 'maniforge_admin_action_token_expires',
        tenant: 'maniforge_admin_tenant_id',
        subtenant: 'maniforge_admin_subtenant_id',
    };

    function hasSession() {
        return Boolean(localStorage.getItem(STORAGE.access));
    }

    function headers(includeAuth) {
        const result = {
            Accept: 'application/json',
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

    function flattenContexts(payload) {
        const items = [];
        (payload.home || []).forEach((c) => items.push({ ...c, _tag: 'home' }));
        (payload.delegated || []).forEach((c) => items.push({ ...c, _tag: 'delegated' }));
        return items;
    }

    function contextLabel(ctx) {
        if (ctx.label) {
            const tag = ctx._tag || ctx.kind || ctx.membership || '';
            const grant = ctx.grant_level ? ' · ' + ctx.grant_level : '';
            const suffix = tag ? ' (' + tag + grant + ')' : '';
            return ctx.label + suffix;
        }
        const tag = ctx._tag || ctx.kind || 'ctx';
        const grant = ctx.grant_level ? ' · ' + ctx.grant_level : '';
        return ctx.tenant_id + ' / ' + ctx.subtenant_id + ' (' + tag + grant + ')';
    }

    function organizationsFromPayload(payload) {
        if (Array.isArray(payload.organizations) && payload.organizations.length > 0) {
            return payload.organizations;
        }
        return flattenContexts(payload).map((ctx) => ({
            ...ctx,
            membership: ctx._tag || ctx.kind || 'home',
            is_current: false,
        }));
    }

    function renderOrganizationsList(container, payload, options) {
        const opts = options || {};
        const onMessage = opts.onMessage || null;
        const current = payload.current || {};
        const items = organizationsFromPayload(payload);
        container.innerHTML = '';
        if (items.length === 0) {
            container.innerHTML = '<p class="app-muted small mb-0">Нет доступных организаций.</p>';
            return { items: [], current };
        }
        const list = document.createElement('div');
        list.className = 'profile-org-list';
        items.forEach((org) => {
            const isCurrent = org.is_current === true
                || (org.tenant_id === current.tenant_id && org.subtenant_id === current.subtenant_id);
            const card = document.createElement('article');
            card.className = 'profile-org-item' + (isCurrent ? ' is-current' : '');
            const membership = org.membership === 'delegated' ? 'Делегированный доступ' : 'Ваша организация';
            const grant = org.grant_level ? '<span class="profile-org-badge">' + org.grant_level + '</span>' : '';
            card.innerHTML =
                '<div class="profile-org-item-head">'
                + '<strong class="profile-org-title">' + (org.tenant_name || org.tenant_id) + '</strong>'
                + (isCurrent ? '<span class="profile-org-badge is-active">Текущая</span>' : '')
                + '</div>'
                + '<p class="app-muted small mb-1">' + (org.subtenant_name || org.subtenant_id) + '</p>'
                + '<p class="app-muted small mb-2"><code>' + org.tenant_id + '</code> / <code>' + org.subtenant_id + '</code></p>'
                + '<p class="app-muted small mb-2">' + membership + grant + '</p>'
                + '<button type="button" class="app-button app-button-secondary profile-org-switch"' + (isCurrent ? ' disabled' : '') + '>'
                + (isCurrent ? 'Активна' : 'Переключиться')
                + '</button>';
            if (!isCurrent) {
                card.querySelector('.profile-org-switch')?.addEventListener('click', async () => {
                    const btn = card.querySelector('.profile-org-switch');
                    if (btn) {
                        btn.disabled = true;
                        btn.textContent = 'Переключение…';
                    }
                    const result = await switchContext(org.tenant_id, org.subtenant_id, { reload: opts.reload !== false });
                    if (!result.ok) {
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = 'Переключиться';
                        }
                        if (onMessage) {
                            onMessage(result.error || 'Ошибка переключения', true);
                        }
                    } else if (onMessage) {
                        onMessage('Контекст переключён', false);
                    }
                });
            }
            list.appendChild(card);
        });
        container.appendChild(list);
        return { items, current };
    }

    function contextCount(payload) {
        return flattenContexts(payload).length;
    }

    async function fetchContexts() {
        const response = await fetch('/rbac/api/v1/me/contexts', { headers: headers(true) });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            return { ok: false, error: payload.error || ('HTTP ' + response.status), payload };
        }
        const scope = payload.current || {};
        if (scope.tenant_id) {
            localStorage.setItem(STORAGE.tenant, scope.tenant_id);
        }
        if (scope.subtenant_id) {
            localStorage.setItem(STORAGE.subtenant, scope.subtenant_id);
        }
        return { ok: true, payload };
    }

    async function switchContext(tenantId, subtenantId, options) {
        const reload = !options || options.reload !== false;
        const response = await fetch('/rbac/api/v1/auth/switch-context', {
            method: 'POST',
            headers: headers(true),
            body: JSON.stringify({ tenant_id: tenantId, subtenant_id: subtenantId }),
        });
        const payload = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !payload.ok) {
            return { ok: false, error: payload.error || ('HTTP ' + response.status) };
        }
        const session = payload.session || {};
        localStorage.setItem(STORAGE.tenant, session.tenant_id || tenantId);
        localStorage.setItem(STORAGE.subtenant, session.subtenant_id || subtenantId);
        if (reload) {
            window.location.reload();
        }
        return { ok: true, payload };
    }

    function bindSelect(select, onError) {
        if (!select || select.dataset.maniforgeBound === '1') {
            return;
        }
        select.dataset.maniforgeBound = '1';
        select.addEventListener('change', async () => {
            const parts = String(select.value || '').split('\0');
            const tenantId = parts[0];
            const subtenantId = parts[1];
            if (!tenantId || !subtenantId) {
                return;
            }
            const refreshed = await fetchContexts();
            const current = refreshed.ok && refreshed.payload ? refreshed.payload.current || {} : {};
            if (tenantId === current.tenant_id && subtenantId === current.subtenant_id) {
                return;
            }
            select.disabled = true;
            const result = await switchContext(tenantId, subtenantId);
            if (!result.ok) {
                select.disabled = false;
                if (onError) {
                    onError(result.error || 'Ошибка переключения');
                } else {
                    alert(result.error || 'Не удалось переключить организацию');
                }
                const again = await fetchContexts();
                if (again.ok) {
                    populateSelect(select, again.payload);
                }
            }
        });
    }

    function populateSelect(select, payload) {
        if (!select) {
            return { multiple: false, items: [] };
        }
        const current = payload.current || {};
        const items = flattenContexts(payload);
        const previous = select.value;
        select.innerHTML = '';
        if (items.length === 0) {
            const opt = document.createElement('option');
            opt.value = (current.tenant_id || '') + '\0' + (current.subtenant_id || '');
            opt.textContent = (current.tenant_id || '—') + ' / ' + (current.subtenant_id || '—');
            select.appendChild(opt);
            select.disabled = true;
            return { multiple: false, items, current };
        }
        items.forEach((ctx) => {
            const opt = document.createElement('option');
            opt.value = ctx.tenant_id + '\0' + ctx.subtenant_id;
            opt.textContent = contextLabel(ctx);
            if (ctx.tenant_id === current.tenant_id && ctx.subtenant_id === current.subtenant_id) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });
        if (previous && previous !== select.value) {
            const match = Array.from(select.options).some((o) => o.value === previous);
            if (match) {
                select.value = previous;
            }
        }
        select.disabled = items.length <= 1;
        return { multiple: items.length > 1, items, current };
    }

    function clearSession() {
        Object.values(STORAGE).forEach((key) => localStorage.removeItem(key));
        document.documentElement.classList.remove('maniforge-has-session');
    }

    async function initNavAuthState() {
        const guest = document.getElementById('navGuestLinks');
        const auth = document.getElementById('navAuthLinks');
        const sessionActive = hasSession();

        if (sessionActive) {
            document.documentElement.classList.add('maniforge-has-session');
        } else {
            document.documentElement.classList.remove('maniforge-has-session');
        }

        if (guest) {
            guest.classList.toggle('hidden', sessionActive);
        }
        if (auth) {
            auth.classList.toggle('hidden', !sessionActive);
        }

        const logoutBtn = document.getElementById('navLogoutBtn');
        if (logoutBtn && logoutBtn.dataset.maniforgeBound !== '1') {
            logoutBtn.dataset.maniforgeBound = '1';
            logoutBtn.addEventListener('click', async () => {
                if (hasSession()) {
                    await fetch('/rbac/api/v1/auth/logout', {
                        method: 'POST',
                        headers: headers(true),
                        body: '{}',
                    }).catch(() => {});
                }
                clearSession();
                window.location.href = '/';
            });
        }

        if (sessionActive) {
            await initNavSwitcher();
        } else {
            const wrap = document.getElementById('navOrgSwitcherWrap');
            if (wrap) {
                wrap.classList.add('hidden');
            }
        }
    }

    async function initNavSwitcher() {
        const wrap = document.getElementById('navOrgSwitcherWrap');
        const select = document.getElementById('navOrgSwitcher');
        if (!wrap || !select) {
            return;
        }
        if (!hasSession()) {
            wrap.classList.add('hidden');
            return;
        }
        const result = await fetchContexts();
        if (!result.ok) {
            wrap.classList.add('hidden');
            return;
        }
        populateSelect(select, result.payload);
        bindSelect(select, null);
        wrap.classList.remove('hidden');
    }

    global.ManiforgeSession = {
        STORAGE,
        hasSession,
        headers,
        clearSession,
        fetchContexts,
        switchContext,
        flattenContexts,
        contextLabel,
        contextCount,
        populateSelect,
        bindSelect,
        initNavSwitcher,
        initNavAuthState,
        organizationsFromPayload,
        renderOrganizationsList,
    };

    document.addEventListener('DOMContentLoaded', () => {
        initNavAuthState();
    });
})(window);
