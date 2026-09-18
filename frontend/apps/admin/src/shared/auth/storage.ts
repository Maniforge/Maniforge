/** Ключи localStorage — паритет с public/assets/js/maniforge-session.js */
export const SESSION_STORAGE = {
  access: 'maniforge_admin_access_token',
  refresh: 'maniforge_admin_refresh_token',
  csrf: 'maniforge_admin_csrf_token',
  action: 'maniforge_admin_action_token',
  actionExpires: 'maniforge_admin_action_token_expires',
  tenant: 'maniforge_admin_tenant_id',
  subtenant: 'maniforge_admin_subtenant_id',
  platformToken: 'maniforge_platform_admin_token',
} as const;

export function hasAccessToken(): boolean {
  return Boolean(localStorage.getItem(SESSION_STORAGE.access));
}

export function clearSession(): void {
  Object.values(SESSION_STORAGE).forEach((key) => localStorage.removeItem(key));
  localStorage.removeItem('maniforge_tl_admin_token');
}
