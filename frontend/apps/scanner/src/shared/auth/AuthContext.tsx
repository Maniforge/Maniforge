import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { login as loginRequest, logout as logoutRequest, verifySession } from './session';
import { hasAccessToken } from './storage';

type AuthState = {
  ready: boolean;
  authenticated: boolean;
  login: (phone: string, password: string) => Promise<{ ok: boolean; error?: string }>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [authenticated, setAuthenticated] = useState(false);

  const refresh = useCallback(async () => {
    if (!hasAccessToken()) {
      setAuthenticated(false);
      return;
    }
    const result = await verifySession();
    setAuthenticated(result.ok);
  }, []);

  useEffect(() => {
    refresh().finally(() => setReady(true));
  }, [refresh]);

  const value = useMemo<AuthState>(
    () => ({
      ready,
      authenticated,
      login: async (phone, password) => {
        const result = await loginRequest(phone, password);
        if (result.ok) {
          setAuthenticated(true);
        }
        return result;
      },
      logout: async () => {
        await logoutRequest();
        setAuthenticated(false);
      },
    }),
    [ready, authenticated],
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
