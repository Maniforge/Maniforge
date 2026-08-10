import { authHeaders } from '@/shared/auth/session';

const wmsBase = () => (import.meta.env.VITE_WMS_BASE || '/wms').replace(/\/$/, '');

export type ScanResult = {
  ok: boolean;
  error?: string;
  code?: string;
  kind?: string;
  barcode?: string;
  product?: Record<string, unknown>;
  marking?: Record<string, unknown>;
  pack?: Record<string, unknown>;
  contents?: Array<Record<string, unknown>>;
  parsed?: Record<string, unknown>;
  [key: string]: unknown;
};

export type MovementType = 'receipt' | 'issue' | 'transfer';

export class WmsApiError extends Error {
  readonly apiCode?: string;
  readonly httpStatus?: number;

  constructor(message: string, apiCode?: string, httpStatus?: number) {
    super(message);
    this.name = 'WmsApiError';
    this.apiCode = apiCode;
    this.httpStatus = httpStatus;
  }
}

async function wmsFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${wmsBase()}${path}`, {
    ...init,
    headers: {
      ...authHeaders(true),
      ...(init?.headers || {}),
    },
  });
  const json = await response.json().catch(() => ({ ok: false }));
  if (!response.ok || !json.ok) {
    throw new WmsApiError(
      String(json.error || `HTTP ${response.status}`),
      typeof json.code === 'string' ? json.code : undefined,
      response.status,
    );
  }
  return json as T;
}

export async function scanCode(code: string): Promise<ScanResult> {
  return wmsFetch<ScanResult>('/api/v1/scan', {
    method: 'POST',
    body: JSON.stringify({ code: code.trim() }),
  });
}

export async function postMovementScan(input: {
  movement_type: MovementType;
  stock_id: number;
  scan: string;
  doc_number?: string;
  qty?: string | number;
  from_stock_id?: number;
  to_stock_id?: number;
}): Promise<Record<string, unknown>> {
  return wmsFetch('/api/v1/movements/scan', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

export async function createPack(input: {
  unit_type: 'group' | 'pallet' | 'consumer' | 'sscc';
  code?: string;
  stock_id?: number;
}): Promise<{ pack: Record<string, unknown> }> {
  return wmsFetch('/api/v1/packs', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

export async function getPack(packId: number): Promise<{
  pack: Record<string, unknown>;
  contents?: Array<Record<string, unknown>>;
}> {
  return wmsFetch(`/api/v1/packs/${packId}`);
}

export async function addMarkingToPack(
  packId: number,
  code: string,
): Promise<{ contents?: Array<Record<string, unknown>> }> {
  return wmsFetch(`/api/v1/packs/${packId}/markings`, {
    method: 'POST',
    body: JSON.stringify({ code: code.trim() }),
  });
}

export async function registerMarking(input: {
  product_id: number;
  code: string;
}): Promise<{ marking: Record<string, unknown> }> {
  return wmsFetch('/api/v1/markings', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

export async function addChildToPack(
  parentPackId: number,
  childPackUnitId: number,
): Promise<{ contents?: Array<Record<string, unknown>> }> {
  return wmsFetch(`/api/v1/packs/${parentPackId}/children`, {
    method: 'POST',
    body: JSON.stringify({ child_pack_unit_id: childPackUnitId }),
  });
}

export async function sealPack(packId: number): Promise<{ pack: Record<string, unknown> }> {
  return wmsFetch(`/api/v1/packs/${packId}/seal`, {
    method: 'POST',
    body: '{}',
  });
}

export function movementDocNumber(prefix: string): string {
  const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 12);
  const rand = Math.random().toString(36).slice(2, 6);
  return `${prefix}-${stamp}-${rand}`;
}
