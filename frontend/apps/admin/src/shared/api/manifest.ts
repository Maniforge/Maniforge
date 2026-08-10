import { authHeaders } from '@/shared/auth/session';

const manifestBase = () =>
  (import.meta.env.VITE_MANIFEST_BASE || 'http://127.0.0.1:8095').replace(/\/$/, '');

export type ManifestField = {
  name: string;
  type: string;
  required?: boolean;
};

export type Manifest = {
  code: string;
  name: string;
  fields?: ManifestField[];
};

export type ManifestRecord = {
  id: number;
  data: Record<string, unknown>;
  created_at?: string;
  updated_at?: string;
};

type ListResponse = {
  ok: boolean;
  error?: string;
  records?: ManifestRecord[];
  meta?: { total?: number; count?: number; limit?: number; offset?: number };
};

async function manifestFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${manifestBase()}${path}`, {
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

export async function listManifests(): Promise<Manifest[]> {
  const json = await manifestFetch<{ manifests: Manifest[] }>('/api/v1/manifests');
  return json.manifests || [];
}

export async function listRecords(
  entity: string,
  limit = 50,
): Promise<{ records: ManifestRecord[]; meta?: ListResponse['meta'] }> {
  const json = await manifestFetch<ListResponse>(
    `/api/data/${encodeURIComponent(entity)}?limit=${limit}&offset=0`,
  );
  return { records: json.records || [], meta: json.meta };
}

export async function createRecord(entity: string, data: Record<string, unknown>): Promise<ManifestRecord> {
  const json = await manifestFetch<{ record: ManifestRecord }>(`/api/data/${encodeURIComponent(entity)}`, {
    method: 'POST',
    body: JSON.stringify(data),
  });
  return json.record;
}

export async function updateRecord(
  entity: string,
  id: number,
  data: Record<string, unknown>,
): Promise<ManifestRecord> {
  const json = await manifestFetch<{ record: ManifestRecord }>(
    `/api/data/${encodeURIComponent(entity)}/${id}`,
    {
      method: 'PATCH',
      body: JSON.stringify(data),
    },
  );
  return json.record;
}

export async function deleteRecord(entity: string, id: number): Promise<void> {
  await manifestFetch(`/api/data/${encodeURIComponent(entity)}/${id}`, {
    method: 'DELETE',
  });
}

export function fieldValue(record: ManifestRecord, name: string): unknown {
  return record.data?.[name];
}
