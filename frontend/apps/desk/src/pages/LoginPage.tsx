import { FormEvent, useEffect, useState } from 'react';
import { hasDeskSession } from '@maniforge/desk-ui';

const RBAC = '/rbac/api/v1';

export function LoginPage() {
  const [tenant, setTenant] = useState('');
  const [subtenant, setSubtenant] = useState('main');
  const [phone, setPhone] = useState('+7');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (hasDeskSession()) {
      window.location.href = '/desk/';
      return;
    }
    fetch('/assets/bootstrap.json', { headers: { Accept: 'application/json' } })
      .then((res) => (res.ok ? res.json() : null))
      .then((data: { tenant_id?: string; subtenant_id?: string; phone?: string } | null) => {
        if (!data) return;
        if (data.tenant_id) setTenant((cur) => cur || data.tenant_id || '');
        if (data.subtenant_id) setSubtenant((cur) => cur || data.subtenant_id || 'main');
        if (data.phone) setPhone((cur) => (!cur || cur === '+7' ? data.phone || cur : cur));
      })
      .catch(() => undefined);
  }, []);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError('');
    try {
      const tenantId = tenant.trim();
      const subId = subtenant.trim() || 'main';
      if (!tenantId) throw new Error('Укажите код организации (tenant)');
      const res = await fetch(RBAC + '/auth/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Tenant-ID': tenantId,
          'X-Subtenant-ID': subId,
        },
        body: JSON.stringify({ phone: phone.trim(), password, tenant_id: tenantId, subtenant_id: subId }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) {
        throw new Error(data.error || 'Ошибка входа ' + res.status);
      }
      const sess = data.credentials?.session || data.session || data.tokens || data;
      localStorage.setItem('maniforge_access_token', sess.access_token || '');
      localStorage.setItem('maniforge_refresh_token', sess.refresh_token || '');
      localStorage.setItem('maniforge_csrf_token', sess.csrf_token || '');
      localStorage.setItem('maniforge_tenant_code', tenantId);
      localStorage.setItem('maniforge_subtenant_code', subId);
      window.location.href = '/desk/';
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
      setLoading(false);
    }
  }

  return (
    <div className="login-wrap">
      <main className="card login-card">
        <p className="kicker">Maniforge Desk</p>
        <h1>Вход</h1>
        <p className="lead">Закрытый контур. Аккаунт выдаёт администратор.</p>
        <form onSubmit={onSubmit}>
          <label>
            Организация (tenant)
            <input value={tenant} onChange={(e) => setTenant(e.target.value)} placeholder="код tenant" />
          </label>
          <label>
            Workspace
            <input value={subtenant} onChange={(e) => setSubtenant(e.target.value)} />
          </label>
          <label>
            Телефон
            <input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
          </label>
          <label>
            Пароль
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
          </label>
          {error ? <p className="msg">{error}</p> : null}
          <button className="btn" type="submit" style={{ width: '100%', marginTop: '1rem' }} disabled={loading}>
            {loading ? 'Вход…' : 'Войти в Desk'}
          </button>
        </form>
      </main>
    </div>
  );
}
