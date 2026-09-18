const API = (window.deventUrl || ((p) => p))('/api/v1');
const UI_PREFS_KEY = 'wb-app-prefs';

function loadUiPrefs() {
  try {
    return JSON.parse(localStorage.getItem(UI_PREFS_KEY) || '{}');
  } catch {
    return {};
  }
}

const uiPrefs = loadUiPrefs();
const PAGE_SIZE = uiPrefs.pageSize || 50;

const state = { offset: 0, warehouses: [] };

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

function params() {
  const p = new URLSearchParams();
  p.set('limit', String(PAGE_SIZE));
  p.set('offset', String(state.offset));
  const q = $('#q').value.trim();
  const wh = $('#warehouse').value;
  if (q) p.set('q', q);
  if (wh) p.set('warehouse', wh);
  if ($('#only-stock').checked) p.set('only_with_stock', 'true');
  return p.toString();
}

function fmtTime(iso) {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
  } catch { return ''; }
}

function renderSummary(s) {
  const badge = $('#status-badge');
  badge.textContent = s.is_demo ? 'Демо-режим' : 'Подключено к WB';
  badge.className = `badge ${s.is_demo ? 'demo' : 'live'}`;

  const syncEl = $('#sync-time');
  if (s.last_synced_at) {
    syncEl.textContent = `Обновлено: ${fmtTime(s.last_synced_at)}`;
  } else {
    syncEl.textContent = 'Ещё не синхронизировали';
  }
  if (state.autoSyncMin) {
    syncEl.textContent += ` · авто ${state.autoSyncMin} мин`;
  }

  if (s.all_warehouses?.length) {
    state.warehouses = s.all_warehouses;
    fillWarehouseSelect();
  } else if (s.top_warehouses?.length) {
    state.warehouses = s.top_warehouses.map((w) => w.name);
    fillWarehouseSelect();
  }
}

function fillWarehouseSelect() {
  const sel = $('#warehouse');
  const cur = sel.value;
  const opts = ['<option value="">Все склады</option>'];
  for (const name of state.warehouses) {
    opts.push(`<option value="${esc(name)}"${name === cur ? ' selected' : ''}>${esc(name)}</option>`);
  }
  sel.innerHTML = opts.join('');
}

function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function renderTable(rows) {
  const tbody = $('#tbody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty">Ничего не найдено. Попробуйте снять фильтры или нажмите «Загрузить с WB».</td></tr>';
    return;
  }
  tbody.innerHTML = rows.map((r) => `
    <tr>
      <td><strong>${esc(r.supplier_article !== '—' ? r.supplier_article : `nmId ${r.nm_id}`)}</strong>${r.brand !== '—' ? `<br><small class="text-muted-sm">${esc(r.brand)}</small>` : ''}</td>
      <td>${r.nm_id}</td>
      <td style="font-size:12px">${esc(r.barcode)}</td>
      <td>${esc(r.warehouse_name)}</td>
      <td class="num ${r.quantity > 0 ? 'qty-ok' : 'qty-zero'}">${r.quantity}</td>
      <td class="num">${r.in_way_to_client}</td>
      <td class="num">${r.in_way_from_client}</td>
      <td>${esc(r.category)}</td>
    </tr>
  `).join('');
}

function updatePager(rows) {
  const page = Math.floor(state.offset / PAGE_SIZE) + 1;
  $('#pager-info').textContent = `Стр. ${page}`;
  $('#btn-prev').disabled = state.offset === 0;
  $('#btn-next').disabled = rows.length < PAGE_SIZE;
  $('#table-meta').textContent = `Показано ${rows.length} записей (с ${state.offset + 1})`;
}

async function loadSummary() {
  const s = await api('/dashboard/summary');
  renderSummary(s);
}

async function loadTable() {
  const rows = await api(`/dashboard/stocks?${params()}`);
  renderTable(rows);
  updatePager(rows);
}

function scrollToTableTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function loadTablePage() {
  await loadTable();
  scrollToTableTop();
}

async function refresh() {
  await loadSummary();
  await loadTable();
}

async function loadAutoSyncConfig() {
  try {
    return await api('/wms/sync/config');
  } catch {
    return { enabled: true, interval_min: 15 };
  }
}

function startAutoRefresh(intervalMin) {
  const ms = Math.max(1, intervalMin) * 60 * 1000;
  if (startAutoRefresh._timer) clearInterval(startAutoRefresh._timer);
  startAutoRefresh._timer = setInterval(() => {
    refresh().catch(() => {});
  }, ms);
}

async function syncWb() {
  const btn = $('#btn-sync');
  btn.disabled = true;
  btn.textContent = 'Загрузка…';
  try {
    const res = await api('/wms/sync/full', { method: 'POST', body: '{}' });
    let msg = res.message || `Обновлено: ${res.wb_synced_count} записей`;
    if (res.is_demo) {
      msg += ' (демо-режим)';
    } else if (res.data_unchanged) {
      msg = `Данные на WB не изменились: ${res.wb_synced_count} записей, ${res.wb_with_stock} с остатком, ${res.wb_total_units.toLocaleString('ru')} шт.`;
    }
    toast(msg, res.is_demo ? '' : (res.data_unchanged ? '' : 'ok'));
    state.offset = 0;
    await refresh();
  } catch (e) {
    toast(e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Обновить с WB';
  }
}

let searchTimer;
$('#q').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { state.offset = 0; loadTable().catch((e) => toast(e.message, 'err')); }, 300);
});
$('#warehouse').addEventListener('change', () => { state.offset = 0; refresh().catch((e) => toast(e.message, 'err')); });
$('#only-stock').addEventListener('change', () => { state.offset = 0; refresh().catch((e) => toast(e.message, 'err')); });
$('#btn-sync').addEventListener('click', syncWb);
$('#btn-refresh').addEventListener('click', () => refresh().catch((e) => toast(e.message, 'err')));
$('#btn-prev').addEventListener('click', () => {
  state.offset = Math.max(0, state.offset - PAGE_SIZE);
  loadTablePage().catch((e) => toast(e.message, 'err'));
});
$('#btn-next').addEventListener('click', () => {
  state.offset += PAGE_SIZE;
  loadTablePage().catch((e) => toast(e.message, 'err'));
});

if (typeof uiPrefs.onlyWithStock === 'boolean') {
  $('#only-stock').checked = uiPrefs.onlyWithStock;
}

(async () => {
  try {
    const cfg = await loadAutoSyncConfig();
    if (cfg.enabled) {
      state.autoSyncMin = cfg.interval_min;
      startAutoRefresh(cfg.interval_min);
    }
    await refresh();
  } catch (e) {
    toast(`Не удалось загрузить: ${e.message}. Запустите server.py`, 'err');
  }
})();
