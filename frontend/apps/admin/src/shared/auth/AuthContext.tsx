import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import {
  fetchConsoleAccess,
  fetchContexts,
  login as loginRequest,
  logout as logoutRequest,
  switchContext as switchContextRequest,
  type ContextsPayload,
} from './session';
import { hasAccessToken } from './storage';

type AuthState = {
  ready: boolean;
  authenticated: boolean;
  modules: { tenant?: boolean; platform?: boolean };
  contexts: ContextsPayload | null;
  login: (phone: string, password: string) => Promise<{ ok: boolean; error?: string }>;
  logout: () => Promise<void>;
  switchContext: (tenantId: string, subtenantId: string) => Promise<{ ok: boolean; error?: string }>;
  refreshAccess: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [authenticated, setAuthenticated] = useState(false);
  const [modules, setModules] = useState<{ tenant?: boolean; platform?: boolean }>({});
  const [contexts, setContexts] = useState<ContextsPayload | null>(null);

  const refreshAccess = useCallback(async () => {
    if (!hasAccessToken()) {
      setAuthenticated(false);
      setModules({});
      setContexts(null);
      return;
    }
    const access = await fetchConsoleAccess();
    if (!access.ok) {
      setAuthenticated(false);
      setModules({});
      setContexts(null);
      return;
    }
    setAuthenticated(true);
    setModules(access.modules || {});
    const ctx = await fetchContexts();
    setContexts(ctx.ok ? ctx.payload || null : null);
  }, []);

  useEffect(() => {
    refreshAccess().finally(() => setReady(true));
  }, [refreshAccess]);

  const login = useCallback(async (phone: string, password: string) => {
    const result = await loginRequest(phone, password);
    if (result.ok) {
      await refreshAccess();
    }
    return result;
  }, [refreshAccess]);

  const logout = useCallback(async () => {
    await logoutRequest();
    setAuthenticated(false);
    setModules({});
    setContexts(null);
  }, []);

  const switchContext = useCallback(async (tenantId: string, subtenantId: string) => {
    const result = await switchContextRequest(tenantId, subtenantId);
    if (result.ok) {
      await refreshAccess();
    }
    return result;
  }, [refreshAccess]);

  const value = useMemo(
    () => ({
      ready,
      authenticated,
      modules,
      contexts,
      login,
      logout,
      switchContext,
      refreshAccess,
    }),
    [ready, authenticated, modules, contexts, login, logout, switchContext, refreshAccess],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth вне AuthProvider');
  }
  return ctx;
}
