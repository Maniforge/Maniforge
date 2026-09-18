import { FormEvent, useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { StockAuditPanel } from '@/components/StockAuditPanel';
import { StockTree } from '@/components/StockTree';
import {
  createStock,
  fetchStockTree,
  listStockTypes,
  type StockNode,
} from '@/shared/api/warehouses';

export function WarehousesPage() {
  const [tree, setTree] = useState<StockNode[]>([]);
  const [flatCount, setFlatCount] = useState(0);
  const [types, setTypes] = useState<Array<{ code: string; name: string }>>([]);
  const [status, setStatus] = useState('Загрузка…');
  const [error, setError] = useState('');
  const [reloadAt, setReloadAt] = useState(0);

  const [name, setName] = useState('');
  const [type, setType] = useState('');
  const [parentId, setParentId] = useState('');
  const [creating, setCreating] = useState(false);
  const [selected, setSelected] = useState<StockNode | null>(null);

  const reload = useCallback(() => setReloadAt((n) => n + 1), []);

  useEffect(() => {
    listStockTypes()
      .then((items) => {
        setTypes(items);
        if (items.length > 0) {
          setType(items[0].code);
        }
      })
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    setStatus('Загрузка дерева…');
    setError('');
    fetchStockTree()
      .then((result) => {
        setTree(result.tree);
        setFlatCount(result.flat_count);
        setStatus(`Узлов: ${result.flat_count}`);
      })
      .catch((err: Error) => {
        setError(err.message);
        setStatus('');
      });
  }, [reloadAt]);

  async function onCreate(e: FormEvent) {
    e.preventDefault();
    if (!name.trim() || !type) return;
    setCreating(true);
    setError('');
    try {
      await createStock({
        name: name.trim(),
        type,
        parent_id: parentId ? Number(parentId) : null,
      });
      setName('');
      setParentId('');
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setCreating(false);
    }
  }

  return (
    <section>
      <div className="mf-page-head">
        <Link to="/dashboard" className="mf-muted" style={{ fontSize: '0.875rem' }}>
          ← К модулям
        </Link>
        <h1 className="mf-title" style={{ marginTop: '0.5rem' }}>
          Warehouses
        </h1>
        <p className="mf-lead">Дерево складских узлов (Go :8098). Требуется permission warehouses.read.</p>
      </div>

      <div className="mf-panel">
        <div className="mf-toolbar">
          <button type="button" className="mf-button mf-button-secondary" onClick={reload}>
            <i className="bi bi-arrow-clockwise" aria-hidden="true" />
            Обновить
          </button>
        </div>
        {error ? <p className="mf-error">{error}</p> : null}
        {status ? <p className="mf-muted small">{status}</p> : null}
        <StockTree
          nodes={tree}
          selectedId={selected?.id ?? null}
          onSelect={setSelected}
        />
        {flatCount > 0 ? (
          <p className="mf-muted small mb-0 mt-2">Всего узлов в scope: {flatCount}</p>
        ) : null}
      </div>

      <div className="mf-panel" style={{ marginTop: '1rem' }}>
        <StockAuditPanel
          stockId={selected?.id ?? null}
          stockLabel={selected ? `${selected.name || 'Узел'} (#${selected.id})` : undefined}
        />
      </div>

      <div className="mf-panel" style={{ marginTop: '1rem' }}>
        <h2 className="mf-title h5">Создать узел</h2>
        <p className="mf-lead">Минимальная форма: name + type (+ parent_id для вложенных типов).</p>
        <form onSubmit={onCreate} className="mf-toolbar" style={{ alignItems: 'end' }}>
          <label>
            Название *
            <input className="mf-field" value={name} onChange={(e) => setName(e.target.value)} required />
          </label>
          <label>
            Тип *
            <select className="mf-field" value={type} onChange={(e) => setType(e.target.value)} required>
              {types.map((t) => (
                <option key={t.code} value={t.code}>
                  {t.name} ({t.code})
                </option>
              ))}
            </select>
          </label>
          <label>
            parent_id
            <input
              className="mf-field"
              type="number"
              min={0}
              placeholder="опционально"
              value={parentId}
              onChange={(e) => setParentId(e.target.value)}
            />
          </label>
          <button type="submit" className="mf-button" disabled={creating}>
            {creating ? 'Создание…' : 'Создать'}
          </button>
        </form>
      </div>
    </section>
  );
}
