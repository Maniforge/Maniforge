/**
 * Темы: light | dark | system
 * Цветовые схемы: purple | berry | ocean | forest | sunset | rose | teal | indigo | slate
 */
(function (global) {
  const PREFS_KEY = 'wb-app-prefs';
  const VALID_THEMES = ['light', 'dark', 'system'];
  const VALID_SCHEMES = ['purple', 'berry', 'ocean', 'forest', 'sunset', 'rose', 'teal', 'indigo', 'slate'];
  const DEFAULT_SCHEME = 'purple';

  function loadPrefs() {
    try {
      return JSON.parse(localStorage.getItem(PREFS_KEY) || '{}');
    } catch {
      return {};
    }
  }

  function savePrefs(patch) {
    localStorage.setItem(PREFS_KEY, JSON.stringify({ ...loadPrefs(), ...patch }));
  }

  function getStoredTheme() {
    const mode = loadPrefs().theme || 'light';
    return VALID_THEMES.includes(mode) ? mode : 'light';
  }

  function getStoredScheme() {
    const scheme = loadPrefs().scheme || DEFAULT_SCHEME;
    return VALID_SCHEMES.includes(scheme) ? scheme : DEFAULT_SCHEME;
  }

  function systemTheme() {
    return global.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function resolveTheme(mode) {
    if (mode === 'system') return systemTheme();
    return mode === 'dark' ? 'dark' : 'light';
  }

  function applyAll(themeMode, scheme) {
    const resolved = resolveTheme(themeMode);
    document.documentElement.setAttribute('data-theme', resolved);
    document.documentElement.setAttribute('data-theme-mode', themeMode);
    document.documentElement.setAttribute('data-scheme', scheme);
    return resolved;
  }

  function initTheme() {
    applyAll(getStoredTheme(), getStoredScheme());

    global.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (getStoredTheme() === 'system') applyAll('system', getStoredScheme());
    });
  }

  global.WBTheme = {
    VALID: VALID_THEMES,
    VALID_SCHEMES,
    DEFAULT_SCHEME,
    getStoredTheme,
    getStoredScheme,
    resolveTheme,
    applyAll,
    saveTheme(mode) {
      if (!VALID_THEMES.includes(mode)) mode = 'light';
      savePrefs({ theme: mode });
      return applyAll(mode, getStoredScheme());
    },
    saveScheme(scheme) {
      if (!VALID_SCHEMES.includes(scheme)) scheme = DEFAULT_SCHEME;
      savePrefs({ scheme });
      return applyAll(getStoredTheme(), scheme);
    },
    setTheme(mode) {
      return global.WBTheme.saveTheme(mode);
    },
    setScheme(scheme) {
      return global.WBTheme.saveScheme(scheme);
    },
    initTheme,
  };

  initTheme();
})(window);
