import { FormEvent, useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  addChildToPack,
  createPack,
  getPack,
  scanCode,
  sealPack,
} from '@/shared/api/wms';
import { loadScannerContext, patchScannerContext } from '@/shared/scannerContext';
import { wmsErrorMessage } from '@/shared/wmsMessages';

export function PalletPage() {
  const navigate = useNavigate();
  const inputRef = useRef<HTMLInputElement>(null);
  const [stockId, setStockId] = useState<number | null>(null);
  const [palletId, setPalletId] = useState<number | null>(null);
  const [palletCode, setPalletCode] = useState<string | null>(null);
  const [palletSscc, setPalletSscc] = useState<string | null>(null);
  const [childCount, setChildCount] = useState(0);
  const [recentChildren, setRecentChildren] = useState<string[]>([]);
  const [sealedPack, setSealedPack] = useState<Record<string, unknown> | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const ctx = loadScannerContext();
    setStockId(ctx.stock_id);
    setPalletId(ctx.draft_pallet_id ?? null);
    setPalletCode(ctx.draft_pallet_code ?? null);
    setPalletSscc(ctx.draft_pallet_sscc ?? null);
    setChildCount(ctx.pallet_child_count || 0);
    setRecentChildren(ctx.pallet_recent_children || []);
    patchScannerContext({ last_operation: 'pallet' });
    inputRef.current?.focus();
  }, []);

  function persistPalletState(
    id: number | null,
    packCode: string | null,
    sscc: string | null,
    count: number,
    recent: string[],
  ) {
    patchScannerContext({
      draft_pallet_id: id,
      draft_pallet_code: packCode,
      draft_pallet_sscc: sscc,
      pallet_child_count: count,
      pallet_recent_children: recent,
    });
  }

  async function refreshChildCount(id: number) {
    const { contents } = await getPack(id);
    const count = contents?.length ?? 0;
    setChildCount(count);
    return count;
  }

  async function onNewPallet() {
    if (!stockId) {
      setError('Выберите узел склада на Hub');
      return;
    }
    setLoading(true);
    setError('');
    setSuccess('');
    setSealedPack(null);
    try {
      const { pack } = await createPack({ unit_type: 'pallet', stock_id: stockId });
      const id = Number(pack.id);
      const packCode = String(pack.code || `pallet-${id}`);
      const sscc = pack.sscc ? String(pack.sscc) : null;
      setPalletId(id);
      setPalletCode(packCode);
      setPalletSscc(sscc);
      setChildCount(0);
      setRecentChildren([]);
      persistPalletState(id, packCode, sscc, 0, []);
      setSuccess(`Паллета ${packCode}${sscc ? ` · SSCC ${sscc}` : ''}`);
      inputRef.current?.focus();
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  async function onScanChild(e: FormEvent) {
    e.preventDefault();
    if (!code.trim() || !palletId) return;
    setLoading(true);
    setError('');
    setSuccess('');
    const scanned = code.trim();
    try {
      const resolved = await scanCode(scanned);
      if (resolved.kind !== 'pack') {
        setError('Ожидается запечатанная групповая упаковка (pack)');
        return;
      }
      const child = resolved.pack || {};
      const unitType = String(child.unit_type || '');
      const status = String(child.status || '');
      if (unitType !== 'group') {
        setError('На паллету вкладываются только sealed ГУ (group)');
        return;
      }
      if (status !== 'sealed') {
        setError('ГУ должна быть запечатана (sealed)');
        return;
      }
      const childId = Number(child.id);
      if (!childId) {
        setError('Не удалось определить ID упаковки');
        return;
      }
      await addChildToPack(palletId, childId);
      const label = String(child.code || `#${childId}`);
      const nextRecent = [label, ...recentChildren].slice(0, 5);
      const count = await refreshChildCount(palletId);
      setRecentChildren(nextRecent);
      persistPalletState(palletId, palletCode, palletSscc, count, nextRecent);
      setSuccess(`Вложено: ${label}. На паллете: ${count}`);
      setCode('');
      inputRef.current?.focus();
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  async function onSeal() {
    if (!palletId) return;
    setLoading(true);
    setError('');
    setSuccess('');
    try {
      const { pack } = await sealPack(palletId);
      setSealedPack(pack);
      const sscc = pack.sscc ? String(pack.sscc) : palletSscc;
      setPalletId(null);
      setPalletCode(null);
      setPalletSscc(null);
      setChildCount(0);
      setRecentChildren([]);
      persistPalletState(null, null, null, 0, []);
      setSuccess(`Паллета запечатана${sscc ? ` · SSCC ${sscc}` : ''}`);
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  function goToReceipt() {
    if (!sealedPack) return;
    const prefill =
      (sealedPack.qr_payload ? String(sealedPack.qr_payload) : '') ||
      (sealedPack.sscc ? String(sealedPack.sscc) : '') ||
      (sealedPack.code ? String(sealedPack.code) : '');
    if (prefill) {
      patchScannerContext({ receipt_prefill_scan: prefill });
    }
    navigate('/receipt');
  }

  return (
    <div className="sc-main">
      <p>
        <Link to="/" className="sc-muted">
          ← Hub
        </Link>
      </p>
      <div className="sc-panel">
        <h1 className="sc-title">Паллета</h1>
        <p className="sc-lead">
          {palletId
            ? `Черновик #${palletId} · ${palletCode || '—'}${palletSscc ? ` · ${palletSscc}` : ''} · ГУ: ${childCount}`
            : 'Создайте паллету и сканируйте запечатанные ГУ.'}
        </p>
        {!stockId ? (
          <p className="sc-error">
            <Link to="/">Выберите stock_id на Hub</Link>.
          </p>
        ) : null}
        {success ? <div className="sc-success">{success}</div> : null}
        {error ? <p className="sc-error">{error}</p> : null}

        {!sealedPack ? (
          <div className="sc-actions" style={{ marginTop: '1rem' }}>
            {!palletId ? (
              <button type="button" className="sc-button" onClick={onNewPallet} disabled={loading || !stockId}>
                Новая паллета
              </button>
            ) : (
              <button
                type="button"
                className="sc-button sc-button-secondary"
                onClick={onSeal}
                disabled={loading || childCount < 1}
              >
                Запечатать паллету
              </button>
            )}
          </div>
        ) : null}

        {palletId && !sealedPack ? (
          <>
            <form onSubmit={onScanChild} style={{ marginTop: '1rem' }}>
              <label style={{ display: 'block' }}>
                Скан sealed ГУ
                <input
                  ref={inputRef}
                  className="sc-field sc-scan-input"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="QR / code групповой упаковки"
                  autoComplete="off"
                />
              </label>
              <button type="submit" className="sc-button" style={{ marginTop: '0.85rem' }} disabled={loading}>
                {loading ? 'Добавление…' : 'Вложить в паллету'}
              </button>
            </form>
            {recentChildren.length > 0 ? (
              <div style={{ marginTop: '1rem' }}>
                <strong>Вложенные ГУ</strong>
                <ul className="sc-recent-list">
                  {recentChildren.map((c) => (
                    <li key={c}>{c}</li>
                  ))}
                </ul>
              </div>
            ) : null}
          </>
        ) : null}

        {sealedPack ? (
          <div className="sc-preview" style={{ marginTop: '1rem' }}>
            <strong>SSCC</strong>
            <p className="sc-lead">{String(sealedPack.sscc || '—')}</p>
            {sealedPack.qr_payload ? (
              <>
                <strong>QR payload</strong>
                <pre className="sc-qr-payload">{String(sealedPack.qr_payload)}</pre>
              </>
            ) : null}
            <div className="sc-actions">
              <button type="button" className="sc-button" onClick={goToReceipt}>
                Принять на склад
              </button>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}
