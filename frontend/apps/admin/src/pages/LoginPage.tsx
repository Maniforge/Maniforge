import { FormEvent, useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '@/shared/auth/AuthContext';

export function LoginPage() {
  const { authenticated, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from || '/dashboard';

  const [phone, setPhone] = useState('+7');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  if (authenticated) {
    return <Navigate to={from} replace />;
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError('');
    const result = await login(phone.trim(), password);
    setLoading(false);
    if (!result.ok) {
      setError(result.error || 'Ошибка входа');
      return;
    }
    navigate(from, { replace: true });
  }

  return (
    <div className="mf-login-wrap">
      <div className="mf-panel">
        <span className="mf-kicker">
          <i className="bi bi-shield-lock" aria-hidden="true" />
          Admin SPA
        </span>
        <h1 className="mf-title h3">Вход в консоль</h1>
        <p className="mf-lead">Тот же аккаунт, что и PHP-админка (/admin). Сессия общая через localStorage.</p>
        <form onSubmit={onSubmit} style={{ marginTop: '1.25rem' }}>
          <label>
            Телефон
            <input
              className="mf-field"
              type="tel"
              autoComplete="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              required
            />
          </label>
          <label style={{ display: 'block', marginTop: '0.85rem' }}>
            Пароль
            <input
              className="mf-field"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </label>
          {error ? <p className="mf-error" style={{ marginTop: '0.75rem' }}>{error}</p> : null}
          <button type="submit" className="mf-button" style={{ marginTop: '1rem', width: '100%' }} disabled={loading}>
            {loading ? 'Вход…' : 'Войти'}
          </button>
        </form>
        <p className="mf-muted" style={{ marginTop: '1rem', fontSize: '0.875rem' }}>
          <a href="/">На главную</a>
          {' · '}
          <a href="/admin">PHP-админка</a>
        </p>
      </div>
    </div>
  );
}
