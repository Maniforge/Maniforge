import { SESSION_STORAGE, clearSession } from './storage';

const rbacBase = () => import.meta.env.VITE_RBAC_BASE || '/rbac';

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
  return result;
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

export async function verifySession(): Promise<{ ok: boolean; error?: string }> {
  const response = await fetch(`${rbacBase()}/api/v1/me/contexts`, { headers: authHeaders(true) });
  const payload = await parseJson<{ ok: boolean; error?: string }>(response);
  if (!response.ok || !payload.ok) {
    return { ok: false, error: payload.error || `HTTP ${response.status}` };
  }
  return { ok: true };
}
