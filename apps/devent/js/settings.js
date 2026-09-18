const API = (window.deventUrl || ((p) => p))('/api/v1');
const UI_PREFS_KEY = 'wb-app-prefs';

const $ = (s) => document.querySelector(s);

function toast(msg, type = '') {
  const el = $('#toast');
  el.textContent = msg;
  el.className = `toast ${type}`;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.add('hidden'), 3500);
}

async function api(path, opts = {}) {
  const res = await fetch(`${API}${path}`, {
    headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) },
    credentials: 'same-origin',
    ...opts,
  });
  const text = await res.text();
  let data;
  try { data = text ? JSON.parse(text) : null; } catch { data = { detail: text }; }
  if (!res.ok) {
    const msg = data?.detail || `Ошибка ${res.status}`;
    if (res.status === 401) window.location = (window.deventUrl || ((p) => p))('/login');
    throw new Error(typeof msg === 'string' ? msg : `Ошибка ${res.status}`);
  }
  return data;
}

function loadUiPrefs() {
  try {
    return JSON.parse(localStorage.getItem(UI_PREFS_KEY) || '{}');
  } catch {
    return {};
  }
}

function saveUiPrefs(prefs) {
  localStorage.setItem(UI_PREFS_KEY, JSON.stringify(prefs));
}

function applyUiPrefs() {
  const prefs = loadUiPrefs();
  if (prefs.pageSize) $('#ui-page-size').value = String(prefs.pageSize);
  if (typeof prefs.onlyWithStock === 'boolean') {
    $('#ui-only-stock').checked = prefs.onlyWithStock;
  }
  const theme = prefs.theme || 'light';
  const themeRadio = document.querySelector(`input[name="ui-theme"][value="${theme}"]`);
  if (themeRadio) themeRadio.checked = true;
  const scheme = prefs.scheme || WBTheme.DEFAULT_SCHEME;
  const schemeRadio = document.querySelector(`input[name="ui-scheme"][value="${scheme}"]`);
  if (schemeRadio) schemeRadio.checked = true;
}

function collectUiPrefs() {
  const themeEl = document.querySelector('input[name="ui-theme"]:checked');
  const schemeEl = document.querySelector('input[name="ui-scheme"]:checked');
  return {
    pageSize: Number($('#ui-page-size').value) || 50,
    onlyWithStock: $('#ui-only-stock').checked,
    theme: themeEl ? themeEl.value : 'light',
    scheme: schemeEl ? schemeEl.value : WBTheme.DEFAULT_SCHEME,
  };
}

function bindThemePicker() {
  document.querySelectorAll('input[name="ui-theme"]').forEach((el) => {
    el.addEventListener('change', () => {
      if (!el.checked) return;
      WBTheme.setTheme(el.value);
      saveUiPrefs(collectUiPrefs());
    });
  });
  document.querySelectorAll('input[name="ui-scheme"]').forEach((el) => {
    el.addEventListener('change', () => {
      if (!el.checked) return;
      WBTheme.setScheme(el.value);
      saveUiPrefs(collectUiPrefs());
    });
  });
}

function fillForm(s) {
  $('#wb-demo').checked = s.wb_demo;
  $('#wb-sandbox').checked = s.wb_sandbox;
  $('#wb-db-path').value = s.wb_db_path || '';
  $('#mariadb-enabled').checked = s.mariadb_enabled;
  $('#mariadb-host').value = s.mariadb_host || '';
  $('#mariadb-port').value = s.mariadb_port || 3306;
  $('#mariadb-database').value = s.mariadb_database || '';
  $('#scheduler-enabled').checked = s.wb_scheduler_enabled;
  $('#scheduler-interval').value = s.wb_scheduler_interval_min || 15;

  const status = $('#token-status');
  if (s.wb_api_token_set) {
    status.textContent = '· задан';
    status.className = 'token-status token-status--ok';
  } else {
    status.textContent = '· не задан';
    status.className = 'token-status token-status--empty';
  }
  $('#wb-token').value = '';
}

async function loadSettings() {
  const s = await api('/settings');
  fillForm(s);
  applyUiPrefs();
}

function collectServerPayload() {
  const payload = {
    wb_demo: $('#wb-demo').checked,
    wb_sandbox: $('#wb-sandbox').checked,
    wb_db_path: $('#wb-db-path').value.trim(),
    mariadb_enabled: $('#mariadb-enabled').checked,
    mariadb_host: $('#mariadb-host').value.trim(),
    mariadb_port: Number($('#mariadb-port').value) || 3306,
    mariadb_database: $('#mariadb-database').value.trim(),
    wb_scheduler_enabled: $('#scheduler-enabled').checked,
    wb_scheduler_interval_min: Number($('#scheduler-interval').value) || 15,
  };
  const token = $('#wb-token').value.trim();
  if (token) payload.wb_api_token = token;
  return payload;
}

$('#settings-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = $('#btn-save');
  btn.disabled = true;
  btn.textContent = 'Сохранение…';
  try {
    saveUiPrefs(collectUiPrefs());
    const res = await api('/settings', {
      method: 'PUT',
      body: JSON.stringify(collectServerPayload()),
    });
    toast(res.message || 'Сохранено', 'ok');
    await loadSettings();
  } catch (err) {
    toast(err.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Сохранить';
  }
});

$('#btn-reset').addEventListener('click', () => {
  loadSettings().catch((e) => toast(e.message, 'err'));
});

loadSettings().catch((e) => toast(`Не удалось загрузить: ${e.message}`, 'err'));
bindThemePicker();
loadNetworkInfo().catch(() => {});

async function loadNetworkInfo() {
  const box = $('#phone-urls');
  if (!box) return;
  try {
    const n = await fetch('/network').then((r) => r.json());
    if (!n.phone_ready) {
      box.innerHTML = '<p class="phone-warn">Сервер слушает только localhost. В .env задайте <code>WB_API_HOST=0.0.0.0</code> и перезапустите.</p>';
      return;
    }
    const links = (n.app_urls || []).filter((u) => !u.includes('127.0.0.1'));
    if (!links.length) {
      box.innerHTML = '<p class="field-hint">Не удалось определить IP. Откройте на телефоне адрес роутера для ПК.</p>';
      return;
    }
    box.innerHTML = links.map((url) =>
      `<a class="phone-link" href="${url}" target="_blank" rel="noopener">${url}</a>`
    ).join('');
  } catch {
    box.textContent = 'Не удалось загрузить ссылки';
  }
}
