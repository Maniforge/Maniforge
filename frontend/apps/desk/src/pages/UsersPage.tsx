import { FormEvent, useState } from 'react';

export function UsersPage() {
  const [login, setLogin] = useState('');
  const [phone, setPhone] = useState('+7');
  const [password, setPassword] = useState('');
  const [msg, setMsg] = useState('');

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setMsg('');
    const token = localStorage.getItem('maniforge_access_token') || '';
    const tenant = localStorage.getItem('maniforge_tenant_code') || '';
    const res = await fetch('/rbac/api/v1/admin/users', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + token,
        'X-Tenant-ID': tenant,
      },
      body: JSON.stringify({ login, phone, password }),
    });
    const data = await res.json().catch(() => ({}));
    setMsg(res.ok ? 'Создан' : data.error || 'Ошибка ' + res.status);
  }

  return (
    <main className="main" style={{ maxWidth: '44rem', margin: '0 auto' }}>
      <p className="kicker">Identity · tenant_admin</p>
      <h1>Пользователи</h1>
      <p className="lead">Создание учёток в текущей организации.</p>
      <form className="card" onSubmit={onSubmit}>
        <label>
          Login
          <input value={login} onChange={(e) => setLogin(e.target.value)} />
        </label>
        <label>
          Телефон
          <input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
        </label>
        <label>
          Пароль (от 12 символов)
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <button className="btn" type="submit" style={{ marginTop: '1rem' }}>
          Создать
        </button>
        {msg ? <p className="msg">{msg}</p> : null}
      </form>
    </main>
  );
}
