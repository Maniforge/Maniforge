import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listStocksForScanner } from '@/shared/api/warehouses';
import { loadScannerContext, patchScannerContext } from '@/shared/scannerContext';

const OPERATIONS = [
  { href: '/scan', title: 'Скан-инфо', icon: 'bi-upc-scan', desc: 'Lookup без движения' },
  { href: '/receipt', title: 'Приёмка', icon: 'bi-box-arrow-in-down', desc: 'receipt по скану' },
  { href: '/issue', title: 'Отгрузка', icon: 'bi-box-arrow-up', desc: 'issue по скану' },
  { href: '/group', title: 'Сборка ГУ', icon: 'bi-collection', desc: 'КИЗ → seal' },
  { href: '/pallet', title: 'Паллета', icon: 'bi-box-seam', desc: 'ГУ → seal → приёмка' },
] as const;

export function HubPage() {
  const [stocks, setStocks] = useState<Array<{ id: number; name?: string; type?: string }>>([]);
  const [stockId, setStockId] = useState<string>('');
  const [error, setError] = useState('');

  useEffect(() => {
    const ctx = loadScannerContext();
    if (ctx.stock_id) {
      setStockId(String(ctx.stock_id));
    }
    listStocksForScanner()
      .then((items) => setStocks(items))
      .catch((err: Error) => setError(err.message));
  }, []);

  function onStockChange(value: string) {
    setStockId(value);
    patchScannerContext({
      stock_id: value ? Number(value) : null,
    });
  }

  return (
    <div className="sc-main">
      <div className="sc-panel">
        <h1 className="sc-title">Склад</h1>
        <p className="sc-lead">Выберите узел склада для операций.</p>
        <label style={{ display: 'block', marginTop: '1rem' }}>
          Узел (stock_id)
          <select className="sc-field" value={stockId} onChange={(e) => onStockChange(e.target.value)}>
            <option value="">— не выбран —</option>
            {stocks.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name || `Узел #${s.id}`} ({s.type || '—'})
              </option>
            ))}
          </select>
        </label>
        {error ? <p className="sc-error" style={{ marginTop: '0.5rem' }}>{error}</p> : null}
      </div>

      <div className="sc-grid">
        {OPERATIONS.map((op) => (
          <Link key={op.href} to={op.href} className="sc-op-card">
            <i className={`bi ${op.icon}`} aria-hidden="true" style={{ fontSize: '1.4rem' }} />
            <strong>{op.title}</strong>
            <small>{op.desc}</small>
          </Link>
        ))}
      </div>
    </div>
  );
}
