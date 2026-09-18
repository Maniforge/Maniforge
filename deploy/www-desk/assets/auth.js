const API = '/rbac/api/v1';
const MIN_PASS = 12;
const $ = (id) => document.getElementById(id);

function msg(text, ok) {
  const el = $('msg');
  el.textContent = text || '';
  el.className = 'msg ' + (ok ? 'ok' : (text ? 'err' : ''));
}

function phoneValue() {
  return ($('phone').value || '').trim();
}

function tenantHeaders() {
  const tenant = ($('tenant')?.value || localStorage.getItem('maniforge_tenant_code') || '').trim();
  const sub = ($('subtenant')?.value || localStorage.getItem('maniforge_subtenant_code') || 'main').trim() || 'main';
  const headers = {};
  if (tenant) {
    headers['X-Tenant-ID'] = tenant;
    headers['X-Subtenant-ID'] = sub;
  }
  return { tenant, sub, headers };
}

async function post(path, body, extraHeaders = {}) {
  const { headers } = tenantHeaders();
  const res = await fetch(API + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...headers, ...extraHeaders },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  return { res, data };
}

function saveSession(data) {
  const sess = (data.credentials && data.credentials.session) || data.session || data.tokens || data;
  const token = sess.access_token || data.access_token;
  const refresh = sess.refresh_token || data.refresh_token;
  const csrf = sess.csrf_token || data.csrf_token;
  if (token) localStorage.setItem('maniforge_access_token', token);
  if (refresh) localStorage.setItem('maniforge_refresh_token', refresh);
  if (csrf) localStorage.setItem('maniforge_csrf_token', csrf);
  const { tenant, sub } = tenantHeaders();
  if (tenant) localStorage.setItem('maniforge_tenant_code', tenant);
  if (sub) localStorage.setItem('maniforge_subtenant_code', sub);
  if (sess.scope) localStorage.setItem('maniforge_tenant', JSON.stringify(sess.scope));
  if (data.user) localStorage.setItem('maniforge_user', JSON.stringify(data.user));
}

async function doLogin() {
  msg('');
  const phone = phoneValue();
  const password = $('password').value;
  const { tenant, sub } = tenantHeaders();
  if (!phone || !password) return msg('Укажите телефон и пароль');
  if (!tenant) return msg('Укажите код организации (tenant)');
  $('btnLogin').disabled = true;
  try {
    const { res, data } = await post('/auth/login', { phone, password, tenant_id: tenant, subtenant_id: sub });
    if (!res.ok || data.ok === false) {
      msg(data.error || ('Ошибка входа (' + res.status + ')'));
      return;
    }
    saveSession(data);
    location.href = '/desk/';
  } catch (e) {
    msg('Сеть: ' + e.message);
  } finally {
    $('btnLogin').disabled = false;
  }
}

async function applyBootstrap() {
  let data = {};
  try {
    const res = await fetch('/assets/bootstrap.json', { headers: { Accept: 'application/json' } });
    if (res.ok) data = await res.json();
  } catch (_) {
    /* optional before first install */
  }
  const tenantEl = $('tenant');
  const subEl = $('subtenant');
  const phoneEl = $('phone');
  const storedTenant = localStorage.getItem('maniforge_tenant_code') || '';
  const storedSub = localStorage.getItem('maniforge_subtenant_code') || '';
  if (tenantEl) tenantEl.value = storedTenant || data.tenant_id || tenantEl.value || '';
  if (subEl) subEl.value = storedSub || data.subtenant_id || subEl.value || 'main';
  if (phoneEl) {
    const cur = (phoneEl.value || '').trim();
    if (!cur || cur === '+7') phoneEl.value = data.phone || cur;
  }
}

window.ManiforgeAuth = { doLogin, applyBootstrap };
