import { useAuth } from '@/shared/auth/AuthContext';
import { contextLabel, flattenContexts } from '@/shared/auth/session';

export function ContextSwitcher() {
  const { contexts, switchContext } = useAuth();
  if (!contexts) {
    return null;
  }
  const items = flattenContexts(contexts);
  const current = contexts.current;
  if (items.length <= 1) {
    return (
      <span className="mf-muted" style={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.85rem' }}>
        {current?.tenant_id ?? '—'} / {current?.subtenant_id ?? '—'}
      </span>
    );
  }

  const value = `${current?.tenant_id ?? ''}\0${current?.subtenant_id ?? ''}`;

  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: '0.2rem', fontSize: '0.72rem' }}>
      <span style={{ color: 'rgba(255,255,255,0.65)' }}>Организация</span>
      <select
        className="mf-context-select"
        value={value}
        onChange={async (e) => {
          const [tenantId, subtenantId] = e.target.value.split('\0');
          if (tenantId && subtenantId) {
            await switchContext(tenantId, subtenantId);
          }
        }}
      >
        {items.map((ctx) => (
          <option key={`${ctx.tenant_id}:${ctx.subtenant_id}`} value={`${ctx.tenant_id}\0${ctx.subtenant_id}`}>
            {contextLabel(ctx)}
          </option>
        ))}
      </select>
    </label>
  );
}
