import type { ScanResult } from '@/shared/api/wms';

export function scanPreviewTitle(result: ScanResult): string {
  const kind = result.kind || 'unknown';
  if (kind === 'product') {
    const name = String(result.product?.name || result.product?.title || 'Товар');
    return `Товар: ${name}`;
  }
  if (kind === 'marking') {
    const serial = result.marking?.serial || result.marking?.code_full;
    return `КИЗ: ${serial ? String(serial).slice(0, 24) : 'маркировка'}`;
  }
  if (kind === 'pack') {
    const pack = result.pack || {};
    const code = pack.code || pack.sscc || `#${pack.id}`;
    return `Упаковка: ${code} (${pack.unit_type || 'pack'})`;
  }
  return `Код: ${kind}`;
}

export function scanPreviewDetails(result: ScanResult): string[] {
  const lines: string[] = [];
  const kind = result.kind;

  if (kind === 'product') {
    const p = result.product || {};
    if (p.barcode_ean13) lines.push(`EAN-13: ${p.barcode_ean13}`);
    if (p.id) lines.push(`product_id: ${p.id}`);
  }

  if (kind === 'marking') {
    const m = result.marking || {};
    if (m.status) lines.push(`Статус: ${m.status}`);
    if (m.gtin) lines.push(`GTIN: ${m.gtin}`);
    if (m.product_id) lines.push(`product_id: ${m.product_id}`);
  }

  if (kind === 'pack') {
    const pack = result.pack || {};
    if (pack.status) lines.push(`Статус: ${pack.status}`);
    if (pack.sscc) lines.push(`SSCC: ${pack.sscc}`);
    const contents = result.contents || [];
    if (contents.length > 0) {
      lines.push(`Содержимое: ${contents.length} поз.`);
    }
  }

  return lines;
}

export function movementConfirmHint(
  result: ScanResult,
  movementType: 'receipt' | 'issue',
): string {
  if (movementType === 'receipt') {
    if (result.kind === 'pack') {
      return 'Подтвердить приёмку упаковки на выбранный склад?';
    }
    if (result.kind === 'marking') {
      return 'Подтвердить приёмку 1 КИЗ?';
    }
    if (result.kind === 'product') {
      return 'Подтвердить приёмку товара по штрихкоду?';
    }
  }
  if (movementType === 'issue') {
    if (result.kind === 'pack') {
      return 'Подтвердить отгрузку упаковки (все вложенные позиции)?';
    }
    if (result.kind === 'marking') {
      return 'Подтвердить отгрузку 1 КИЗ?';
    }
    if (result.kind === 'product') {
      return 'Подтвердить отгрузку товара по штрихкоду?';
    }
  }
  return 'Подтвердить операцию?';
}
