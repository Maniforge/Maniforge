import type { ReactNode } from 'react';
import type { ScanResult } from '@/shared/api/wms';
import { scanPreviewDetails, scanPreviewTitle } from '@/shared/scanPreview';

type Props = {
  result: ScanResult;
  hint?: string;
  qty?: string;
  onQtyChange?: (value: string) => void;
  children?: ReactNode;
};

export function ScanPreviewCard({ result, hint, qty, onQtyChange, children }: Props) {
  const details = scanPreviewDetails(result);

  return (
    <div className="sc-preview">
      <div className="sc-preview-head">
        <span className="sc-preview-kind">{result.kind || '—'}</span>
        <strong>{scanPreviewTitle(result)}</strong>
      </div>
      {details.length > 0 ? (
        <ul className="sc-preview-list">
          {details.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      ) : null}
      {result.kind === 'product' && onQtyChange ? (
        <label style={{ display: 'block', marginTop: '0.75rem' }}>
          Количество
          <input
            className="sc-field"
            type="number"
            min="0.001"
            step="any"
            value={qty || '1'}
            onChange={(e) => onQtyChange(e.target.value)}
          />
        </label>
      ) : null}
      {hint ? <p className="sc-lead" style={{ marginTop: '0.75rem' }}>{hint}</p> : null}
      {children}
    </div>
  );
}
