import { Navigate } from 'react-router-dom';
import { useAuth } from '@/shared/auth/AuthContext';

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { ready, authenticated } = useAuth();
  if (!ready) {
    return <p className="sc-muted" style={{ padding: '2rem', textAlign: 'center' }}>Загрузка…</p>;
  }
  if (!authenticated) {
    return <Navigate to="/login" replace />;
  }
  return children;
}
