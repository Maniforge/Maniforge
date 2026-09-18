import { FormEvent, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ScanPreviewCard } from '@/components/ScanPreviewCard';
import { scanCode, type ScanResult } from '@/shared/api/wms';
import { patchScannerContext } from '@/shared/scannerContext';
import { wmsErrorMessage } from '@/shared/wmsMessages';

export function ScanPage() {
  const inputRef = useRef<HTMLInputElement>(null);
  const [code, setCode] = useState('');
  const [result, setResult] = useState<ScanResult | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    patchScannerContext({ last_operation: 'lookup' });
    inputRef.current?.focus();
  }, []);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (!code.trim()) return;
    setLoading(true);
    setError('');
    setResult(null);
    try {
      const payload = await scanCode(code);
      setResult(payload);
    } catch (err) {
      setError(wmsErrorMessage(err));
    } finally {
      setLoading(false);
      inputRef.current?.select();
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
        <h1 className="sc-title">Скан-инфо</h1>
        <p className="sc-lead">Lookup кода без проведения движения.</p>
        <form onSubmit={onSubmit}>
          <label style={{ display: 'block', marginTop: '1rem' }}>
            Код
            <input
              ref={inputRef}
              className="sc-field sc-scan-input"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder="Сканируйте или введите код"
              autoComplete="off"
            />
          </label>
          <button type="submit" className="sc-button" style={{ marginTop: '0.85rem' }} disabled={loading}>
            {loading ? 'Проверка…' : 'Отправить'}
          </button>
        </form>
        {error ? <p className="sc-error" style={{ marginTop: '0.75rem' }}>{error}</p> : null}
        {result ? (
          <div style={{ marginTop: '1rem' }}>
            <ScanPreviewCard result={result} />
          </div>
        ) : null}
      </div>
    </div>
  );
}
