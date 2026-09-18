const RBAC = '/rbac/api/v1';
const ME = '/manifest-engine/api/v1';
const KEYS = {
  access: 'maniforge_access_token',
  refresh: 'maniforge_refresh_token',
  csrf: 'maniforge_csrf_token',
  user: 'maniforge_user',
  tenant: 'maniforge_tenant',
  tenantCode: 'maniforge_tenant_code',
  subtenantCode: 'maniforge_subtenant_code',
};

function token() {
  return localStorage.getItem(KEYS.access) || '';
}

function requireAuth() {
  if (!token()) {
    location.href = '/desk/login/';
    return false;
  }
  return true;
}

function logout() {
  Object.values(KEYS).forEach((k) => localStorage.removeItem(k));
  location.href = '/desk/login/';
}

async function api(base, path, opts = {}) {
  const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
  if (token()) headers.Authorization = 'Bearer ' + token();
  const csrf = localStorage.getItem(KEYS.csrf);
  if (csrf && opts.method && opts.method !== 'GET') headers['X-CSRF-Token'] = csrf;
  const tenant = localStorage.getItem(KEYS.tenantCode);
  const sub = localStorage.getItem(KEYS.subtenantCode);
  if (tenant) headers['X-Tenant-ID'] = tenant;
  if (sub) headers['X-Subtenant-ID'] = sub;
  const res = await fetch(base + path, {
    ...opts,
    headers,
    body: opts.body && typeof opts.body !== 'string' ? JSON.stringify(opts.body) : opts.body,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) {
    logout();
    throw new Error('session');
  }
  return { res, data };
}

function sessionFrom(data) {
  return (
    (data.credentials && data.credentials.session) ||
    data.session ||
    data.tokens ||
    data
  );
}

async function applyBootstrap() {
  let data = {};
  try {
    const res = await fetch('/assets/bootstrap.json', { headers: { Accept: 'application/json' } });
    if (res.ok) data = await res.json();
  } catch (_) {
    /* optional before first install */
  }
  const tenantEl = document.getElementById('tenant');
  const subEl = document.getElementById('subtenant');
  const phoneEl = document.getElementById('phone');
  const storedTenant = localStorage.getItem(KEYS.tenantCode) || '';
  const storedSub = localStorage.getItem(KEYS.subtenantCode) || '';
  if (tenantEl) tenantEl.value = storedTenant || data.tenant_id || tenantEl.value || '';
  if (subEl) subEl.value = storedSub || data.subtenant_id || subEl.value || 'main';
  if (phoneEl) {
    const cur = (phoneEl.value || '').trim();
    if (!cur || cur === '+7') {
      phoneEl.value = data.phone || cur;
    }
  }
  return data;
}

async function loginRaw(phone, password, tenant, subtenant) {
  const tenantId = String(tenant || localStorage.getItem(KEYS.tenantCode) || '').trim();
  const subId = String(subtenant || localStorage.getItem(KEYS.subtenantCode) || 'main').trim() || 'main';
  if (!tenantId) throw new Error('Укажите код организации (tenant)');
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Tenant-ID': tenantId,
    'X-Subtenant-ID': subId,
  };
  const res = await fetch(RBAC + '/auth/login', {
    method: 'POST',
    headers,
    body: JSON.stringify({ phone, password, tenant_id: tenantId, subtenant_id: subId }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || 'Ошибка входа ' + res.status);
  }
  const sess = sessionFrom(data);
  localStorage.setItem(KEYS.access, sess.access_token || '');
  localStorage.setItem(KEYS.refresh, sess.refresh_token || '');
  localStorage.setItem(KEYS.csrf, sess.csrf_token || '');
  localStorage.setItem(KEYS.tenantCode, tenantId);
  localStorage.setItem(KEYS.subtenantCode, subId);
  if (sess.scope) localStorage.setItem(KEYS.tenant, JSON.stringify(sess.scope));
  if (data.user) localStorage.setItem(KEYS.user, JSON.stringify(data.user));
  return data;
}

async function loadMe() {
  const { data } = await api(RBAC, '/me');
  return data;
}

async function listManifests() {
  const { res, data } = await api(ME, '/manifests');
  if (!res.ok) throw new Error(data.error || 'manifests ' + res.status);
  return data.items || data.manifests || data.data || (Array.isArray(data) ? data : []);
}

async function listRecords(code) {
  const headers = { Authorization: 'Bearer ' + token(), Accept: 'application/json' };
  const tenant = localStorage.getItem(KEYS.tenantCode);
  const sub = localStorage.getItem(KEYS.subtenantCode);
  if (tenant) headers['X-Tenant-ID'] = tenant;
  if (sub) headers['X-Subtenant-ID'] = sub;
  const res = await fetch('/manifest-engine/api/data/' + encodeURIComponent(code), { headers });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) {
    logout();
    throw new Error('session');
  }
  if (!res.ok) throw new Error(data.error || 'data ' + res.status);
  return data.items || data.records || data.data || [];
}

async function listUsers() {
  const { res, data } = await api(RBAC, '/admin/users');
  if (!res.ok) throw new Error(data.error || 'users ' + res.status);
  return data.items || data.users || [];
}

async function createUser({ login, phone, password, email, reason, roleCode }) {
  const { res, data } = await api(RBAC, '/admin/users', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: {
      login,
      phone,
      password,
      email: email || undefined,
      status: 'active',
      reason: reason || 'desk-create',
    },
  });
  if (!res.ok || data.ok === false) throw new Error(data.error || 'create ' + res.status);
  const user = data.user || {};
  if (roleCode && user.id) {
    const role = await api(RBAC, '/admin/user-roles/assign', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: { user_id: user.id, role_code: roleCode, reason: reason || 'desk-create' },
    });
    if (!role.res.ok || role.data.ok === false) {
      throw new Error(role.data.error || 'role ' + role.res.status);
    }
  }
  return user;
}

window.ManiforgeDesk = {
  loginRaw,
  logout,
  requireAuth,
  loadMe,
  listManifests,
  listRecords,
  listUsers,
  createUser,
  applyBootstrap,
  token,
  api,
  KEYS,
};
