import { useEffect, useState } from 'react';

type Manifest = { code?: string; name?: string; origin?: string };

export function DeskHomePage() {
  const [items, setItems] = useState<Manifest[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    const token = localStorage.getItem('maniforge_access_token') || '';
    fetch('/manifest/api/v1/manifests', {
      headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
    })
      .then((res) => res.json())
      .then((data) => {
        const list = data.items || data.manifests || data || [];
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
