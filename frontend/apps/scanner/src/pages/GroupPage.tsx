import { FormEvent, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  addMarkingToPack,
  createPack,
  registerMarking,
  scanCode,
  sealPack,
} from '@/shared/api/wms';
import { loadScannerContext, maskCode, patchScannerContext } from '@/shared/scannerContext';
import { wmsErrorMessage } from '@/shared/wmsMessages';

export function GroupPage() {
  const inputRef = useRef<HTMLInputElement>(null);
  const [draftPackId, setDraftPackId] = useState<number | null>(null);
  const [draftPackCode, setDraftPackCode] = useState<string | null>(null);
  const [markingCount, setMarkingCount] = useState(0);
  const [recentCodes, setRecentCodes] = useState<string[]>([]);
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);
  const [registerOpen, setRegisterOpen] = useState(false);
  const [registerProductId, setRegisterProductId] = useState('');
  const [pendingCode, setPendingCode] = useState('');

  useEffect(() => {
    const ctx = loadScannerContext();
    setDraftPackId(ctx.draft_pack_id ?? null);
    setDraftPackCode(ctx.draft_pack_code ?? null);
    setMarkingCount(ctx.group_marking_count || 0);
    setRecentCodes(ctx.group_recent_codes || []);
    patchScannerContext({ last_operation: 'group' });
    inputRef.current?.focus();
  }, []);

  function persistGroupState(
    packId: number | null,
    packCode: string | null,
    count: number,
    recent: string[],
  ) {
    patchScannerContext({
      draft_pack_id: packId,
      draft_pack_code: packCode,
      group_marking_count: count,
      group_recent_codes: recent,
    });
  }

  async function onNewGroup() {
    setLoading(true);
    setError('');
    setSuccess('');
    try {
      const guCode = `gu-${Date.now()}`;
      const { pack } = await createPack({ unit_type: 'group', code: guCode });
      const id = Number(pack.id);
      const packCode = String(pack.code || guCode);
      setDraftPackId(id);
      setDraftPackCode(packCode);
      setMarkingCount(0);
      setRecentCodes([]);
      persistGroupState(id, packCode, 0, []);
      setSuccess(`Создана ГУ ${packCode} (#${id})`);
      inputRef.current?.focus();
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  async function addCodeToPack(scanned: string) {
    if (!draftPackId) {
      setError('Сначала создайте новую ГУ');
      return;
    }
    await addMarkingToPack(draftPackId, scanned);
    const nextCount = markingCount + 1;
    const nextRecent = [maskCode(scanned), ...recentCodes].slice(0, 5);
    setMarkingCount(nextCount);
    setRecentCodes(nextRecent);
    persistGroupState(draftPackId, draftPackCode, nextCount, nextRecent);
    setSuccess(`В ГУ: ${nextCount} шт.`);
    setCode('');
    inputRef.current?.focus();
  }

  async function onScan(e: FormEvent) {
    e.preventDefault();
    if (!code.trim()) return;
    if (!draftPackId) {
      setError('Сначала создайте новую ГУ');
      return;
    }
    setLoading(true);
    setError('');
    setSuccess('');
    const scanned = code.trim();
    try {
      await addCodeToPack(scanned);
    } catch (err) {
      const message = wmsErrorMessage(err);
      if (message.includes('не найден') || message.includes('register')) {
        try {
          const lookup = await scanCode(scanned);
          if (lookup.kind === 'marking' && lookup.marking?.product_id) {
            setPendingCode(scanned);
            setRegisterProductId(String(lookup.marking.product_id));
            setRegisterOpen(true);
            return;
          }
        } catch {
          // fallback to manual register
        }
        setPendingCode(scanned);
        setRegisterOpen(true);
      } else {
        setError(message);
      }
    } finally {
      setLoading(false);
    }
  }

  async function onRegister(e: FormEvent) {
    e.preventDefault();
    const productId = Number(registerProductId);
    if (!productId || !pendingCode) return;
    setLoading(true);
    setError('');
    try {
      await registerMarking({ product_id: productId, code: pendingCode });
      setRegisterOpen(false);
      await addCodeToPack(pendingCode);
      setPendingCode('');
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  async function onSeal() {
    if (!draftPackId) return;
    setLoading(true);
    setError('');
    setSuccess('');
    try {
      const { pack } = await sealPack(draftPackId);
      const sealedCode = String(pack.code || draftPackCode);
      setSuccess(`ГУ запечатана: ${sealedCode}`);
      setDraftPackId(null);
      setDraftPackCode(null);
      setMarkingCount(0);
      setRecentCodes([]);
      persistGroupState(null, null, 0, []);
      if (pack.qr_payload) {
        setSuccess(`ГУ запечатана. QR: ${String(pack.qr_payload).slice(0, 80)}…`);
      }
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="sc-main">
      <p>
        <Link to="/" className="sc-muted">
          ← Hub
        </Link>
      </p>
      <div className="sc-panel">
        <h1 className="sc-title">Сборка ГУ</h1>
        <p className="sc-lead">
          {draftPackId
            ? `Черновик: ${draftPackCode || '—'} (#${draftPackId}) · КИЗ: ${markingCount}`
            : 'Создайте групповую упаковку и сканируйте КИЗ.'}
        </p>
        {success ? <div className="sc-success">{success}</div> : null}
        {error ? <p className="sc-error">{error}</p> : null}

        <div className="sc-actions" style={{ marginTop: '1rem' }}>
          {!draftPackId ? (
            <button type="button" className="sc-button" onClick={onNewGroup} disabled={loading}>
              Новая ГУ
            </button>
          ) : (
            <>
              <button type="button" className="sc-button sc-button-secondary" onClick={onSeal} disabled={loading || markingCount < 1}>
                Запечатать
              </button>
            </>
          )}
        </div>

        {draftPackId ? (
          <>
            <form onSubmit={onScan} style={{ marginTop: '1rem' }}>
              <label style={{ display: 'block' }}>
                Скан КИЗ
                <input
                  ref={inputRef}
                  className="sc-field sc-scan-input"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="DataMatrix / код маркировки"
                  autoComplete="off"
                />
              </label>
              <button type="submit" className="sc-button" style={{ marginTop: '0.85rem' }} disabled={loading}>
                {loading ? 'Добавление…' : 'Добавить в ГУ'}
              </button>
            </form>
            {recentCodes.length > 0 ? (
              <div style={{ marginTop: '1rem' }}>
                <strong>Последние коды</strong>
                <ul className="sc-recent-list">
                  {recentCodes.map((c) => (
                    <li key={c}>{c}</li>
                  ))}
                </ul>
              </div>
            ) : null}
          </>
        ) : null}
      </div>

      {registerOpen ? (
        <div className="sc-modal-backdrop" role="presentation" onClick={() => !loading && setRegisterOpen(false)}>
          <div className="sc-modal" role="dialog" onClick={(e) => e.stopPropagation()}>
            <h2 className="sc-title h5">Зарегистрировать КИЗ?</h2>
            <p className="sc-lead">Код не найден в БД. Укажите product_id для регистрации.</p>
            <form onSubmit={onRegister}>
              <label style={{ display: 'block', marginTop: '0.75rem' }}>
                product_id
                <input
                  className="sc-field"
                  type="number"
                  min={1}
                  value={registerProductId}
                  onChange={(e) => setRegisterProductId(e.target.value)}
                  required
                />
              </label>
              <p className="sc-muted small" style={{ marginTop: '0.5rem' }}>
                Код: {maskCode(pendingCode)}
              </p>
              <div className="sc-actions">
                <button type="submit" className="sc-button" disabled={loading}>
                  {loading ? 'Регистрация…' : 'Зарегистрировать и добавить'}
                </button>
                <button
                  type="button"
                  className="sc-button sc-button-secondary"
                  onClick={() => setRegisterOpen(false)}
                  disabled={loading}
                >
                  Отмена
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  );
}
