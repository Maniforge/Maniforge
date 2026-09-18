import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ManifestRecordModal } from '@/components/ManifestRecordModal';
import {
  createRecord,
  deleteRecord,
  fieldValue,
  listManifests,
  listRecords,
  updateRecord,
  type Manifest,
  type ManifestRecord,
} from '@/shared/api/manifest';
import { useRealtime } from '@/shared/hooks/useRealtime';

export function ManifestPage() {
  const [manifests, setManifests] = useState<Manifest[]>([]);
  const [selected, setSelected] = useState('');
  const [records, setRecords] = useState<ManifestRecord[]>([]);
  const [total, setTotal] = useState<number | undefined>();
  const [status, setStatus] = useState('Загрузка…');
  const [error, setError] = useState('');
  const [reloadAt, setReloadAt] = useState(0);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<ManifestRecord | null>(null);
  const [saving, setSaving] = useState(false);
  const [liveStatus, setLiveStatus] = useState<string | null>(null);

  const current = manifests.find((m) => m.code === selected) || null;
  const fields = current?.fields || [];

  const realtimeChannels = useMemo(
    () => (selected ? ['entity.all', `data.${selected}`, `entity.${selected}`] : ['entity.all']),
    [selected],
  );

  const reload = useCallback(() => setReloadAt((n) => n + 1), []);

  const { status: wsStatus } = useRealtime({
    channels: realtimeChannels,
    enabled: Boolean(selected),
    onEvent: (event) => {
      const entity = event.payload?.entity;
      const ev = event.payload?.event || '';
      if (!entity || entity === selected) {
        if (ev.startsWith('record.')) {
          setLiveStatus(`Live: ${ev} #${event.payload?.record_id ?? '—'}`);
          reload();
        }
      }
    },
  });

  useEffect(() => {
    listManifests()
      .then((items) => {
        setManifests(items);
        if (items.length > 0) {
          setSelected(items[0].code);
        } else {
          setStatus('Нет manifest. Запустите make run-manifest и manifest-journey.');
        }
      })
      .catch((err: Error) => {
        setError(err.message);
        setStatus('');
      });
  }, []);

  useEffect(() => {
    if (!selected) {
      return;
    }
    setStatus('Загрузка записей…');
    setError('');
    listRecords(selected)
      .then((result) => {
        setRecords(result.records);
        setTotal(result.meta?.total);
        setStatus(
          `Записей: ${result.records.length}${result.meta?.total != null ? ` / ${result.meta.total}` : ''}`,
        );
      })
      .catch((err: Error) => {
        setError(err.message);
        setStatus('');
      });
  }, [selected, reloadAt]);

  async function onSave(data: Record<string, unknown>) {
    if (!selected) return;
    setSaving(true);
    setError('');
    try {
      if (editing) {
        await updateRecord(selected, editing.id, data);
      } else {
        await createRecord(selected, data);
      }
      setModalOpen(false);
      setEditing(null);
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setSaving(false);
    }
  }

  async function onDelete(record: ManifestRecord) {
    if (!selected) return;
    if (!window.confirm(`Удалить запись #${record.id}?`)) return;
    setError('');
    try {
      await deleteRecord(selected, record.id);
      reload();
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    }
  }

  return (
    <section>
      <div className="mf-page-head">
        <Link to="/dashboard" className="mf-muted" style={{ fontSize: '0.875rem' }}>
          ← К модулям
        </Link>
        <h1 className="mf-title" style={{ marginTop: '0.5rem' }}>
          Manifest Engine
        </h1>
        <p className="mf-lead">CRUD записей + live-обновление через Realtime (:8097).</p>
      </div>

      <div className="mf-panel">
        <div className="mf-toolbar">
          <label>
            Manifest
            <select
              className="mf-field"
              style={{ minWidth: '14rem' }}
              value={selected}
              onChange={(e) => setSelected(e.target.value)}
            >
              {manifests.map((m) => (
                <option key={m.code} value={m.code}>
                  {m.name} ({m.code})
                </option>
              ))}
            </select>
          </label>
          <button type="button" className="mf-button mf-button-secondary" onClick={reload}>
            <i className="bi bi-arrow-clockwise" aria-hidden="true" />
            Обновить
          </button>
          <button
            type="button"
            className="mf-button"
            disabled={!current}
            onClick={() => {
              setEditing(null);
              setModalOpen(true);
            }}
          >
            <i className="bi bi-plus-lg" aria-hidden="true" />
            Создать
          </button>
        </div>

        <p className="mf-muted small mb-2">
          WebSocket: {wsStatus}
          {liveStatus ? ` · ${liveStatus}` : null}
        </p>
        {error ? <p className="mf-error">{error}</p> : null}
        {status ? <p className="mf-muted small">{status}</p> : null}

        <div className="mf-table-wrap">
          <table className="mf-table">
            <thead>
              <tr>
                <th>ID</th>
                {fields.map((f) => (
                  <th key={f.name}>{f.name}</th>
                ))}
                <th />
              </tr>
            </thead>
            <tbody>
              {records.length === 0 ? (
                <tr>
                  <td colSpan={fields.length + 2} className="mf-muted">
                    Нет записей
                  </td>
                </tr>
              ) : (
                records.map((row) => (
                  <tr key={row.id}>
                    <td>
                      <code>{row.id}</code>
                    </td>
                    {fields.map((f) => {
                      const v = fieldValue(row, f.name);
                      return (
                        <td key={f.name}>
                          {typeof v === 'object' ? JSON.stringify(v) : String(v ?? '')}
                        </td>
                      );
                    })}
                    <td className="mf-row-actions">
                      <button
                        type="button"
                        className="mf-button mf-button-secondary mf-button-sm"
                        onClick={() => {
                          setEditing(row);
                          setModalOpen(true);
                        }}
                      >
                        Изменить
                      </button>
                      <button
                        type="button"
                        className="mf-button mf-button-secondary mf-button-sm mf-button-danger-text"
                        onClick={() => onDelete(row)}
                      >
                        Удалить
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {total != null ? <p className="mf-muted small mb-0">Всего в meta: {total}</p> : null}
      </div>

      <ManifestRecordModal
        open={modalOpen}
        manifest={current}
        record={editing}
        saving={saving}
        onClose={() => {
          if (!saving) {
            setModalOpen(false);
            setEditing(null);
          }
        }}
        onSave={onSave}
      />
    </section>
  );
}
