import { useEffect, useState } from 'react';
import { fetchStockAudit, type StockAuditEntry } from '@/shared/api/warehouses';

const EVENT_LABELS: Record<string, string> = {
  'warehouses.stock.created': 'Создание узла',
  'warehouses.stock.updated': 'Изменение узла',
  'warehouses.stock.archived': 'Архивация',
  'warehouses.stock.external_bound': 'Привязка external',
};

type Props = {
  stockId: number | null;
  stockLabel?: string;
};

export function StockAuditPanel({ stockId, stockLabel }: Props) {
  const [items, setItems] = useState<StockAuditEntry[]>([]);
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!stockId) {
      setItems([]);
      setStatus('');
      setError('');
      return;
    }
    setStatus('Загрузка audit…');
    setError('');
    fetchStockAudit(stockId)
      .then((result) => {
        setItems(result.items);
        setStatus(`Событий: ${result.items.length}`);
      })
      .catch((err: Error) => {
        setError(err.message);
        setStatus('');
        setItems([]);
      });
  }, [stockId]);

  if (!stockId) {
    return (
      <p className="mf-muted mb-0">Выберите узел в дереве, чтобы просмотреть журнал audit.</p>
    );
  }

  function actorLabel(entry: StockAuditEntry): string {
    const u = entry.actor_user;
    if (!u) return '—';
    return u.display_name || u.email || u.phone || (u.id ? `#${u.id}` : '—');
  }

  function eventLabel(type: string): string {
    return EVENT_LABELS[type] || type;
  }

  return (
    <div>
      <h2 className="mf-title h5">
        Audit — {stockLabel || `узел #${stockId}`}
      </h2>
      <p className="mf-lead">Журнал событий warehouses.* (permission warehouses.audit.read).</p>
      {error ? <p className="mf-error">{error}</p> : null}
      {status ? <p className="mf-muted small">{status}</p> : null}
      <div className="mf-table-wrap">
        <table className="mf-table">
          <thead>
            <tr>
              <th>Время</th>
              <th>Событие</th>
              <th>Актор</th>
              <th>Детали</th>
            </tr>
          </thead>
          <tbody>
            {items.length === 0 ? (
              <tr>
                <td colSpan={4} className="mf-muted">
                  Нет записей audit для этого узла
                </td>
              </tr>
            ) : (
              items.map((entry) => (
                <tr key={entry.id}>
                  <td className="mf-audit-time">{entry.created_at || '—'}</td>
                  <td>
                    <span className="mf-audit-event">{eventLabel(entry.event_type)}</span>
                    <code className="mf-tree-code d-block">{entry.event_type}</code>
                  </td>
                  <td>{actorLabel(entry)}</td>
                  <td>
                    <code className="mf-audit-payload">
                      {entry.payload ? JSON.stringify(entry.payload) : '—'}
                    </code>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
