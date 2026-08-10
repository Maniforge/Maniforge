import { FormEvent, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ScanPreviewCard } from '@/components/ScanPreviewCard';
import {
  movementDocNumber,
  postMovementScan,
  scanCode,
  type MovementType,
  type ScanResult,
} from '@/shared/api/wms';
import { loadScannerContext, patchScannerContext } from '@/shared/scannerContext';
import { movementConfirmHint } from '@/shared/scanPreview';
import { wmsErrorMessage } from '@/shared/wmsMessages';

type Props = {
  movementType: 'receipt' | 'issue';
};

export function MovementPage({ movementType }: Props) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [stockId, setStockId] = useState<number | null>(null);
  const [code, setCode] = useState('');
  const [preview, setPreview] = useState<ScanResult | null>(null);
  const [qty, setQty] = useState('1');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [sessionCount, setSessionCount] = useState(0);

  const title = movementType === 'receipt' ? 'Приёмка' : 'Отгрузка';
  const docPrefix = movementType === 'receipt' ? 'rcv-scanner' : 'iss-scanner';
  const countKey = movementType === 'receipt' ? 'receipt_count' : 'issue_count';

  useEffect(() => {
    const ctx = loadScannerContext();
    setStockId(ctx.stock_id);
    setSessionCount(ctx[countKey] || 0);
    if (movementType === 'receipt' && ctx.receipt_prefill_scan) {
      setCode(ctx.receipt_prefill_scan);
      patchScannerContext({ receipt_prefill_scan: undefined });
    }
    patchScannerContext({ last_operation: movementType });
    inputRef.current?.focus();
  }, [movementType, countKey]);

  async function onScan(e: FormEvent) {
    e.preventDefault();
    if (!code.trim()) return;
    if (!stockId) {
      setError('Выберите узел склада на Hub');
      return;
    }
    setLoading(true);
    setError('');
    setSuccess('');
    setPreview(null);
    try {
      const result = await scanCode(code);
      if (movementType === 'receipt' && result.kind === 'pack') {
        const status = String(result.pack?.status || '');
        if (status && status !== 'sealed') {
          setError('Упаковка не запечатана — приёмка только sealed');
          return;
        }
      }
      setPreview(result);
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  async function onConfirm() {
    if (!preview || !stockId || !code.trim()) return;
    setConfirming(true);
    setError('');
    setSuccess('');
    try {
      const payload: Parameters<typeof postMovementScan>[0] = {
        movement_type: movementType as MovementType,
        stock_id: stockId,
        scan: code.trim(),
        doc_number: movementDocNumber(docPrefix),
      };
      if (preview.kind === 'product') {
        payload.qty = qty || '1';
      }
      const result = await postMovementScan(payload);
      const movement = result.movement as { doc_number?: string } | undefined;
      const doc = String(movement?.doc_number || result.doc_number || '—');
      const nextCount = sessionCount + 1;
      setSessionCount(nextCount);
      patchScannerContext({ [countKey]: nextCount });
      setSuccess(
        movementType === 'receipt'
          ? `Принято (${nextCount}). Документ: ${doc}`
          : `Списано (${nextCount}). Документ: ${doc}`,
      );
      setPreview(null);
      setCode('');
      inputRef.current?.focus();
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setConfirming(false);
    }
  }

  function resetPreview() {
    setPreview(null);
    setError('');
    inputRef.current?.focus();
  }

  return (
    <div className="sc-main">
      <p>
        <Link to="/" className="sc-muted">
          ← Hub
        </Link>
      </p>
      <div className="sc-panel">
        <h1 className="sc-title">{title}</h1>
        <p className="sc-lead">
          Скан → preview → подтверждение. Узел: {stockId ? `#${stockId}` : 'не выбран'}.
          {sessionCount > 0 ? ` · Сессия: ${sessionCount}` : null}
        </p>
        {!stockId ? (
          <p className="sc-error">
            <Link to="/">Выберите stock_id на Hub</Link> перед операцией.
          </p>
        ) : null}
        {success ? <div className="sc-success">{success}</div> : null}
        {error ? <p className="sc-error">{error}</p> : null}

        {!preview ? (
          <form onSubmit={onScan}>
            <label style={{ display: 'block', marginTop: '1rem' }}>
              Код
              <input
                ref={inputRef}
                className="sc-field sc-scan-input"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                placeholder="SSCC, QR, КИЗ или EAN-13"
                autoComplete="off"
                disabled={!stockId}
              />
            </label>
            <button
              type="submit"
              className="sc-button"
              style={{ marginTop: '0.85rem' }}
              disabled={loading || !stockId}
            >
              {loading ? 'Скан…' : 'Сканировать'}
            </button>
          </form>
        ) : (
          <>
            <ScanPreviewCard
              result={preview}
              hint={movementConfirmHint(preview, movementType)}
              qty={qty}
              onQtyChange={preview.kind === 'product' ? setQty : undefined}
            >
              <div className="sc-actions">
                <button type="button" className="sc-button" onClick={onConfirm} disabled={confirming}>
                  {confirming ? 'Проведение…' : 'Подтвердить'}
                </button>
                <button
                  type="button"
                  className="sc-button sc-button-secondary"
                  onClick={resetPreview}
                  disabled={confirming}
                >
                  Отмена
                </button>
              </div>
            </ScanPreviewCard>
          </>
        )}
      </div>
    </div>
  );
}
