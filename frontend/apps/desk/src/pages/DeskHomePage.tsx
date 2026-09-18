import { useEffect, useState } from 'react';

type Manifest = { code?: string; name?: string; origin?: string };

export function DeskHomePage() {
  const [items, setItems] = useState<Manifest[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    const token = localStorage.getItem('maniforge_access_token') || '';
    const tenant = localStorage.getItem('maniforge_tenant_code') || '';
    const sub = localStorage.getItem('maniforge_subtenant_code') || 'main';
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (token) headers.Authorization = 'Bearer ' + token;
    if (tenant) headers['X-Tenant-ID'] = tenant;
    if (sub) headers['X-Subtenant-ID'] = sub;

    fetch('/manifest-engine/api/v1/manifests', { headers })
      .then(async (res) => {
        const text = await res.text();
        let data: { error?: string; items?: Manifest[]; manifests?: Manifest[] } = {};
        if (text) {
          try {
            data = JSON.parse(text);
          } catch {
            throw new Error('Manifest Engine: ответ не JSON (' + res.status + ')');
          }
        }
        if (!res.ok) {
          throw new Error(data.error || 'Ошибка списка сущностей ' + res.status);
        }
        return data;
      })
      .then((data) => {
        const list = data.items || data.manifests || [];
        setItems(Array.isArray(list) ? list : []);
      })
      .catch((err) => setError(String(err)));
  }, []);

  return (
    <div className="shell">
      <aside className="side">
        <h2>Manifest</h2>
        {items.map((m) => (
          <a key={m.code} className="entity-btn" href={'/desk/?entity=' + encodeURIComponent(m.code || '')}>
            {m.code}
          </a>
        ))}
      </aside>
      <main className="main">
        <p className="kicker">Framework · Desk</p>
        <h1>Сущности</h1>
        <p className="lead">Список Manifest из Manifest Engine.</p>
        {error ? <p className="msg">{error}</p> : null}
        {!error && items.length === 0 ? (
          <p className="muted">Пока нет сущностей. Их создаёт Manifest Engine после описания полей.</p>
        ) : null}
        <div className="grid">
          {items.map((m) => (
            <a key={m.code} className="tile" href={'/desk/?entity=' + encodeURIComponent(m.code || '')}>
              <strong>{m.name || m.code}</strong>
              <span className="muted">{m.code}</span>
            </a>
          ))}
        </div>
      </main>
    </div>
  );
}
