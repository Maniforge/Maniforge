export function isLoginPath(pathname = window.location.pathname): boolean {
  return /\/(desk\/)?login\/?$/.test(pathname) || /\/(app|scanner)\/login\/?$/.test(pathname);
}

export function hasDeskSession(): boolean {
  try {
    return Boolean(
      localStorage.getItem('maniforge_access_token') ||
        localStorage.getItem('maniforge_admin_access_token'),
    );
  } catch {
    return false;
  }
}

const TOKEN_KEYS = [
  'maniforge_access_token',
  'maniforge_refresh_token',
  'maniforge_csrf_token',
  'maniforge_user',
  'maniforge_tenant',
  'maniforge_tenant_code',
  'maniforge_subtenant_code',
  'maniforge_admin_access_token',
  'maniforge_admin_refresh_token',
  'maniforge_admin_csrf_token',
];

export function clearDeskSession(): void {
  TOKEN_KEYS.forEach((key) => {
    try {
      localStorage.removeItem(key);
    } catch {
      /* ignore */
    }
  });
}

export function deskLogout(): void {
  clearDeskSession();
  window.location.href = '/desk/login/';
}
