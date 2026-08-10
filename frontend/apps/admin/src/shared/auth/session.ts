import { SESSION_STORAGE, clearSession } from './storage';

const rbacBase = () => import.meta.env.VITE_RBAC_BASE || '/rbac';

export type ManiforgeContext = {
  tenant_id: string;
  subtenant_id: string;
  label?: string;
  grant_level?: string;
  kind?: string;
  _tag?: 'home' | 'delegated';
};

export type ContextsPayload = {
  ok: boolean;
  current?: { tenant_id: string; subtenant_id: string };
  home?: ManiforgeContext[];
  delegated?: ManiforgeContext[];
  error?: string;
};

export type ConsoleAccess = {
  ok: boolean;
  modules?: { tenant?: boolean; platform?: boolean };
  platform_licensing_token?: string;
  error?: string;
};

export function authHeaders(includeAuth = true): HeadersInit {
  const result: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
  if (!includeAuth) {
    return result;
  }
  const token = localStorage.getItem(SESSION_STORAGE.access);
  if (token) {
    result.Authorization = `Bearer ${token}`;
  }
  const csrf = localStorage.getItem(SESSION_STORAGE.csrf);
  if (csrf) {
    result['X-CSRF-Token'] = csrf;
  }
  const actionExp = Number(localStorage.getItem(SESSION_STORAGE.actionExpires) || '0');
  const action = localStorage.getItem(SESSION_STORAGE.action);
  if (action && actionExp > Date.now()) {
    result['X-Action-Token'] = action;
  }
  return result;
}

export function flattenContexts(payload: ContextsPayload): ManiforgeContext[] {
  const items: ManiforgeContext[] = [];
  (payload.home || []).forEach((c) => items.push({ ...c, _tag: 'home' }));
  (payload.delegated || []).forEach((c) => items.push({ ...c, _tag: 'delegated' }));
  return items;
}

export function contextLabel(ctx: ManiforgeContext): string {
  if (ctx.label) {
    const tag = ctx._tag || ctx.kind || '';
    const grant = ctx.grant_level ? ` · ${ctx.grant_level}` : '';
    const suffix = tag ? ` (${tag}${grant})` : '';
    return ctx.label + suffix;
  }
  const tag = ctx._tag || ctx.kind || 'ctx';
  const grant = ctx.grant_level ? ` · ${ctx.grant_level}` : '';
  return `${ctx.tenant_id} / ${ctx.subtenant_id} (${tag}${grant})`;
}

async function parseJson<T>(response: Response): Promise<T> {
  return response.json().catch(() => ({ ok: false }) as T);
}

export async function login(phone: string, password: string): Promise<{ ok: boolean; error?: string }> {
  const response = await fetch(`${rbacBase()}/api/v1/auth/login`, {
    method: 'POST',
    headers: authHeaders(false),
    body: JSON.stringify({ phone, password }),
  });
  const payload = await parseJson<{
    ok: boolean;
    error?: string;
    csrf_token?: string;
    session?: { access_token?: string; refresh_token?: string; scope?: { tenant_id?: string; subtenant_id?: string } };
    credentials?: { session?: { access_token?: string; refresh_token?: string; scope?: { tenant_id?: string; subtenant_id?: string } } };
  }>(response);

  const session = payload.session || payload.credentials?.session;
  if (!response.ok || !payload.ok || !session?.access_token) {
    return { ok: false, error: payload.error || `HTTP ${response.status}` };
  }

  localStorage.setItem(SESSION_STORAGE.access, session.access_token);
  localStorage.setItem(SESSION_STORAGE.refresh, session.refresh_token || '');
  localStorage.setItem(SESSION_STORAGE.csrf, payload.csrf_token || '');
  const scope = session.scope || {};
  if (scope.tenant_id) localStorage.setItem(SESSION_STORAGE.tenant, scope.tenant_id);
  if (scope.subtenant_id) localStorage.setItem(SESSION_STORAGE.subtenant, scope.subtenant_id);

  const access = await fetchConsoleAccess();
  if (!access.ok) {
    clearSession();
    return { ok: false, error: access.error || 'Нет доступа к консоли' };
  }
  return { ok: true };
}

export async function logout(): Promise<void> {
  if (localStorage.getItem(SESSION_STORAGE.access)) {
    await fetch(`${rbacBase()}/api/v1/auth/logout`, {
      method: 'POST',
      headers: authHeaders(true),
      body: '{}',
    }).catch(() => undefined);
  }
  clearSession();
}

export async function fetchContexts(): Promise<{ ok: boolean; payload?: ContextsPayload; error?: string }> {
  const response = await fetch(`${rbacBase()}/api/v1/me/contexts`, { headers: authHeaders(true) });
  const payload = await parseJson<ContextsPayload>(response);
  if (!response.ok || !payload.ok) {
    return { ok: false, error: payload.error || `HTTP ${response.status}` };
  }
  const scope = payload.current;
  if (scope?.tenant_id) localStorage.setItem(SESSION_STORAGE.tenant, scope.tenant_id);
  if (scope?.subtenant_id) localStorage.setItem(SESSION_STORAGE.subtenant, scope.subtenant_id);
  return { ok: true, payload };
}

export async function switchContext(tenantId: string, subtenantId: string): Promise<{ ok: boolean; error?: string }> {
  const response = await fetch(`${rbacBase()}/api/v1/auth/switch-context`, {
    method: 'POST',
    headers: authHeaders(true),
    body: JSON.stringify({ tenant_id: tenantId, subtenant_id: subtenantId }),
  });
  const payload = await parseJson<{
    ok: boolean;
    error?: string;
    session?: { tenant_id?: string; subtenant_id?: string };
  }>(response);
  if (!response.ok || !payload.ok) {
    return { ok: false, error: payload.error || `HTTP ${response.status}` };
  }
  const session = payload.session || {};
  localStorage.setItem(SESSION_STORAGE.tenant, session.tenant_id || tenantId);
  localStorage.setItem(SESSION_STORAGE.subtenant, session.subtenant_id || subtenantId);
  return { ok: true };
}

export async function fetchConsoleAccess(): Promise<ConsoleAccess> {
  const response = await fetch(`${rbacBase()}/api/v1/me/console-access`, { headers: authHeaders(true) });
  const payload = await parseJson<ConsoleAccess>(response);
  if (!response.ok || !payload.ok) {
    return { ok: false, error: payload.error || `HTTP ${response.status}` };
  }
  if (payload.platform_licensing_token) {
    localStorage.setItem(SESSION_STORAGE.platformToken, payload.platform_licensing_token);
    localStorage.setItem('maniforge_tl_admin_token', payload.platform_licensing_token);
    payload.modules = { ...payload.modules, platform: true };
  }
  return payload;
}
