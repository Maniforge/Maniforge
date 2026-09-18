import { Outlet } from 'react-router-dom';
import { DeskHeader } from '@maniforge/desk-ui';
import { SESSION_STORAGE } from '@/shared/auth/storage';

export function ScannerShell() {
  const tenant = localStorage.getItem(SESSION_STORAGE.tenant) || '—';
  const subtenant = localStorage.getItem(SESSION_STORAGE.subtenant) || '—';

  return (
    <div className="sc-shell">
      <DeskHeader current="scanner" />
      <Outlet />
      <footer className="sc-footer">
        {tenant} / {subtenant}
      </footer>
    </div>
  );
}
