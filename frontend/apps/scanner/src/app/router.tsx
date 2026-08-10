import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { ProtectedRoute } from '@/app/ProtectedRoute';
import { ScannerShell } from '@/components/ScannerShell';
import { GroupPage } from '@/pages/GroupPage';
import { PalletPage } from '@/pages/PalletPage';
import { HubPage } from '@/pages/HubPage';
import { LoginPage } from '@/pages/LoginPage';
import { MovementPage } from '@/pages/MovementPage';
import { ScanPage } from '@/pages/ScanPage';

export function AppRouter() {
  return (
    <BrowserRouter basename="/scanner">
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route
          element={
            <ProtectedRoute>
              <ScannerShell />
            </ProtectedRoute>
          }
        >
          <Route path="/" element={<HubPage />} />
          <Route path="/scan" element={<ScanPage />} />
          <Route path="/receipt" element={<MovementPage movementType="receipt" />} />
          <Route path="/issue" element={<MovementPage movementType="issue" />} />
          <Route path="/group" element={<GroupPage />} />
          <Route path="/pallet" element={<PalletPage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
