(function () {
  const TOKEN_KEYS = [
    'maniforge_access_token',
    'maniforge_admin_access_token',
  ];

  function hasToken() {
    try {
      return Boolean(localStorage.getItem('maniforge_access_token'));
    } catch (_) {
      return false;
    }
  }

  function isLoginPath() {
    return /\/(desk\/)?login\/?$/.test(location.pathname);
  }

  function pathOf() {
    let path = location.pathname || '/';
    if (path !== '/' && !path.endsWith('/')) path += '/';
    return path;
  }

  function markCurrent(nav) {
    const here = pathOf();
    const prefixOk = ['/api/', '/apps/', '/about/', '/about-us/', '/app/', '/scanner/', '/desk/users/'];
    nav.querySelectorAll('a[href]').forEach((link) => {
      const href = link.getAttribute('href') || '';
      if (!href.startsWith('/')) return;
      const target = href === '/' ? '/' : href.endsWith('/') || href.includes('.') ? href : href + '/';
      const onPage =
        target === '/'
          ? here === '/'
          : here === target || (prefixOk.includes(target) && here.startsWith(target));
      if (onPage) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
  }

  function logout() {
    if (window.ManiforgeDesk && typeof ManiforgeDesk.logout === 'function') {
      ManiforgeDesk.logout();
      return;
    }
    TOKEN_KEYS.concat([
      'maniforge_refresh_token',
      'maniforge_csrf_token',
      'maniforge_user',
      'maniforge_tenant',
      'maniforge_tenant_code',
      'maniforge_subtenant_code',
      'maniforge_admin_refresh_token',
      'maniforge_admin_csrf_token',
    ]).forEach((key) => {
      try {
        localStorage.removeItem(key);
      } catch (_) {
        /* ignore */
      }
    });
    location.href = '/desk/login/';
  }

  function mount() {
    const header = document.querySelector('header.top');
    if (!header) return;
    const guest = header.querySelector('.nav-guest');
    const auth = header.querySelector('.nav-auth');
    const authed = hasToken() && !isLoginPath();
    if (guest) guest.hidden = authed;
    if (auth) auth.hidden = !authed;
    if (guest && !guest.hidden) markCurrent(guest);
    if (auth && !auth.hidden) markCurrent(auth);
    header.querySelectorAll('[data-logout]').forEach((btn) => {
      btn.addEventListener('click', logout);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
