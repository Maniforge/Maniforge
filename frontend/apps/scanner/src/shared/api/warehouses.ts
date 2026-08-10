import { authHeaders } from '@/shared/auth/session';

const warehousesBase = () =>
  (import.meta.env.VITE_WAREHOUSES_BASE || 'http://127.0.0.1:8098').replace(/\/$/, '');

export type StockOption = {
  id: number;
  name?: string;
  type?: string;
  code?: string;
};

async function whFetch<T>(path: string): Promise<T> {
  const response = await fetch(`${warehousesBase()}${path}`, {
    headers: authHeaders(true),
  });
  const json = await response.json().catch(() => ({ ok: false }));
  if (!response.ok || !json.ok) {
    throw new Error(json.error || `HTTP ${response.status}`);
  }
  return json as T;
}

export async function listStocksForScanner(): Promise<StockOption[]> {
  const json = await whFetch<{ items: StockOption[] }>('/api/v1/stocks?status=active');
  return json.items || [];
}
