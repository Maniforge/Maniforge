import { FormEvent, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/shared/auth/AuthContext';

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

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
    navigate('/', { replace: true });
  }

  return (
    <div className="sc-main">
      <div className="sc-panel">
        <h1 className="sc-title">WMS Scanner</h1>
        <p className="sc-lead">Вход по RBAC-сессии (общий токен с Admin SPA).</p>
        <form onSubmit={onSubmit}>
          <label style={{ display: 'block', marginTop: '1rem' }}>
            Телефон
            <input
              className="sc-field"
              type="tel"
              autoComplete="username"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              required
            />
          </label>
          <label style={{ display: 'block', marginTop: '0.85rem' }}>
            Пароль
            <input
              className="sc-field"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </label>
          {error ? <p className="sc-error" style={{ marginTop: '0.75rem' }}>{error}</p> : null}
          <button type="submit" className="sc-button" style={{ marginTop: '1rem' }} disabled={loading}>
            {loading ? 'Вход…' : 'Войти'}
          </button>
        </form>
      </div>
    </div>
  );
}
