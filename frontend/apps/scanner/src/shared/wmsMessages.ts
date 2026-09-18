import { WmsApiError } from '@/shared/api/wms';

const CODE_MESSAGES: Record<string, string> = {
  pack_not_sealed: 'Упаковка не запечатана',
  pack_sealed: 'Упаковка уже запечатана',
  marking_unavailable: 'КИЗ недоступен для операции',
  insufficient_qty: 'Недостаточно остатка на складе',
  duplicate_marking: 'КИЗ уже зарегистрирован',
};

export function wmsErrorMessage(err: unknown): string {
  if (err instanceof WmsApiError) {
    if (err.apiCode && CODE_MESSAGES[err.apiCode]) {
      return CODE_MESSAGES[err.apiCode];
    }
    if (err.httpStatus === 404) {
      return 'Код не найден';
    }
    if (err.httpStatus === 403) {
      return 'Нет доступа';
    }
    return err.message;
  }
  if (err instanceof Error) {
    return err.message;
  }
  return String(err);
}
