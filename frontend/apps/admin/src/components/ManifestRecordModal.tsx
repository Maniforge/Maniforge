import { FormEvent, useEffect, useState } from 'react';
import type { Manifest, ManifestRecord } from '@/shared/api/manifest';

type Props = {
  open: boolean;
  manifest: Manifest | null;
  record: ManifestRecord | null;
  saving: boolean;
  onClose: () => void;
  onSave: (data: Record<string, unknown>) => void;
};

export function ManifestRecordModal({ open, manifest, record, saving, onClose, onSave }: Props) {
  const [values, setValues] = useState<Record<string, unknown>>({});

  useEffect(() => {
    if (!open || !manifest) {
      return;
    }
    const initial: Record<string, unknown> = {};
    for (const field of manifest.fields || []) {
      const raw = record ? record.data?.[field.name] : undefined;
      if (field.type === 'boolean') {
        initial[field.name] = Boolean(raw);
      } else if (raw !== undefined && raw !== null) {
        initial[field.name] = raw;
      } else {
        initial[field.name] = '';
      }
    }
    setValues(initial);
  }, [open, manifest, record]);

  if (!open || !manifest) {
    return null;
  }

  const activeManifest = manifest;

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    const data: Record<string, unknown> = {};
    for (const field of activeManifest.fields || []) {
      const raw = values[field.name];
      if (field.type === 'boolean') {
        data[field.name] = Boolean(raw);
      } else if (field.type === 'number') {
        data[field.name] = raw === '' || raw === null ? null : Number(raw);
      } else {
        data[field.name] = raw ?? '';
      }
    }
    onSave(data);
  }

  return (
    <div className="mf-modal-backdrop" role="presentation" onClick={onClose}>
      <div
        className="mf-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mf-modal-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mf-modal-head">
          <h2 id="mf-modal-title" className="mf-title h5 mb-0">
            {record ? `Изменить #${record.id}` : `Создать — ${activeManifest.name}`}
          </h2>
          <button type="button" className="mf-modal-close" onClick={onClose} aria-label="Закрыть">
            <i className="bi bi-x-lg" aria-hidden="true" />
          </button>
        </div>
        <form onSubmit={onSubmit}>
          <div className="mf-modal-body">
            {(activeManifest.fields || []).map((field) => (
              <label key={field.name} style={{ display: 'block', marginBottom: '0.85rem' }}>
                {field.name}
                {field.required ? ' *' : ''}
                {field.type === 'boolean' ? (
                  <input
                    className="mf-field"
                    type="checkbox"
                    checked={Boolean(values[field.name])}
                    onChange={(e) => setValues((v) => ({ ...v, [field.name]: e.target.checked }))}
                    style={{ width: 'auto', marginTop: '0.5rem' }}
                  />
                ) : (
                  <input
                    className="mf-field"
                    type={field.type === 'number' ? 'number' : 'text'}
                    value={String(values[field.name] ?? '')}
                    onChange={(e) => setValues((v) => ({ ...v, [field.name]: e.target.value }))}
                    required={Boolean(field.required)}
                  />
                )}
              </label>
            ))}
          </div>
          <div className="mf-modal-foot">
            <button type="button" className="mf-button mf-button-secondary" onClick={onClose} disabled={saving}>
              Отмена
            </button>
            <button type="submit" className="mf-button" disabled={saving}>
              {saving ? 'Сохранение…' : 'Сохранить'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
