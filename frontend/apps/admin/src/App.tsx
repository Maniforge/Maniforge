import { AuthProvider } from '@/shared/auth/AuthContext';
import { AppRouter } from '@/app/router';

export function App() {
  return (
    <AuthProvider>
      <AppRouter />
    </AuthProvider>
  );
}
