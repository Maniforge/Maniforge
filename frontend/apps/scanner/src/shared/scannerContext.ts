import { SCANNER_CTX_KEY } from './auth/storage';

export type ScannerContext = {
  stock_id: number | null;
  last_operation?: string;
  draft_pack_id?: number | null;
  draft_pack_code?: string | null;
  group_marking_count?: number;
  group_recent_codes?: string[];
  receipt_count?: number;
  issue_count?: number;
  draft_pallet_id?: number | null;
  draft_pallet_code?: string | null;
  draft_pallet_sscc?: string | null;
  pallet_child_count?: number;
  pallet_recent_children?: string[];
  receipt_prefill_scan?: string;
};

const DEFAULT_CTX: ScannerContext = {
  stock_id: null,
  draft_pack_id: null,
  draft_pack_code: null,
  group_marking_count: 0,
  group_recent_codes: [],
  receipt_count: 0,
  issue_count: 0,
  draft_pallet_id: null,
  draft_pallet_code: null,
  draft_pallet_sscc: null,
  pallet_child_count: 0,
  pallet_recent_children: [],
};

export function loadScannerContext(): ScannerContext {
  try {
    const raw = localStorage.getItem(SCANNER_CTX_KEY);
    if (!raw) {
      return { ...DEFAULT_CTX };
    }
    const parsed = JSON.parse(raw) as ScannerContext;
    return {
      ...DEFAULT_CTX,
      ...parsed,
      stock_id: parsed.stock_id ?? null,
      draft_pack_id: parsed.draft_pack_id ?? null,
      group_recent_codes: parsed.group_recent_codes || [],
    };
  } catch {
    return { ...DEFAULT_CTX };
  }
}

export function saveScannerContext(ctx: ScannerContext): void {
  localStorage.setItem(SCANNER_CTX_KEY, JSON.stringify(ctx));
}

export function patchScannerContext(patch: Partial<ScannerContext>): ScannerContext {
  const next = { ...loadScannerContext(), ...patch };
  saveScannerContext(next);
  return next;
}

export function maskCode(code: string): string {
  const trimmed = code.trim();
  if (trimmed.length <= 8) {
    return trimmed;
  }
  return `${trimmed.slice(0, 4)}…${trimmed.slice(-4)}`;
}
