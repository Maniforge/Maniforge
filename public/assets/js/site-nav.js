(function () {
  function closeMenu(nav) {
    nav.classList.remove('is-menu-open');
    var toggle = nav.querySelector('.mf-site-nav-toggle');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Открыть меню');
    }
  }

  document.querySelectorAll('.mf-site-nav-toggle').forEach(function (toggle) {
    toggle.addEventListener('click', function () {
      var nav = toggle.closest('.mf-site-nav');
      if (!nav) {
        return;
      }
      var open = !nav.classList.contains('is-menu-open');
      nav.classList.toggle('is-menu-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    });
  });

  document.querySelectorAll('.mf-site-nav--marketing .mf-site-nav-menu a').forEach(function (link) {
    link.addEventListener('click', function () {
      var nav = link.closest('.mf-site-nav');
      if (nav) {
        closeMenu(nav);
      }
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }
    document.querySelectorAll('.mf-site-nav.is-menu-open').forEach(closeMenu);
  });
})();
