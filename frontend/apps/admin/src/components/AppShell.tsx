import { Link, Outlet } from 'react-router-dom';
import { DeskHeader } from '@maniforge/desk-ui';
import { ContextSwitcher } from './ContextSwitcher';

export function AppShell() {
  return (
    <div className="mf-shell">
      <DeskHeader
        current="admin"
        actions={
          <>
            <ContextSwitcher />
            <Link to="/dashboard">Модули</Link>
          </>
        }
      />
      <main className="mf-main">
        <Outlet />
      </main>
    </div>
  );
}
