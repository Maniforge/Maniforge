import { Link, Outlet } from 'react-router-dom';
import { SiteNav } from '@/components/SiteNav';
import { useAuth } from '@/shared/auth/AuthContext';
import { ContextSwitcher } from './ContextSwitcher';

export function AppShell() {
  const { logout } = useAuth();

  return (
    <div className="mf-shell">
      <SiteNav
        active="admin"
        actions={
          <>
            <ContextSwitcher />
            <Link to="/dashboard" className="mf-button mf-button-ghost">
              <i className="bi bi-grid" aria-hidden="true" />
              Модули
            </Link>
            <button type="button" className="mf-button mf-button-ghost" onClick={() => logout()}>
              <i className="bi bi-box-arrow-right" aria-hidden="true" />
              Выход
            </button>
          </>
        }
      />
      <main className="mf-main">
        <Outlet />
      </main>
    </div>
  );
}
