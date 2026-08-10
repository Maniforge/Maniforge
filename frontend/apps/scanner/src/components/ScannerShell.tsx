import { Outlet } from 'react-router-dom';
import { SiteNav } from '@/components/SiteNav';
import { useAuth } from '@/shared/auth/AuthContext';
import { SESSION_STORAGE } from '@/shared/auth/storage';

export function ScannerShell() {
  const { logout } = useAuth();
  const tenant = localStorage.getItem(SESSION_STORAGE.tenant) || '—';
  const subtenant = localStorage.getItem(SESSION_STORAGE.subtenant) || '—';

  return (
    <div className="sc-shell">
      <SiteNav
        active="scanner"
        actions={
          <button
            type="button"
            className="sc-button sc-button-secondary sc-nav-logout"
            onClick={() => logout()}
          >
            Выйти
          </button>
        }
      />
      <Outlet />
      <footer className="sc-footer">
        {tenant} / {subtenant}
      </footer>
    </div>
  );
}
