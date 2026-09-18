import { authHeaders } from '@/shared/auth/session';

const warehousesBase = () =>
  (import.meta.env.VITE_WAREHOUSES_BASE || 'http://127.0.0.1:8098').replace(/\/$/, '');

export type StockNode = {
  id: number;
  name?: string;
  code?: string;
  type?: string;
  status?: string;
  parent_id?: number | null;
  children?: StockNode[];
  [key: string]: unknown;
};

async function whFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${warehousesBase()}${path}`, {
    ...init,
    headers: {
      ...authHeaders(true),
      ...(init?.headers || {}),
    },
  });
  const json = await response.json().catch(() => ({ ok: false }));
  if (!response.ok || !json.ok) {
    throw new Error(json.error || `HTTP ${response.status}`);
  }
  return json as T;
}

export async function fetchStockTree(): Promise<{ tree: StockNode[]; flat_count: number }> {
  const json = await whFetch<{ tree: StockNode[]; flat_count: number }>('/api/v1/stocks/tree');
  return { tree: json.tree || [], flat_count: json.flat_count ?? 0 };
}

export async function listStockTypes(): Promise<Array<{ code: string; name: string }>> {
  const json = await whFetch<{ items: Array<{ code: string; name: string }> }>('/api/v1/stock-types');
  return json.items || [];
}

export type StockAuditEntry = {
  id: number;
  event_type: string;
  stock_id: number;
  payload?: Record<string, unknown>;
  created_at?: string;
  correlation_id?: string;
  actor_user?: { id?: number; phone?: string; email?: string; display_name?: string };
};

export async function fetchStockAudit(
  stockId: number,
  limit = 50,
): Promise<{ stock_id: number; items: StockAuditEntry[] }> {
  const json = await whFetch<{ stock_id: number; items: StockAuditEntry[] }>(
    `/api/v1/stocks/${stockId}/audit?limit=${limit}`,
  );
  return { stock_id: json.stock_id ?? stockId, items: json.items || [] };
}

export async function createStock(input: {
  name: string;
  type: string;
  parent_id?: number | null;
  code?: string;
}): Promise<StockNode> {
  const json = await whFetch<{ stock: StockNode }>('/api/v1/stocks', {
    method: 'POST',
    body: JSON.stringify(input),
  });
  return json.stock;
}
