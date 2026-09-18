const API = (window.deventUrl || ((p) => p))('/api/v1/wms');
const PREFS_KEY = 'store-prefs';
const PREFS_VERSION = 5;
const STOCK_CACHE_KEY = 'store-stock-cache';
const STOCK_CACHE_VERSION = 3;
const STOCK_CACHE_MAX_ROWS = 50;
const STOCK_CACHE_TTL_MS = 90_000;
const WH_CACHE_KEY = 'store-warehouses-cache';
const WH_CACHE_VERSION = 1;
const STOCK_POLL_MS = 30000;
const STOCK_PAGE_SIZE = 50;
const LOGS_PAGE_SIZE = 40;
const ALL_WAREHOUSES = 'all';

/** Инкремент при каждом reset-запросе остатков — отсекает устаревшие ответы. */
let stockFetchSeq = 0;
let loadMoreScrollTimer = 0;
const PERF = new URLSearchParams(location.search).has('perf');

function perfMark(name) {
  if (!PERF || !performance?.mark) return;
  try { performance.mark(name); } catch { /* ignore */ }
}

function perfMeasure(name, start, end) {
  if (!PERF || !performance?.measure) return;
  try {
    performance.measure(name, start, end);
    const m = performance.getEntriesByName(name).pop();
    if (m) console.info(`[perf] ${name}: ${Math.round(m.duration)}ms`);
  } catch { /* ignore */ }
}

const state = {
  view: 'stock',
  movementTab: 'receipt',
  movementType: 'receipt',
  movementProduct: null,
  movementPickerFor: 'receipt',
  transfers: [],
  displayMode: 'table',
  warehouses: [],
  warehouseId: null,
  search: '',
  onlyStock: true,
  lastUpdated: null,
  sortCol: 'free',
  sortDir: 'desc',
  stockRows: [],
  stockHasMore: true,
  stockLoading: false,
  stockLoadingMore: false,
  warehouseSearch: '',
  stockRefreshPending: false,
  productDetailId: null,
  productPickerSearch: '',
  productPickerRows: [],
  productPickerHasMore: true,
  productPickerLoading: false,
  productPickerLoadingMore: false,
  logsSearch: '',
  logsRows: [],
  logsHasMore: true,
  logsLoading: false,
  logsLoadingMore: false,
};

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

const VIEW_KEYS = { 1: 'stock', 2: 'movements' };

function loadPrefs() {
  try {
    const p = JSON.parse(localStorage.getItem(PREFS_KEY) || '{}');
    if (p.v === PREFS_VERSION) {
      if (typeof p.onlyStock === 'boolean') state.onlyStock = p.onlyStock;
      if (p.displayMode === 'table' || p.displayMode === 'cards') state.displayMode = p.displayMode;
    } else {
      // Новая версия prefs: «С остатком» снова по умолчанию включён
      state.onlyStock = true;
    }
    if (p.sortCol) state.sortCol = p.sortCol;
    if (p.sortDir) state.sortDir = p.sortDir;
  } catch { /* ignore */ }
}

function savePrefs() {
  localStorage.setItem(PREFS_KEY, JSON.stringify({
    v: PREFS_VERSION,
    onlyStock: state.onlyStock,
    displayMode: state.displayMode,
    sortCol: state.sortCol,
    sortDir: state.sortDir,
  }));
}

function toast(msg, type = '') {
  const el = $('#toast');
  el.textContent = msg;
  el.className = `toast ${type}`;
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.add('hidden'), 3200);
}

function setLoading(on) {
  const bar = $('#store-loading');
  if (!bar) return;
  bar.classList.remove('hidden', 'busy', 'done');
  if (on) bar.classList.add('busy');
  else {
    bar.classList.add('done');
    setTimeout(() => bar.classList.add('hidden'), 200);
  }
}

function setSyncing(on) {
  $('#sync-icon')?.classList.toggle('spinning', on);
  $('#btn-refresh-stock')?.classList.toggle('syncing', on);
}

async function api(path, opts = {}) {
  const silent = Boolean(opts.silent);
  if (!silent) setLoading(true);
  try {
    const res = await fetch(`${API}${path}`, {
      headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) },
      credentials: 'same-origin',
      ...opts,
    });
    const text = await res.text();
    let data;
    try { data = text ? JSON.parse(text) : null; } catch { data = { detail: text }; }
    if (!res.ok) {
      const detail = data?.detail;
      const msg = typeof detail === 'string' ? detail : `Ошибка ${res.status}`;
      if (res.status === 401) {
        toast(msg, 'err');
        window.location = (window.deventUrl || ((p) => p))('/login');
      }
      throw new Error(msg);
    }
    return data;
  } finally {
    if (!silent) setLoading(false);
  }
}

function apiSilent(path, opts = {}) {
  return api(path, { ...opts, silent: true });
}

function stockCacheKey() {
  return isAllWarehouses() ? 'all' : String(whId() ?? 'none');
}

function loadStockCache() {
  try {
    const raw = JSON.parse(localStorage.getItem(STOCK_CACHE_KEY) || '{}');
    if (raw.v !== STOCK_CACHE_VERSION) return null;
    const bucket = raw.buckets?.[stockCacheKey()];
    if (!bucket?.rows?.length) return null;
    const f = bucket.filters || {};
    if (typeof f.onlyStock === 'boolean' && f.onlyStock !== state.onlyStock) return null;
    if (f.sortCol && f.sortCol !== state.sortCol) return null;
    if (f.sortDir && f.sortDir !== state.sortDir) return null;
    if (String(f.search || '') !== String(state.search || '')) return null;
    return bucket;
  } catch {
    return null;
  }
}

function saveStockCache(rows) {
  try {
    const raw = JSON.parse(localStorage.getItem(STOCK_CACHE_KEY) || '{}');
    const cache = raw.v === STOCK_CACHE_VERSION ? raw : { v: STOCK_CACHE_VERSION, buckets: {} };
    // Только первая страница — иначе quota + долгий JSON.parse на boot
    cache.buckets[stockCacheKey()] = {
      rows: (rows || []).slice(0, STOCK_CACHE_MAX_ROWS),
      savedAt: Date.now(),
      filters: {
        onlyStock: state.onlyStock,
        sortCol: state.sortCol,
        sortDir: state.sortDir,
        search: state.search,
      },
    };
    localStorage.setItem(STOCK_CACHE_KEY, JSON.stringify(cache));
  } catch { /* ignore */ }
}

function loadWarehousesCache() {
  try {
    const raw = JSON.parse(localStorage.getItem(WH_CACHE_KEY) || '{}');
    if (raw.v !== WH_CACHE_VERSION || !Array.isArray(raw.rows)) return null;
    return raw;
  } catch {
    return null;
  }
}

function saveWarehousesCache(rows) {
  try {
    localStorage.setItem(WH_CACHE_KEY, JSON.stringify({
      v: WH_CACHE_VERSION,
      rows,
      savedAt: Date.now(),
    }));
  } catch { /* ignore */ }
}

function applyWarehousesList(list, { persistId = true } = {}) {
  state.warehouses = list || [];
  const whs = warehouseList();
  const prev = state.warehouseId;
  if (prev === ALL_WAREHOUSES || (prev && whs.some((w) => w.id === prev))) {
    state.warehouseId = prev;
  } else {
    state.warehouseId = pickWarehouseId(whs);
  }
  if (persistId && state.warehouseId != null) {
    localStorage.setItem('store-warehouse-id', String(state.warehouseId));
  }
  updateWarehouseButton();
  renderWarehouseList();
}

function applyStockData(rows, { deferIfEditing = true, append = false } = {}) {
  const fromIndex = append ? state.stockRows.length : 0;
  if (append) state.stockRows = [...state.stockRows, ...rows];
  else state.stockRows = rows;
  saveStockCache(state.stockRows);
  if (deferIfEditing && document.activeElement?.matches('.cell-free-input')) {
    state.stockRefreshPending = true;
    return;
  }
  state.stockRefreshPending = false;
  renderCurrentData({ append, fromIndex });
}

function mergeStockRow(updated) {
  const idx = state.stockRows.findIndex((r) => r.product_id === updated.product_id);
  if (idx >= 0) state.stockRows[idx] = updated;
  else state.stockRows.push(updated);
  saveStockCache(state.stockRows);
}

function esc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function isAllWarehouses() {
  return state.warehouseId === ALL_WAREHOUSES;
}

function whId() {
  if (isAllWarehouses()) return null;
  return state.warehouseId ?? warehouseList()[0]?.id ?? null;
}

function requireWarehouseId() {
  const id = whId();
  if (id == null) {
    toast('Выберите конкретный склад для операции', 'err');
    openWarehouseModal();
  }
  return id;
}

function warehouseList() {
  const base = state.warehouses.filter((w) => String(w.code || '').startsWith('_base/'));
  if (base.length) return base;
  const wb = state.warehouses.filter((w) => w.is_wb);
  return wb.length ? wb : state.warehouses;
}

function currentWarehouse() {
  if (isAllWarehouses()) return null;
  return state.warehouses.find((x) => x.id === whId());
}

function currentWarehouseName() {
  if (isAllWarehouses()) return 'Все склады';
  return currentWarehouse()?.name || '—';
}

function warehouseButtonLabel() {
  if (isAllWarehouses()) return 'Все склады';
  const w = currentWarehouse();
  return w ? shortWhName(w.name) : 'Склад';
}

function updateWarehouseButton() {
  const label = $('#warehouse-label');
  if (label) label.textContent = warehouseButtonLabel();
}

function shortWhName(name) {
  const n = String(name);
  const city = n.split(/[-–(]/)[0].trim();
  return city.length > 16 ? `${city.slice(0, 14)}…` : city || n.slice(0, 16);
}

function stockQty(row) {
  return row.quantity ?? 0;
}

function assemblyQty(row) {
  return row.assembly_qty ?? 0;
}

function qtyOf(row) {
  if (row.free_qty != null) return row.free_qty;
  return Math.max(0, stockQty(row) - assemblyQty(row));
}

function qtyTier(q) {
  if (q <= 0) return 'zero';
  if (q <= 5) return 'low';
  if (q <= 20) return 'mid';
  return 'high';
}

function rowMatchesSearch(row, q) {
  const needle = q.toLowerCase();
  const fields = [
    row.supplier_article,
    row.name,
    row.barcode,
    row.brand,
    row.nm_id != null ? String(row.nm_id) : '',
  ];
  return fields.some((f) => String(f || '').toLowerCase().includes(needle));
}

function highlight(text, q) {
  const s = String(text ?? '');
  if (!q || !s) return esc(s || '—');
  const lower = s.toLowerCase();
  const n = q.toLowerCase();
  const i = lower.indexOf(n);
  if (i < 0) return esc(s);
  return `${esc(s.slice(0, i))}<mark class="hl">${esc(s.slice(i, i + n.length))}</mark>${esc(s.slice(i + n.length))}`;
}

function filterRows(rows) {
  let out = rows;
  const q = state.search.trim();
  if (q) out = out.filter((r) => rowMatchesSearch(r, q));
  if (state.onlyStock) out = out.filter((r) => qtyOf(r) > 0);
  return out;
}

function sortRows(rows) {
  const col = state.sortCol;
  const dir = state.sortDir === 'asc' ? 1 : -1;
  return [...rows].sort((a, b) => {
    let va;
    let vb;
    if (col === 'free' || col === 'qty') {
      va = qtyOf(a);
      vb = qtyOf(b);
      return (va - vb) * dir;
    }
    if (col === 'stock') {
      va = stockQty(a);
      vb = stockQty(b);
      return (va - vb) * dir;
    }
    if (col === 'assembly') {
      va = assemblyQty(a);
      vb = assemblyQty(b);
      return (va - vb) * dir;
    }
    if (col === 'name') {
      va = (a.name || '').toLowerCase();
      vb = (b.name || '').toLowerCase();
      return va.localeCompare(vb, 'ru') * dir;
    }
    if (col === 'article') {
      va = (a.supplier_article || a.name || '').toLowerCase();
      vb = (b.supplier_article || b.name || '').toLowerCase();
      return va.localeCompare(vb, 'ru') * dir;
    }
    if (col === 'barcode') {
      va = a.barcode || '';
      vb = b.barcode || '';
      return va.localeCompare(vb) * dir;
    }
    va = (a.brand || '').toLowerCase();
    vb = (b.brand || '').toLowerCase();
    return va.localeCompare(vb, 'ru') * dir;
  });
}

function displayedRows() {
  return state.stockRows;
}

function stockRequestParams({ offset = 0, limit = STOCK_PAGE_SIZE } = {}) {
  const params = new URLSearchParams({
    limit: String(limit),
    offset: String(offset),
    sort: state.sortCol,
    sort_dir: state.sortDir,
  });
  if (!isAllWarehouses() && whId() != null) params.set('warehouse_id', String(whId()));
  const q = state.search.trim();
  if (q) {
    // Поиск всегда по каталогу, включая нулевые — фильтр «С остатком» не применяется
    params.set('q', q);
  } else if (state.onlyStock) {
    params.set('only_with_stock', 'true');
  }
  return params;
}

function isSearchMode() {
  return Boolean(state.search.trim());
}

function allRowsOutOfStock(rows) {
  return rows.length > 0 && rows.every((r) => qtyOf(r) <= 0);
}

function warehouseMatchesSearch(warehouse, q) {
  const needle = q.toLowerCase();
  const fields = [
    warehouse.name,
    warehouse.code,
    String(warehouse.id),
  ];
  return fields.some((f) => String(f || '').toLowerCase().includes(needle));
}

function allWarehousesMatchesSearch(q) {
  if (!q) return true;
  return 'все склады'.includes(q.toLowerCase());
}

function selectWarehouse(id) {
  if (state.warehouseId === id) {
    closeWarehouseModal();
    return;
  }
  state.warehouseId = id;
  localStorage.setItem('store-warehouse-id', String(id));
  updateWarehouseButton();
  closeWarehouseModal();
  // Сброс поиска — иначе уходим в тяжёлый catalog-search вместо списка склада
  if (state.search.trim()) {
    state.search = '';
    const searchEl = $('#global-search');
    if (searchEl) searchEl.value = '';
    updateSearchClear();
  }
  // Сразу сбрасываем список: иначе висят товары прошлого склада до ответа API
  stockFetchSeq += 1;
  state.stockLoading = true;
  const cached = loadStockCache();
  if (cached?.rows?.length) {
    state.stockRows = cached.rows;
    state.stockHasMore = cached.rows.length >= STOCK_PAGE_SIZE;
  } else {
    state.stockRows = [];
    state.stockHasMore = true;
  }
  renderCurrentData();
  fetchStock({ silent: false, useCache: false, reset: true }).catch((e) => toast(e.message, 'err'));
  if (state.view === 'movements') {
    refreshMovements();
  }
}

function renderWarehouseList() {
  const ul = $('#warehouse-list');
  if (!ul) return;
  const list = warehouseList();
  const q = state.warehouseSearch.trim();
  const filtered = q ? list.filter((w) => warehouseMatchesSearch(w, q)) : list;
  const totalUnits = list.reduce((s, w) => s + (w.stock_units || 0), 0);
  const items = [];

  if (allWarehousesMatchesSearch(q)) {
    items.push(`<li>
      <button type="button" class="warehouse-item${isAllWarehouses() ? ' active' : ''}" data-id="${ALL_WAREHOUSES}">
        <span class="warehouse-item-name">${highlight('Все склады', q)}</span>
        <span class="warehouse-item-meta">${totalUnits > 0 ? `${totalUnits.toLocaleString('ru-RU')} шт.` : 'суммарно'}</span>
      </button>
    </li>`);
  }

  items.push(...filtered.map((w) => `
    <li>
      <button type="button" class="warehouse-item${!isAllWarehouses() && state.warehouseId === w.id ? ' active' : ''}" data-id="${w.id}">
        <span class="warehouse-item-name">${highlight(w.name, q)}</span>
        <span class="warehouse-item-meta">${w.stock_units > 0 ? `${w.stock_units.toLocaleString('ru-RU')} шт.` : 'пусто'}</span>
      </button>
    </li>`));

  ul.innerHTML = items.length
    ? items.join('')
    : '<li class="warehouse-empty">Ничего не найдено</li>';
}

function updateWarehouseSearchClear() {
  const btn = $('#warehouse-search-clear');
  const has = Boolean(state.warehouseSearch.trim());
  btn?.classList.toggle('hidden', !has);
}

function openWarehouseModal() {
  state.warehouseSearch = '';
  const input = $('#warehouse-search');
  if (input) input.value = '';
  updateWarehouseSearchClear();
  renderWarehouseList();
  $('#warehouse-modal')?.classList.remove('hidden');
  document.body.classList.add('modal-open');
  input?.focus();
  // Полные счётчики позиций — только при открытии модалки
  loadWarehouses({ silent: true, light: false }).catch(() => {});
}

function closeWarehouseModal() {
  $('#warehouse-modal')?.classList.add('hidden');
  if ($('#product-picker-modal')?.classList.contains('hidden') && $('#product-modal')?.classList.contains('hidden')) {
    document.body.classList.remove('modal-open');
  }
  state.warehouseSearch = '';
  const input = $('#warehouse-search');
  if (input) input.value = '';
  updateWarehouseSearchClear();
}

function restoreWarehouseId() {
  const saved = localStorage.getItem('store-warehouse-id');
  if (saved === ALL_WAREHOUSES) state.warehouseId = ALL_WAREHOUSES;
  else if (saved) {
    const n = Number(saved);
    if (Number.isFinite(n) && n > 0) state.warehouseId = n;
  }
}

function pickWarehouseId(list) {
  const saved = localStorage.getItem('store-warehouse-id') || '';
  if (saved === ALL_WAREHOUSES) return ALL_WAREHOUSES;
  const savedNum = Number(saved);
  if (Number.isFinite(savedNum) && savedNum > 0) {
    const savedWh = list.find((w) => w.id === savedNum);
    if (savedWh) return savedWh.id;
  }
  const withStock = list.find((w) => (w.stock_units || 0) > 0);
  return withStock?.id ?? list[0]?.id ?? ALL_WAREHOUSES;
}

function updateStockBadge(rows) {
  const badge = $('#badge-stock');
  if (badge) badge.textContent = rows.length > 0 ? `${rows.length}${state.stockHasMore ? '+' : ''}` : '';
}

function updateSortUi() {
  $$('.th-sort').forEach((btn) => {
    const active = btn.dataset.sort === state.sortCol;
    btn.classList.toggle('active', active);
    btn.classList.toggle('asc', active && state.sortDir === 'asc');
    btn.classList.toggle('desc', active && state.sortDir === 'desc');
  });
}

function updateDisplayModeUi() {
  $$('.view-mode-btn').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.mode === state.displayMode);
  });
  const tableWrap = $('#stock-table-wrap');
  const cards = $('#stock-cards');
  const isCards = state.displayMode === 'cards';
  tableWrap?.classList.toggle('hidden', isCards);
  cards?.classList.toggle('hidden', !isCards);
}

function hasCopyValue(value) {
  const s = String(value ?? '').trim();
  return Boolean(s && s !== '—');
}

function copyMiniBtn(value, label) {
  if (!hasCopyValue(value)) return '';
  const raw = String(value).trim();
  return `<button type="button" class="cell-copy" data-copy="${esc(raw)}" data-copy-label="${esc(label)}" title="Копировать ${esc(label)}" aria-label="Копировать ${esc(label)}">⎘</button>`;
}

function cellWithCopy(value, q, label) {
  const raw = String(value ?? '').trim();
  const shown = raw || '—';
  const inner = highlight(shown, q);
  if (!hasCopyValue(raw)) return inner;
  return `<span class="cell-with-copy"><span class="cell-text">${inner}</span>${copyMiniBtn(raw, label)}</span>`;
}

function stockBar(q, max) {
  const pct = max > 0 ? Math.min(100, Math.round((q / max) * 100)) : 0;
  return `<div class="qty-bar" aria-hidden="true"><span class="qty-bar-fill ${qtyTier(q)}" style="width:${pct}%"></span></div>`;
}

function qtyCell(value, { highlight = false, maxQty = 1 } = {}) {
  return `
    <td class="num cell-qty-wrap">
      <span class="cell-qty qty-pill ${qtyTier(value)}">${value.toLocaleString('ru-RU')}</span>
      ${highlight ? stockBar(value, maxQty) : ''}
    </td>`;
}

function freeQtyCell(row, { highlight = false, maxQty = 1, editable = false } = {}) {
  const free = qtyOf(row);
  if (!editable) return qtyCell(free, { highlight, maxQty });
  const tier = qtyTier(free);
  return `
    <td class="num cell-qty-wrap cell-free-edit">
      <span class="cell-qty cell-free-display qty-pill ${tier}">${free.toLocaleString('ru-RU')}</span>
      <input type="number" class="cell-free-input qty-pill ${tier}" min="0" step="1"
        value="${free}" data-product-id="${row.product_id}" data-prev="${free}"
        aria-label="Свободно, шт." inputmode="numeric" tabindex="-1" />
      ${highlight ? stockBar(free, maxQty) : ''}
    </td>`;
}

function stockRow(row, q, maxQty) {
  const free = qtyOf(row);
  const stock = stockQty(row);
  const assembly = assemblyQty(row);
  const editable = !isAllWarehouses();
  const out = free <= 0;
  const freeInner = editable
    ? `<span class="cell-qty cell-free-display qty-pill ${qtyTier(free)}">${free.toLocaleString('ru-RU')}</span>
       <input type="number" class="cell-free-input qty-pill ${qtyTier(free)}" min="0" step="1"
         value="${free}" data-product-id="${row.product_id}" data-prev="${free}"
         aria-label="Свободно, шт." inputmode="numeric" tabindex="-1" />
       ${stockBar(free, maxQty)}`
    : `<span class="cell-qty qty-pill ${qtyTier(free)}">${free.toLocaleString('ru-RU')}</span>
       ${stockBar(free, maxQty)}`;
  return `
    <tr class="data-row${out ? ' out-of-stock' : ''}" data-product-id="${row.product_id}" data-qty="${free}">
      <td class="cell-article">${cellWithCopy(row.supplier_article, q, 'артикул')}</td>
      <td class="cell-name">${cellWithCopy(row.name && row.name !== row.supplier_article ? row.name : '', q, 'наименование')}</td>
      <td class="cell-barcode">${cellWithCopy(row.barcode, q, 'штрихкод')}</td>
      ${qtyCell(stock)}
      ${qtyCell(assembly)}
      <td class="num cell-qty-wrap${editable ? ' cell-free-edit' : ''}">
        ${freeInner}
      </td>
    </tr>`;
}

function productCard(row, q) {
  const qty = qtyOf(row);
  const article = row.supplier_article || '';
  const name = row.name && row.name !== row.supplier_article ? row.name : '';
  return `
    <article class="product-card ${qtyTier(qty)}${qty <= 0 ? ' out-of-stock' : ''}" tabindex="0" data-product-id="${row.product_id}" data-qty="${qty}">
      <div class="product-card-top">
        <span class="product-card-qty qty-pill ${qtyTier(qty)}">${qty.toLocaleString('ru-RU')}</span>
        ${row.brand ? `<span class="product-card-brand">${esc(row.brand)}</span>` : ''}
      </div>
      <h3 class="product-card-title">${cellWithCopy(article, q, 'артикул') || '—'}</h3>
      ${name ? `<p class="product-card-name">${cellWithCopy(name, q, 'наименование')}</p>` : ''}
      <div class="product-card-meta">
        ${row.barcode ? `<span class="mono card-barcode">${cellWithCopy(row.barcode, q, 'штрихкод')}</span>` : ''}
        ${row.nm_id ? `<span>nm ${row.nm_id}</span>` : ''}
      </div>
    </article>`;
}

function stockCountLabel(count) {
  const suffix = state.stockHasMore ? '+' : '';
  return `${count}${suffix} поз.`;
}

function updateStockLoadingUi() {
  const row = $('#stock-loading-row');
  const card = $('#stock-loading-card');
  const show = state.stockLoadingMore;
  row?.classList.toggle('hidden', !show);
  card?.classList.toggle('hidden', !show);
}

function updateSearchBanner(rows) {
  let banner = $('#stock-search-banner');
  if (!banner) {
    const wrap = $('#stock-table-wrap') || $('#view-stock');
    if (!wrap) return;
    wrap.insertAdjacentHTML('afterbegin', `
      <div id="stock-search-banner" class="stock-search-banner hidden" role="status"></div>`);
    banner = $('#stock-search-banner');
  }
  if (!banner) return;

  if (!isSearchMode() || !rows.length) {
    banner.classList.add('hidden');
    banner.innerHTML = '';
    return;
  }

  if (allRowsOutOfStock(rows)) {
    banner.classList.remove('hidden');
    banner.innerHTML = `
      <div class="stock-search-banner-body">
        <strong>Товары не в наличии</strong>
        <span>Остаток 0 — введите количество в колонке «Свободно»</span>
      </div>`;
    return;
  }

  const zeroCnt = rows.filter((r) => qtyOf(r) <= 0).length;
  if (zeroCnt > 0) {
    banner.classList.remove('hidden');
    banner.innerHTML = `
      <div class="stock-search-banner-body">
        <strong>Найдено ${rows.length}${state.stockHasMore ? '+' : ''} поз.</strong>
        <span>${zeroCnt} без остатка — можно указать количество в «Свободно»</span>
      </div>`;
    return;
  }

  banner.classList.add('hidden');
  banner.innerHTML = '';
}

function renderStockView({ append = false, fromIndex = 0 } = {}) {
  const rows = displayedRows();
  const q = state.search.trim();
  const slice = append ? rows.slice(fromIndex) : rows;
  const maxQty = Math.max(1, ...rows.map(qtyOf));
  updateStockBadge(rows);
  if (!append) updateSearchBanner(rows);

  const countEl = $('#stock-count');
  if (countEl) countEl.textContent = stockCountLabel(rows.length);

  const empty = $('#empty-stock');
  const tbody = $('#list-stock');
  const foot = $('#foot-stock');
  const footQty = $('#foot-stock-qty');
  const footAssembly = $('#foot-assembly-sum');
  const footSum = $('#foot-stock-sum');
  const cardsEl = $('#stock-cards');

  if (!rows.length && !state.stockLoading) {
    tbody.innerHTML = '';
    cardsEl.innerHTML = '';
    foot?.classList.add('hidden');
    empty?.classList.remove('hidden');
    updateSearchBanner([]);
    const wh = currentWarehouse();
    const hint = $('#empty-stock-hint');
    const refreshBtn = $('#empty-refresh-btn');
    refreshBtn?.classList.add('hidden');
    if (state.search.trim()) {
      $('#empty-stock-text').textContent = 'Товар не найден';
      if (hint) hint.textContent = 'Проверьте артикул, штрихкод или название';
    } else if (state.onlyStock) {
      $('#empty-stock-text').textContent = 'Нет товаров в наличии';
      if (hint) {
        hint.textContent = wh
          ? `Склад «${wh.name}»: найдите товар через поиск и укажите «Свободно»`
          : 'Найдите товар через поиск и укажите количество в «Свободно»';
      }
    } else if (!isAllWarehouses() && wh) {
      $('#empty-stock-text').textContent = `Склад «${wh.name}» пуст`;
      if (hint) hint.textContent = 'Найдите товар через поиск и укажите количество в «Свободно»';
    } else {
      $('#empty-stock-text').textContent = 'Нет данных на складе';
      if (hint) hint.textContent = 'Нажмите «Обновить» или выберите филиал';
      refreshBtn?.classList.remove('hidden');
    }
    return;
  }

  empty?.classList.add('hidden');
  if (!append) {
    tbody.innerHTML = rows.map((r) => stockRow(r, q, maxQty)).join('');
    cardsEl.innerHTML = rows.map((r) => productCard(r, q)).join('');
  } else if (slice.length) {
    tbody.insertAdjacentHTML('beforeend', slice.map((r) => stockRow(r, q, maxQty)).join(''));
    cardsEl.insertAdjacentHTML('beforeend', slice.map((r) => productCard(r, q)).join(''));
  }
  const sumFree = rows.reduce((s, r) => s + qtyOf(r), 0);
  const sumStock = rows.reduce((s, r) => s + stockQty(r), 0);
  const sumAssembly = rows.reduce((s, r) => s + assemblyQty(r), 0);
  if (foot && footQty && footAssembly && footSum) {
    if (rows.length) foot.classList.remove('hidden');
    footQty.textContent = sumStock.toLocaleString('ru-RU');
    footAssembly.textContent = sumAssembly.toLocaleString('ru-RU');
    footSum.textContent = sumFree.toLocaleString('ru-RU');
    const footNote = $('#foot-stock-note');
    if (footNote) {
      footNote.textContent = state.stockHasMore ? 'итого по загруженным' : '';
      footNote.classList.toggle('hidden', !state.stockHasMore);
    }
  }
  bindFreeQtyInputs(tbody);
  ensureStockInteractions();
  ensureStockLoadingSentinel();
  updateStockLoadingUi();
  requestAnimationFrame(() => maybeLoadMoreStock());
}

function ensureStockLoadingSentinel() {
  const tbody = $('#list-stock');
  if (tbody && !$('#stock-loading-row')) {
    tbody.insertAdjacentHTML('beforeend', `
      <tr id="stock-loading-row" class="stock-loading-row hidden">
        <td colspan="6">Загрузка…</td>
      </tr>`);
  } else {
    const row = $('#stock-loading-row');
    if (row?.parentElement) row.parentElement.appendChild(row);
  }
  const cards = $('#stock-cards');
  if (cards && !$('#stock-loading-card')) {
    cards.insertAdjacentHTML('beforeend', `
      <div id="stock-loading-card" class="stock-loading-card hidden">Загрузка…</div>`);
  } else {
    const card = $('#stock-loading-card');
    if (card?.parentElement) card.parentElement.appendChild(card);
  }
}

function stockScrollRoot() {
  return state.displayMode === 'cards' ? $('#stock-cards') : $('.store-table-scroll');
}

function maybeLoadMoreStock() {
  clearTimeout(loadMoreScrollTimer);
  loadMoreScrollTimer = setTimeout(() => {
    if (state.view !== 'stock' || !state.stockHasMore || state.stockLoadingMore || state.stockLoading) return;
    const root = stockScrollRoot();
    if (!root) return;
    const threshold = 160;
    if (root.scrollTop + root.clientHeight >= root.scrollHeight - threshold) {
      loadMoreStock().catch((e) => toast(e.message, 'err'));
    }
  }, 150);
}

function setupStockInfiniteScroll() {
  const roots = [$('.store-table-scroll'), $('#stock-cards')].filter(Boolean);
  roots.forEach((root) => {
    if (root.dataset.stockScrollBound) return;
    root.dataset.stockScrollBound = '1';
    root.addEventListener('scroll', () => maybeLoadMoreStock(), { passive: true });
  });
}

function syncFreeQtyDisplay(cell) {
  const input = cell.querySelector('.cell-free-input');
  const span = cell.querySelector('.cell-free-display');
  if (!input || !span) return;
  const v = Math.max(0, parseInt(input.value, 10) || 0);
  span.textContent = v.toLocaleString('ru-RU');
}

function bindFreeQtyInputs(root) {
  root.querySelectorAll('.cell-free-edit').forEach((cell) => {
    const input = cell.querySelector('.cell-free-input');
    if (!input) return;

    cell.addEventListener('click', (e) => {
      e.stopPropagation();
      if (!input.disabled) input.focus();
    });

    input.addEventListener('click', (e) => e.stopPropagation());
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        input.blur();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        input.value = input.dataset.prev || '0';
        input.blur();
      }
    });
    input.addEventListener('focus', () => {
      cell.classList.add('is-editing');
      input.select();
    });
    input.addEventListener('blur', () => {
      cell.classList.remove('is-editing');
      syncFreeQtyDisplay(cell);
      commitFreeQty(input);
    });
  });
}

async function commitFreeQty(input) {
  const productId = Number(input.dataset.productId);
  const prev = Number(input.dataset.prev || 0);
  const next = Math.max(0, parseInt(input.value, 10) || 0);
  input.value = String(next);
  if (next === prev) {
    if (state.stockRefreshPending) {
      state.stockRefreshPending = false;
      fetchStock({ silent: true }).catch(() => {});
    }
    return;
  }

  const warehouseId = whId();
  if (warehouseId == null) {
    input.value = String(prev);
    toast('Выберите конкретный склад для инвентаризации', 'err');
    return;
  }

  const row = state.stockRows.find((r) => r.product_id === productId);
  if (row) {
    const assembly = assemblyQty(row);
    row.free_qty = next;
    row.quantity = next + assembly;
    mergeStockRow(row);
    renderCurrentData();
  }

  input.disabled = true;
  try {
    const res = await apiSilent('/stock/inventory', {
      method: 'POST',
      body: JSON.stringify({
        warehouse_id: warehouseId,
        product_id: productId,
        free_qty: next,
      }),
    });
    mergeStockRow(res.stock);
    applyStockData(state.stockRows, { deferIfEditing: false });
    input.dataset.prev = String(qtyOf(res.stock));
    if (res.changed) {
      toast(`Инвентаризация: ${prev} → ${next}`, 'ok');
      if (state.view === 'movements' && state.movementTab === 'logs') refreshMovements();
    }
  } catch (e) {
    if (row) {
      row.free_qty = prev;
      row.quantity = prev + assemblyQty(row);
      mergeStockRow(row);
      renderCurrentData();
    }
    input.value = String(prev);
    input.dataset.prev = String(prev);
    toast(e.message, 'err');
  } finally {
    input.disabled = false;
    if (state.stockRefreshPending) {
      state.stockRefreshPending = false;
      fetchStock({ silent: true }).catch(() => {});
    }
  }
}

function stockRowById(productId) {
  return state.stockRows.find((r) => r.product_id === productId) || null;
}

function renderProductDashStats(data) {
  const el = $('#product-dash-stats');
  if (!el) return;
  const cards = [];
  if (data.current_warehouse_id != null && data.current_warehouse_name) {
    cards.push(
      { label: `Свободно · ${data.current_warehouse_name}`, value: data.current_free_qty, tier: qtyTier(data.current_free_qty) },
      { label: 'Остаток здесь', value: data.current_quantity, tier: qtyTier(data.current_quantity) },
      { label: 'Наборка здесь', value: data.current_assembly_qty, tier: qtyTier(data.current_assembly_qty) },
    );
  }
  cards.push(
    { label: 'Свободно · все филиалы', value: data.total_free_qty, tier: qtyTier(data.total_free_qty), accent: true },
    { label: 'Остаток · все филиалы', value: data.total_quantity, tier: qtyTier(data.total_quantity) },
  );
  el.innerHTML = cards.map((c) => `
    <div class="product-stat-card ${c.accent ? 'accent' : ''}">
      <span class="product-stat-label">${esc(c.label)}</span>
      <span class="product-stat-value qty-pill ${c.tier}">${Number(c.value || 0).toLocaleString('ru-RU')}</span>
    </div>`).join('');
}

function renderProductBranches(data) {
  const tbody = $('#product-branches-body');
  const loading = $('#product-branches-loading');
  if (!tbody) return;
  loading?.classList.add('hidden');
  const currentId = data.current_warehouse_id;
  const maxFree = Math.max(1, ...data.branches.map((b) => b.free_qty || 0));
  if (!data.branches.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="product-branches-empty">Нет данных по филиалам</td></tr>';
    return;
  }
  tbody.innerHTML = data.branches.map((b) => {
    const pct = Math.round(((b.free_qty || 0) / maxFree) * 100);
    const active = currentId != null && b.warehouse_id === currentId ? ' active' : '';
    const zero = (b.free_qty || 0) <= 0 && (b.quantity || 0) <= 0 ? ' zero' : '';
    return `
      <tr class="product-branch-row${active}${zero}">
        <td class="product-branch-name">
          <span class="product-branch-bar" style="width:${pct}%"></span>
          <span class="product-branch-label">${esc(b.warehouse_name)}</span>
        </td>
        <td class="num">${Number(b.quantity || 0).toLocaleString('ru-RU')}</td>
        <td class="num">${Number(b.assembly_qty || 0).toLocaleString('ru-RU')}</td>
        <td class="num"><span class="qty-pill ${qtyTier(b.free_qty || 0)}">${Number(b.free_qty || 0).toLocaleString('ru-RU')}</span></td>
      </tr>`;
  }).join('');
}

function renderProductDetailFields(data) {
  const fields = [
    ['Артикул', data.supplier_article],
    ['Наименование', data.name],
    ['Штрихкод', data.barcode],
    ['ID товара', data.product_id],
    ['nmId', data.nm_id],
    ['Бренд', data.brand],
  ];
  const list = $('#product-detail-list');
  if (!list) return;
  list.innerHTML = fields
    .filter(([, value]) => value != null && String(value).trim() !== '')
    .map(([label, value]) => `
      <div class="product-detail-item">
        <dt>${esc(label)}</dt>
        <dd>${esc(String(value))}</dd>
      </div>`)
    .join('');
}

async function openProductDetail(productId) {
  const row = stockRowById(productId);
  if (!row) return;
  state.productDetailId = productId;
  const title = $('#product-modal-title');
  if (title) title.textContent = row.supplier_article || row.name || 'Товар';
  renderProductDetailFields({
    product_id: productId,
    supplier_article: row.supplier_article,
    name: row.name,
    barcode: row.barcode,
    nm_id: row.nm_id,
    brand: row.brand,
  });
  renderProductDashStats({
    current_warehouse_id: isAllWarehouses() ? null : whId(),
    current_warehouse_name: currentWarehouseName(),
    current_quantity: stockQty(row),
    current_assembly_qty: assemblyQty(row),
    current_free_qty: qtyOf(row),
    total_quantity: stockQty(row),
    total_free_qty: qtyOf(row),
    total_assembly_qty: assemblyQty(row),
  });
  $('#product-branches-body').innerHTML = '';
  $('#product-branches-loading')?.classList.remove('hidden');
  $('#product-modal')?.classList.remove('hidden');
  document.body.classList.add('modal-open');

  const params = new URLSearchParams();
  if (!isAllWarehouses() && whId() != null) params.set('warehouse_id', String(whId()));
  try {
    const data = await api(`/products/${productId}/dashboard?${params}`, { silent: true });
    if (state.productDetailId !== productId) return;
    if (title) title.textContent = data.supplier_article || data.name || 'Товар';
    renderProductDetailFields(data);
    renderProductDashStats(data);
    renderProductBranches(data);
  } catch (e) {
    if (state.productDetailId === productId) {
      $('#product-branches-loading')?.classList.add('hidden');
      $('#product-branches-body').innerHTML = `<tr><td colspan="4" class="product-branches-empty">${esc(e.message)}</td></tr>`;
    }
  }
}

function closeProductDetail() {
  $('#product-modal')?.classList.add('hidden');
  if ($('#warehouse-modal')?.classList.contains('hidden') && $('#product-picker-modal')?.classList.contains('hidden')) {
    document.body.classList.remove('modal-open');
  }
  state.productDetailId = null;
}

function onStockInteractionClick(e) {
  const copyBtn = e.target.closest('.cell-copy');
  if (copyBtn) {
    e.stopPropagation();
    copyText(copyBtn.dataset.copy, copyBtn.dataset.copyLabel || 'Значение');
    return;
  }
  if (e.target.closest('.cell-free-edit, input, textarea, select, button')) return;
  const target = e.target.closest('.data-row, .product-card');
  if (!target?.dataset.productId) return;
  openProductDetail(Number(target.dataset.productId)).catch((err) => toast(err.message, 'err'));
}

function ensureStockInteractions() {
  const tbody = $('#list-stock');
  if (tbody && !tbody.dataset.interactionBound) {
    tbody.dataset.interactionBound = '1';
    tbody.addEventListener('click', onStockInteractionClick);
  }
  const cards = $('#stock-cards');
  if (cards && !cards.dataset.interactionBound) {
    cards.dataset.interactionBound = '1';
    cards.addEventListener('click', onStockInteractionClick);
  }
}

async function copyText(text, label) {
  if (!text) { toast('Нет данных для копирования', 'err'); return; }
  try {
    await navigator.clipboard.writeText(text);
    toast(`${label} скопирован`, 'ok');
  } catch {
    toast('Не удалось скопировать', 'err');
  }
}

async function quickMovement(type, qty, rowOverride) {
  const row = rowOverride || stockRowById(state.productDetailId);
  if (!row) return;
  const warehouseId = requireWarehouseId();
  if (warehouseId == null) return;
  closeProductDetail();
  try {
    await api(`/movements/${type}`, {
      method: 'POST',
      body: JSON.stringify({
        warehouse_id: warehouseId,
        product_id: row.product_id || null,
        supplier_article: row.supplier_article,
        barcode: row.barcode,
        quantity: qty,
        doc_number: '',
        comment: 'Быстрая операция',
      }),
    });
    toast(type === 'receipt' ? `Приход +${qty}` : `Расход −${qty}`, 'ok');
    await fetchStock({ silent: true });
    if (state.view === 'movements') refreshMovements();
  } catch (e) {
    toast(e.message, 'err');
  }
}

function fillForm(formId, row) {
  if (!row) return;
  if (formId === '#form-receipt' || formId === '#form-expense') {
    state.movementPickerFor = formId === '#form-expense' ? 'expense' : 'receipt';
    selectMovementProduct({
      product_id: row.product_id,
      supplier_article: row.supplier_article || '',
      barcode: row.barcode || '',
      name: row.name || '',
    });
    const form = $(formId);
    const qty = form?.querySelector('[name="quantity"]');
    if (qty) qty.value = '1';
    setMovementType(state.movementPickerFor);
  }
}

/** API max for /stock limit; export pages until exhausted (not just UI stockRows). */
const STOCK_EXPORT_PAGE = 1000;

async function fetchAllStockForExport() {
  const all = [];
  let offset = 0;
  for (;;) {
    const params = stockRequestParams({ offset, limit: STOCK_EXPORT_PAGE });
    const rows = await api(`/stock?${params}`, { silent: true });
    if (!Array.isArray(rows) || !rows.length) break;
    all.push(...rows);
    if (rows.length < STOCK_EXPORT_PAGE) break;
    offset += rows.length;
    // safety: API le=5000 per call; hard cap total pulls
    if (offset >= 100_000) break;
  }
  return all;
}

async function exportCsv() {
  const btn = $('#btn-export');
  if (btn) btn.disabled = true;
  toast('Готовим CSV…');
  try {
    const rows = await fetchAllStockForExport();
    if (!rows.length) {
      toast('Нет данных для экспорта', 'err');
      return;
    }
    const header = ['Артикул', 'Наименование', 'Штрихкод', 'Остаток', 'Наборка', 'Свободно'];
    const lines = rows.map((r) => [
      r.supplier_article || '',
      r.name || '',
      r.barcode || '',
      stockQty(r),
      assemblyQty(r),
      qtyOf(r),
    ].map((c) => `"${String(c).replace(/"/g, '""')}"`).join(';'));
    const bom = '\uFEFF';
    const blob = new Blob([bom + header.join(';') + '\n' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    const wh = shortWhName(currentWarehouseName()).replace(/[^\wа-яА-ЯёЁ-]/g, '_');
    a.href = URL.createObjectURL(blob);
    a.download = `склад_${wh}_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
    const loaded = state.stockRows.length;
    const note = rows.length > loaded ? ` (в таблице было ${loaded})` : '';
    toast(`Экспорт: ${rows.length} поз.${note}`, 'ok');
  } catch (e) {
    toast(e.message || 'Ошибка экспорта', 'err');
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function loadPhoneAccess() {
  const link = $('#phone-url');
  const qr = $('#phone-qr');
  if (!link) return;
  try {
    const host = window.location.hostname;
    const isLocal = ['127.0.0.1', 'localhost'].includes(host);
    let url;
    if (!isLocal) {
      // Prod/public: без /network (oneshot + часто 503)
      url = `${window.location.origin}${(window.deventUrl || ((p) => p))('/app/store')}`;
    } else {
      const res = await fetch((window.deventUrl || ((p) => p))('/network')).then((r) => r.json());
      if (!res.phone_ready) {
        link.textContent = 'Задайте WB_API_HOST=0.0.0.0';
        link.removeAttribute('href');
        return;
      }
      const urls = res.store_urls?.length ? res.store_urls : res.app_urls;
      url = urls.find((u) => !u.includes('127.0.0.1')) || urls[0];
    }
    if (!url) {
      link.textContent = 'Нет адреса в Wi‑Fi';
      return;
    }
    link.href = url;
    link.textContent = url.replace(/^https?:\/\//, '');
    if (qr) {
      qr.loading = 'lazy';
      qr.src = `https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=${encodeURIComponent(url)}`;
      qr.classList.remove('hidden');
    }
  } catch {
    link.textContent = window.location.host || 'Ошибка сети';
    link.href = (window.deventUrl || ((p) => p))('/app/store');
  }
}

async function refreshStockData({ silent = false } = {}) {
  const btn = $('#btn-refresh-stock');
  const prevHtml = btn?.innerHTML;
  if (!silent && btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="sync-icon spinning" id="sync-icon">↻</span> Обновление…';
  } else setSyncing(true);
  try {
    await loadWarehouses({ silent: true });
    await fetchStock({ silent, useCache: false, reset: true });
    state.lastUpdated = new Date();
    if (!silent) toast('Остатки обновлены', 'ok');
  } finally {
    setSyncing(false);
    if (!silent && btn) {
      btn.disabled = false;
      btn.innerHTML = prevHtml || '<span class="sync-icon" id="sync-icon">↻</span> Обновить';
    }
  }
}

async function loadWarehouses({ silent = false, light = true } = {}) {
  const qs = light ? '?include_stats=false' : '';
  perfMark('wh-fetch-start');
  let rows = await api(`/warehouses${qs}`, { silent });
  perfMark('wh-fetch-end');
  perfMeasure('warehouses', 'wh-fetch-start', 'wh-fetch-end');
  if (light) {
    // Не затираем stock_lines/units нулями из light-ответа
    const prev = loadWarehousesCache();
    if (prev?.rows?.length) {
      const byId = new Map(prev.rows.map((w) => [w.id, w]));
      rows = rows.map((w) => {
        const old = byId.get(w.id);
        if (!old) return w;
        const lines = Number(w.stock_lines || 0);
        const units = Number(w.stock_units || 0);
        if (lines || units) return w;
        return {
          ...w,
          stock_lines: old.stock_lines || 0,
          stock_units: old.stock_units || 0,
        };
      });
    }
  }
  saveWarehousesCache(rows);
  applyWarehousesList(rows);
}

async function createWarehouse(name) {
  const clean = String(name || '').trim();
  if (!clean) {
    toast('Введите название склада', 'err');
    return;
  }
  const btn = $('#warehouse-add-btn');
  if (btn) btn.disabled = true;
  try {
    const wh = await api('/warehouses', {
      method: 'POST',
      body: JSON.stringify({ name: clean }),
    });
    toast(`Склад «${wh.name}» добавлен`, 'ok');
    await loadWarehouses({ silent: true });
    selectWarehouse(wh.id);
    const input = $('#warehouse-add-name');
    if (input) input.value = '';
  } catch (e) {
    const msg = String(e.message || '');
    if (/вход в админ|401|сессия/i.test(msg)) {
      toast('Создание склада — только из админ-панели (/login)', 'err');
    } else {
      toast(msg, 'err');
    }
  } finally {
    if (btn) btn.disabled = false;
  }
}

function startStockPolling() {
  if (startStockPolling._timer) clearInterval(startStockPolling._timer);
  startStockPolling._timer = setInterval(() => {
    if (!navigator.onLine) return;
    if (document.visibilityState !== 'visible') return;
    if (state.view !== 'stock') return;
    // Не сбрасываем глубокий скролл полным refetch
    if (state.stockRows.length > STOCK_PAGE_SIZE) return;
    fetchStock({ silent: true, useCache: false, limit: STOCK_PAGE_SIZE }).catch(() => {});
  }, STOCK_POLL_MS);
}

async function fetchStock({ silent = false, useCache = true, reset = true, limit } = {}) {
  const expectedKey = stockCacheKey();
  const seq = reset ? ++stockFetchSeq : stockFetchSeq;

  if (reset && useCache) {
    const cached = loadStockCache();
    if (cached?.rows?.length) {
      state.stockRows = cached.rows;
      state.stockHasMore = cached.rows.length >= STOCK_PAGE_SIZE;
      renderCurrentData();
      // Свежий кэш — не блокируем UI сеть-запросом на boot
      if (cached.savedAt && Date.now() - cached.savedAt < STOCK_CACHE_TTL_MS) {
        // фоновое обновление без спиннера
        silent = true;
      }
    }
  }
  if (reset) {
    state.stockLoading = true;
    if (!silent) state.stockHasMore = true;
  }
  const pageSize = limit ?? STOCK_PAGE_SIZE;
  const params = stockRequestParams({ offset: 0, limit: pageSize });
  try {
    perfMark('stock-fetch-start');
    const rows = await api(`/stock?${params}`, { silent });
    perfMark('stock-fetch-end');
    perfMeasure('stock', 'stock-fetch-start', 'stock-fetch-end');
    // Устаревший ответ (сменили склад / новый запрос уже ушёл)
    if (seq !== stockFetchSeq || stockCacheKey() !== expectedKey) return;
    state.stockHasMore = rows.length >= pageSize;
    applyStockData(rows, { append: false });
  } finally {
    if (seq === stockFetchSeq) {
      state.stockLoading = false;
      updateStockLoadingUi();
    }
  }
}

async function loadMoreStock() {
  if (!state.stockHasMore || state.stockLoadingMore || state.stockLoading) return;
  const expectedKey = stockCacheKey();
  const seq = stockFetchSeq;
  state.stockLoadingMore = true;
  updateStockLoadingUi();
  const params = stockRequestParams({ offset: state.stockRows.length, limit: STOCK_PAGE_SIZE });
  try {
    const rows = await api(`/stock?${params}`, { silent: true });
    if (seq !== stockFetchSeq || stockCacheKey() !== expectedKey) return;
    state.stockHasMore = rows.length >= STOCK_PAGE_SIZE;
    applyStockData(rows, { append: true });
  } finally {
    if (seq === stockFetchSeq) {
      state.stockLoadingMore = false;
      updateStockLoadingUi();
    }
  }
}

function renderCurrentData({ append = false, fromIndex = 0 } = {}) {
  updateSortUi();
  updateDisplayModeUi();
  renderStockView({ append, fromIndex });
}

function movementKind(m) {
  const type = m.movement_type || '';
  const comment = String(m.comment || '').toLowerCase();
  if (type === 'transfer' || comment.includes('transfer')) return 'auto';
  if (comment.includes('заказу') || comment.includes('синхрон') || comment.includes('автомат')) {
    return 'auto';
  }
  if (type === 'inventory') return 'inventory';
  if (type === 'receipt') return 'receipt';
  if (type === 'expense') return 'expense';
  return 'auto';
}

function movementKindLabel(kind) {
  if (kind === 'receipt') return 'Приход';
  if (kind === 'expense') return 'Расход';
  if (kind === 'inventory') return 'Инвентаризация';
  if (kind === 'auto') return 'Автоматика';
  return kind;
}

function journalItemHtml(m, { showType = false } = {}) {
  const kind = movementKind(m);
  const type = m.movement_type || kind;
  let qtyClass = 'out';
  let qtyPrefix = '−';
  if (type === 'receipt') {
    qtyClass = 'in';
    qtyPrefix = '+';
  } else if (type === 'inventory') {
    qtyClass = 'inv';
    const match = (m.comment || '').match(/было\s+(-?\d+),\s*стало\s+(-?\d+)/i);
    if (match) {
      qtyPrefix = Number(match[2]) >= Number(match[1]) ? '+' : '−';
    } else {
      qtyPrefix = '±';
    }
  }
  const typeBadge = showType
    ? `<span class="journal-type ${esc(kind)}">${esc(movementKindLabel(kind))}</span>`
    : '';
  const title = m.product_name || m.supplier_article || 'Товар';
  const bits = [];
  if (m.supplier_article) bits.push(m.supplier_article);
  if (m.barcode) bits.push(m.barcode);
  bits.push(m.doc_number || (type === 'inventory' ? 'инвентаризация' : 'без номера'));
  bits.push(new Date(m.created_at).toLocaleString('ru-RU'));
  if (m.comment) bits.push(m.comment);
  return `
    <li class="journal-item">
      <span class="journal-qty ${qtyClass}">${qtyPrefix}${m.quantity}</span>
      <div class="journal-body">
        ${typeBadge}
        <div class="journal-title">${esc(title)}</div>
        <div class="journal-meta">${bits.map((b) => esc(String(b))).join(' · ')}</div>
      </div>
    </li>`;
}

function selectMovementProduct(product) {
  state.movementProduct = product
    ? {
      product_id: product.product_id ?? product.id ?? null,
      supplier_article: product.supplier_article || '',
      barcode: product.barcode || '',
      name: product.name || '',
    }
    : null;
  const target = state.movementPickerFor || 'receipt';
  const prefix = target === 'expense' ? 'expense' : target === 'transfer' ? 'transfer' : 'receipt';
  const idEl = $(`#${prefix}-product-id`);
  const artEl = $(`#${prefix}-article`);
  const barEl = $(`#${prefix}-barcode`);
  const label = $(`#product-picker-label-${prefix}`);
  if (idEl) idEl.value = state.movementProduct?.product_id != null ? String(state.movementProduct.product_id) : '';
  if (artEl) artEl.value = state.movementProduct?.supplier_article || '';
  if (barEl) barEl.value = state.movementProduct?.barcode || '';
  if (label) {
    if (!state.movementProduct) {
      label.textContent = 'Выберите товар…';
      label.classList.add('is-empty');
    } else {
      const art = state.movementProduct.supplier_article || '—';
      const bar = state.movementProduct.barcode || '—';
      const name = state.movementProduct.name ? ` · ${state.movementProduct.name}` : '';
      label.textContent = `${art} · ${bar}${name}`;
      label.classList.remove('is-empty');
    }
  }
}

function clearMovementProduct() {
  selectMovementProduct(null);
}

function setMovementType(type) {
  state.movementType = type === 'expense' ? 'expense' : 'receipt';
}

function productPickerParams({ offset = 0, limit = STOCK_PAGE_SIZE } = {}) {
  const params = new URLSearchParams({
    limit: String(limit),
    offset: String(offset),
    sort: 'article',
    sort_dir: 'asc',
  });
  if (!isAllWarehouses() && whId() != null) params.set('warehouse_id', String(whId()));
  const q = state.productPickerSearch.trim();
  if (q) params.set('q', q);
  return params;
}

function renderProductPickerList({ append = false } = {}) {
  const ul = $('#product-picker-list');
  if (!ul) return;
  const q = state.productPickerSearch.trim();
  const selectedId = state.movementProduct?.product_id;
  const rows = state.productPickerRows;
  if (!rows.length) {
    ul.innerHTML = `<li class="warehouse-empty">${q ? 'Ничего не найдено' : 'Нет товаров'}</li>`;
    return;
  }
  const html = rows.map((p) => {
    const active = selectedId != null && Number(selectedId) === Number(p.product_id) ? ' active' : '';
    const free = qtyOf(p);
    return `<li>
      <button type="button" class="warehouse-item${active}" data-product-id="${p.product_id}">
        <span class="warehouse-item-name">
          ${highlight(p.supplier_article || '—', q)}
          <br><span style="font-weight:400;color:var(--muted)">${highlight(p.name || '', q)}</span>
        </span>
        <span class="product-picker-meta">
          <span>${highlight(p.barcode || '—', q)}</span>
          <span>${free.toLocaleString('ru-RU')} св.</span>
        </span>
      </button>
    </li>`;
  }).join('');
  if (append) ul.insertAdjacentHTML('beforeend', html);
  else ul.innerHTML = html;
}

function updateProductPickerSearchClear() {
  const btn = $('#product-picker-search-clear');
  const has = Boolean(state.productPickerSearch.trim());
  btn?.classList.toggle('hidden', !has);
}

function updateProductPickerLoadingUi() {
  const el = $('#product-picker-loading');
  const show = state.productPickerLoading || state.productPickerLoadingMore;
  el?.classList.toggle('hidden', !show);
  if (el) el.textContent = state.productPickerLoadingMore ? 'Ещё…' : 'Загрузка…';
}

async function fetchProductPickerRows({ reset = true } = {}) {
  if (state.productPickerLoading || state.productPickerLoadingMore) return;
  if (reset) {
    state.productPickerLoading = true;
    state.productPickerRows = [];
    state.productPickerHasMore = true;
    updateProductPickerLoadingUi();
    renderProductPickerList();
  } else {
    if (!state.productPickerHasMore) return;
    state.productPickerLoadingMore = true;
    updateProductPickerLoadingUi();
  }
  try {
    const offset = reset ? 0 : state.productPickerRows.length;
    const rows = await api(`/stock?${productPickerParams({ offset })}`, { silent: true });
    state.productPickerHasMore = rows.length >= STOCK_PAGE_SIZE;
    if (reset) state.productPickerRows = rows;
    else state.productPickerRows = state.productPickerRows.concat(rows);
    renderProductPickerList({ append: !reset });
  } finally {
    state.productPickerLoading = false;
    state.productPickerLoadingMore = false;
    updateProductPickerLoadingUi();
  }
}

function maybeLoadMoreProductPicker() {
  const ul = $('#product-picker-list');
  if (!ul || state.productPickerLoading || state.productPickerLoadingMore || !state.productPickerHasMore) return;
  if (ul.scrollTop + ul.clientHeight >= ul.scrollHeight - 120) {
    fetchProductPickerRows({ reset: false }).catch((e) => toast(e.message, 'err'));
  }
}

function openProductPickerModal() {
  state.productPickerSearch = '';
  const input = $('#product-picker-search');
  if (input) input.value = '';
  updateProductPickerSearchClear();
  $('#product-picker-modal')?.classList.remove('hidden');
  document.body.classList.add('modal-open');
  input?.focus();
  fetchProductPickerRows({ reset: true }).catch((e) => toast(e.message, 'err'));
}

function closeProductPickerModal() {
  $('#product-picker-modal')?.classList.add('hidden');
  if ($('#warehouse-modal')?.classList.contains('hidden') && $('#product-modal')?.classList.contains('hidden')) {
    document.body.classList.remove('modal-open');
  }
  state.productPickerSearch = '';
  const input = $('#product-picker-search');
  if (input) input.value = '';
  updateProductPickerSearchClear();
}

async function loadOpsJournal() {
  const list = $('#list-ops');
  if (!list) return;
  const type = state.movementType;
  const params = new URLSearchParams({ movement_type: type, limit: '40' });
  if (!isAllWarehouses() && whId() != null) params.set('warehouse_id', String(whId()));
  const rows = await api(`/movements?${params}`, { silent: true });
  list.innerHTML = rows.length
    ? rows.map((m) => journalItemHtml(m)).join('')
    : '<li class="store-empty-inline"><p>Операций пока нет</p></li>';
}

function logsParams({ offset = 0, limit = LOGS_PAGE_SIZE } = {}) {
  const params = new URLSearchParams({
    limit: String(limit),
    offset: String(offset),
  });
  if (!isAllWarehouses() && whId() != null) params.set('warehouse_id', String(whId()));
  const q = state.logsSearch.trim();
  if (q) params.set('q', q);
  return params;
}

function renderLogsList({ append = false } = {}) {
  const list = $('#list-logs');
  if (!list) return;
  const rows = state.logsRows;
  if (!rows.length) {
    list.innerHTML = '<li class="store-empty-inline"><p>Записей пока нет</p></li>';
    return;
  }
  const html = rows.map((m) => journalItemHtml(m, { showType: true })).join('');
  if (append) {
    const empty = list.querySelector('.store-empty-inline');
    if (empty) list.innerHTML = '';
    list.insertAdjacentHTML('beforeend', html);
  } else {
    list.innerHTML = html;
  }
}

function updateLogsLoadingUi() {
  const el = $('#logs-loading');
  const show = state.logsLoading || state.logsLoadingMore;
  el?.classList.toggle('hidden', !show);
  if (el) el.textContent = state.logsLoadingMore ? 'Ещё…' : 'Загрузка…';
}

async function fetchLogs({ reset = true } = {}) {
  if (state.logsLoading || state.logsLoadingMore) return;
  if (reset) {
    state.logsLoading = true;
    state.logsRows = [];
    state.logsHasMore = true;
    updateLogsLoadingUi();
    renderLogsList();
  } else {
    if (!state.logsHasMore) return;
    state.logsLoadingMore = true;
    updateLogsLoadingUi();
  }
  try {
    const offset = reset ? 0 : state.logsRows.length;
    const rows = await api(`/movements?${logsParams({ offset })}`, { silent: true });
    state.logsHasMore = rows.length >= LOGS_PAGE_SIZE;
    if (reset) state.logsRows = rows;
    else state.logsRows = state.logsRows.concat(rows);
    renderLogsList({ append: !reset });
  } finally {
    state.logsLoading = false;
    state.logsLoadingMore = false;
    updateLogsLoadingUi();
  }
}

function maybeLoadMoreLogs() {
  const list = $('#list-logs');
  if (!list || state.logsLoading || state.logsLoadingMore || !state.logsHasMore) return;
  if (list.scrollTop + list.clientHeight >= list.scrollHeight - 120) {
    fetchLogs({ reset: false }).catch((e) => toast(e.message, 'err'));
  }
}

function bindMovementForm() {
  ['#form-receipt', '#form-expense'].forEach((sel) => {
    const form = $(sel);
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const warehouseId = requireWarehouseId();
      if (warehouseId == null) return;
      const type = form.dataset.movType || 'receipt';
      const productId = fd.get('product_id');
      const article = String(fd.get('supplier_article') || '').trim();
      const barcode = String(fd.get('barcode') || '').trim();
      if (!productId && !article && !barcode) {
        toast('Выберите товар', 'err');
        state.movementPickerFor = type;
        openProductPickerModal();
        return;
      }
      try {
        await api(`/movements/${type}`, {
          method: 'POST',
          body: JSON.stringify({
            warehouse_id: warehouseId,
            product_id: productId ? Number(productId) : null,
            supplier_article: article,
            barcode,
            quantity: Number(fd.get('quantity')),
            doc_number: fd.get('doc_number') || '',
            comment: fd.get('comment') || '',
          }),
        });
        toast(type === 'receipt' ? 'Приход проведён' : 'Расход проведён', 'ok');
        e.target.reset();
        const qty = form.querySelector('[name="quantity"]');
        if (qty) qty.value = '1';
        state.movementPickerFor = type;
        clearMovementProduct();
        fetchStock({ silent: true });
      } catch (err) { toast(err.message, 'err'); }
    });
  });
}

function parseMovementsCsv(text) {
  const lines = String(text || '').replace(/^\uFEFF/, '').split(/\r?\n/).filter((l) => l.trim());
  if (lines.length < 2) return [];
  const split = (line) => {
    const out = [];
    let cur = '';
    let q = false;
    for (let i = 0; i < line.length; i += 1) {
      const ch = line[i];
      if (ch === '"') {
        if (q && line[i + 1] === '"') { cur += '"'; i += 1; }
        else q = !q;
      } else if ((ch === ',' || ch === ';') && !q) {
        out.push(cur.trim());
        cur = '';
      } else cur += ch;
    }
    out.push(cur.trim());
    return out;
  };
  const headers = split(lines[0]).map((h) => h.toLowerCase().replace(/\s+/g, '_'));
  const idx = (names) => {
    for (const n of names) {
      const i = headers.indexOf(n);
      if (i >= 0) return i;
    }
    return -1;
  };
  const iPid = idx(['product_id', 'id']);
  const iArt = idx(['supplier_article', 'article', 'артикул']);
  const iBc = idx(['barcode', 'ean', 'штрихкод']);
  const iQty = idx(['quantity', 'qty', 'количество']);
  const iDoc = idx(['doc_number', 'doc', 'документ']);
  const iCom = idx(['comment', 'комментарий']);
  const rows = [];
  for (let r = 1; r < lines.length; r += 1) {
    const cols = split(lines[r]);
    if (!cols.some((c) => c)) continue;
    const qtyRaw = iQty >= 0 ? cols[iQty] : '';
    const qty = Number(String(qtyRaw).replace(',', '.'));
    if (!(qty > 0)) continue;
    const pidRaw = iPid >= 0 ? cols[iPid] : '';
    rows.push({
      product_id: pidRaw ? Number(pidRaw) : null,
      supplier_article: iArt >= 0 ? (cols[iArt] || '') : '',
      barcode: iBc >= 0 ? (cols[iBc] || '') : '',
      quantity: qty,
      doc_number: iDoc >= 0 ? (cols[iDoc] || '') : '',
      comment: iCom >= 0 ? (cols[iCom] || '') : '',
    });
  }
  return rows;
}

async function postMovementBatches(type, warehouseId, items) {
  const CHUNK = 400;
  let totalOk = 0;
  let totalFailed = 0;
  let lastErrors = [];
  for (let offset = 0; offset < items.length; offset += CHUNK) {
    const chunk = items.slice(offset, offset + CHUNK);
    const data = await api('/movements/batch', {
      method: 'POST',
      body: JSON.stringify({
        warehouse_id: warehouseId,
        movement_type: type,
        items: chunk,
      }),
    });
    totalOk += Number(data.ok || 0);
    totalFailed += Number(data.failed || 0);
    if (data.errors?.length) lastErrors = data.errors;
    toast(`Батч ${Math.min(offset + CHUNK, items.length)}/${items.length}… ок ${totalOk}`, 'ok');
  }
  return { totalOk, totalFailed, lastErrors };
}

async function importMovementsFile(type) {
  const input = $(`#import-${type}`);
  const file = input?.files?.[0];
  const warehouseId = requireWarehouseId();
  if (warehouseId == null) return;
  if (!file) { toast('Выберите файл CSV или XLSX', 'err'); return; }
  const name = (file.name || '').toLowerCase();
  try {
    toast('Импорт…', 'ok');
    let totalOk = 0;
    let totalFailed = 0;
    let lastErrors = [];

    if (name.endsWith('.csv') || name.endsWith('.txt')) {
      const text = await file.text();
      const items = parseMovementsCsv(text);
      if (!items.length) throw new Error('В CSV нет строк с quantity > 0');
      const r = await postMovementBatches(type, warehouseId, items);
      totalOk = r.totalOk;
      totalFailed = r.totalFailed;
      lastErrors = r.lastErrors;
    } else {
      const CHUNK = 400;
      let offset = 0;
      while (true) {
        const fd = new FormData();
        fd.append('movement_type', type);
        fd.append('warehouse_id', String(warehouseId));
        fd.append('file', file);
        fd.append('offset', String(offset));
        fd.append('limit', String(CHUNK));
        const res = await fetch(`${API}/movements/import`, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.detail || `Ошибка ${res.status}`);
        totalOk += Number(data.ok || 0);
        totalFailed += Number(data.failed || 0);
        if (data.errors?.length) lastErrors = data.errors;
        const processed = Number(data.processed || offset);
        const total = data.total != null ? Number(data.total) : processed;
        toast(`Импорт ${processed}/${total}… ок ${totalOk}`, 'ok');
        if (!data.has_more) break;
        offset = processed;
        if (offset <= 0) break;
      }
    }

    toast(
      `Импорт готов: ок ${totalOk}${totalFailed ? `, ошибок ${totalFailed}` : ''}`,
      totalFailed ? 'err' : 'ok',
    );
    if (lastErrors.length) console.warn(lastErrors);
    if (input) input.value = '';
    fetchStock({ silent: true });
    if (state.movementTab === 'logs') fetchLogs({ reset: true }).catch(() => {});
  } catch (e) { toast(e.message, 'err'); }
}

function fillTransferWarehouseSelects() {
  const options = warehouseList().map((w) =>
    `<option value="${w.id}">${esc(w.name)}</option>`
  ).join('');
  const from = $('#transfer-from');
  const to = $('#transfer-to');
  if (from) {
    from.innerHTML = options;
    if (whId() != null) from.value = String(whId());
  }
  if (to) {
    to.innerHTML = options;
    const list = warehouseList();
    const other = list.find((w) => w.id !== whId()) || list[1] || list[0];
    if (other) to.value = String(other.id);
  }
}

async function loadTransfersList() {
  const list = $('#list-transfers');
  if (!list) return;
  try {
    const rows = await api('/transfers?limit=50', { silent: true });
    state.transfers = rows;
    list.innerHTML = rows.length ? rows.map((t) => {
      const n = (t.items || []).length;
      const qty = (t.items || []).reduce((s, i) => s + (i.quantity || 0), 0);
      const when = t.created_at ? new Date(t.created_at).toLocaleString('ru-RU') : '';
      return `
        <li class="journal-item journal-item-click" role="button" tabindex="0" data-open-transfer="${t.id}">
          <div class="journal-body">
            <div class="journal-title">${esc(t.from_warehouse_name || '#' + t.from_warehouse_id)} → ${esc(t.to_warehouse_name || '#' + t.to_warehouse_id)}</div>
            <div class="journal-meta">#${t.id} · ${n} поз. · ${qty} шт.${t.doc_number ? ` · ${esc(t.doc_number)}` : ''} · ${esc(when)}</div>
          </div>
        </li>`;
    }).join('') : '<li class="warehouse-empty">Перемещений пока нет</li>';
    list.querySelectorAll('[data-open-transfer]').forEach((row) => {
      const open = () => openTransferModal(row.dataset.openTransfer);
      row.addEventListener('click', open);
      row.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
    });
  } catch (e) {
    list.innerHTML = `<li class="warehouse-empty">${esc(e.message)}</li>`;
  }
}

function openTransferModal(id) {
  const t = state.transfers.find((x) => String(x.id) === String(id));
  const modal = $('#transfer-modal');
  const body = $('#transfer-modal-body');
  const title = $('#transfer-modal-title');
  if (!t || !modal || !body) return;
  if (title) title.textContent = `Перемещение #${t.id}`;
  body.innerHTML = `
    <p class="ops-desc"><strong>${esc(t.from_warehouse_name)}</strong> → <strong>${esc(t.to_warehouse_name)}</strong></p>
    <p class="journal-meta">${t.doc_number ? esc(t.doc_number) + ' · ' : ''}${t.created_at ? new Date(t.created_at).toLocaleString('ru-RU') : ''}</p>
    ${t.comment ? `<p class="ops-desc">${esc(t.comment)}</p>` : ''}
    <h3 class="view-subtitle">Состав</h3>
    <ul class="store-journal">
      ${(t.items || []).map((i) => `
        <li class="journal-item">
          <span class="journal-qty inv">${i.quantity}</span>
          <div class="journal-body">
            <div class="journal-title">${esc(i.product_name || i.supplier_article || 'Товар #' + i.product_id)}</div>
            <div class="journal-meta">${esc(i.supplier_article)} · ${esc(i.barcode)}</div>
          </div>
        </li>`).join('') || '<li class="warehouse-empty">Пусто</li>'}
    </ul>`;
  modal.classList.remove('hidden');
  document.body.classList.add('modal-open');
}

function closeTransferModal() {
  $('#transfer-modal')?.classList.add('hidden');
  document.body.classList.remove('modal-open');
}

function openTransferCreateModal() {
  fillTransferWarehouseSelects();
  state.movementPickerFor = 'transfer';
  clearMovementProduct();
  $('#transfer-create-modal')?.classList.remove('hidden');
  document.body.classList.add('modal-open');
}

function closeTransferCreateModal() {
  $('#transfer-create-modal')?.classList.add('hidden');
  document.body.classList.remove('modal-open');
}

function setMovementTab(tab, { refresh = true } = {}) {
  const allowed = ['receipt', 'expense', 'transfers', 'logs'];
  state.movementTab = allowed.includes(tab) ? tab : 'receipt';
  $$('.movements-tab').forEach((btn) => {
    const active = btn.dataset.movement === state.movementTab;
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  $$('.movement-panel').forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.movement === state.movementTab);
  });
  if (refresh) refreshMovements();
}

function refreshMovements() {
  if (state.movementTab === 'logs') {
    fetchLogs({ reset: true }).catch((e) => toast(e.message, 'err'));
  } else if (state.movementTab === 'transfers') {
    loadTransfersList().catch((e) => toast(e.message, 'err'));
  }
}

function setView(view, opts = {}) {
  const allowed = ['stock', 'movements', 'orders', 'docs'];
  if (!allowed.includes(view)) view = 'stock';
  state.view = view;
  if (opts.movementTab) setMovementTab(opts.movementTab, { refresh: false });
  document.body.dataset.storeView = view;
  $$('.store-menu button[data-view]').forEach((b) => b.classList.toggle('active', b.dataset.view === view));
  $$('.store-view').forEach((v) => v.classList.toggle('active', v.dataset.view === view));
  $$('#mobile-nav button[data-view]').forEach((b) => b.classList.toggle('active', b.dataset.view === view));
  $$('.store-sidebar-linkbtn[data-view]').forEach((b) => b.classList.toggle('active', b.dataset.view === view));
  $('#sidebar').classList.remove('open');
  closeProductDetail();
  if (!opts.skipUrl) {
    const url = new URL(location.href);
    if (view === 'stock') url.searchParams.delete('view');
    else url.searchParams.set('view', view);
    const qs = url.searchParams.toString();
    history.replaceState(null, '', url.pathname + (qs ? `?${qs}` : '') + url.hash);
  }
  refreshView();
  window.scrollTo({ top: 0, behavior: 'instant' });
}

function refreshView() {
  const v = state.view;
  if (v === 'stock') {
    if (state.stockRows.length) {
      renderCurrentData();
      // Подтянуть свежие остатки после docs/orders/movements
      fetchStock({ silent: true, useCache: false, reset: true }).catch(() => {});
    } else {
      fetchStock({ silent: false }).catch((e) => toast(e.message, 'err'));
    }
  } else if (v === 'movements') {
    refreshMovements();
  } else if (v === 'orders') {
    const frame = $('#orders-frame');
    if (frame && (!frame.getAttribute('src') || frame.getAttribute('src') === 'about:blank')) {
      frame.src = (window.deventUrl || ((p) => p))('/app/wms?embed=orders');
    }
  }
}

function updateSearchClear() {
  const btn = $('#search-clear');
  const has = Boolean(state.search.trim());
  btn?.classList.toggle('hidden', !has);
}

$$('#store-menu button[data-view]').forEach((btn) => {
  btn.addEventListener('click', () => setView(btn.dataset.view));
});
$$('.store-sidebar-linkbtn[data-view]').forEach((btn) => {
  btn.addEventListener('click', () => setView(btn.dataset.view));
});
$$('#mobile-nav button[data-view]').forEach((btn) => {
  btn.addEventListener('click', () => setView(btn.dataset.view));
});
$$('.movements-tab').forEach((btn) => {
  btn.addEventListener('click', () => setMovementTab(btn.dataset.movement));
});

$('#warehouse-picker')?.addEventListener('click', () => openWarehouseModal());
$('#warehouse-modal-close')?.addEventListener('click', () => closeWarehouseModal());
$('#warehouse-modal-backdrop')?.addEventListener('click', () => closeWarehouseModal());
$('#warehouse-list')?.addEventListener('click', (e) => {
  const btn = e.target.closest('.warehouse-item[data-id]');
  if (!btn) return;
  const id = btn.dataset.id;
  selectWarehouse(id === ALL_WAREHOUSES ? ALL_WAREHOUSES : Number(id));
});
$('#warehouse-add-form')?.addEventListener('submit', (e) => {
  e.preventDefault();
  const input = $('#warehouse-add-name');
  createWarehouse(input?.value || '').catch((err) => toast(err.message, 'err'));
});
$('#product-modal-close')?.addEventListener('click', () => closeProductDetail());
$('#product-modal-backdrop')?.addEventListener('click', () => closeProductDetail());

let warehouseSearchTimer;
const warehouseSearchEl = $('#warehouse-search');
warehouseSearchEl?.addEventListener('input', (e) => {
  clearTimeout(warehouseSearchTimer);
  warehouseSearchTimer = setTimeout(() => {
    state.warehouseSearch = e.target.value;
    updateWarehouseSearchClear();
    renderWarehouseList();
  }, 120);
});
$('#warehouse-search-clear')?.addEventListener('click', () => {
  if (!warehouseSearchEl) return;
  warehouseSearchEl.value = '';
  state.warehouseSearch = '';
  updateWarehouseSearchClear();
  renderWarehouseList();
  warehouseSearchEl.focus();
});

$('#only-stock').addEventListener('change', (e) => {
  state.onlyStock = e.target.checked;
  savePrefs();
  fetchStock({ silent: false, useCache: false, reset: true }).catch((err) => toast(err.message, 'err'));
});

let searchTimer;
const searchEl = $('#global-search');
searchEl.addEventListener('input', (e) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    state.search = e.target.value;
    updateSearchClear();
    if (state.view === 'stock') {
      fetchStock({ silent: false, useCache: false, reset: true }).catch((err) => toast(err.message, 'err'));
    }
  }, 300);
});

$('#search-clear').addEventListener('click', () => {
  searchEl.value = '';
  state.search = '';
  updateSearchClear();
  fetchStock({ silent: false, useCache: false, reset: true }).catch((err) => toast(err.message, 'err'));
  searchEl.focus();
});

$$('.th-sort').forEach((btn) => {
  btn.addEventListener('click', () => {
    const col = btn.dataset.sort;
    if (state.sortCol === col) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
    else {
      state.sortCol = col;
      state.sortDir = col === 'free' || col === 'qty' ? 'desc' : 'asc';
    }
    savePrefs();
    fetchStock({ silent: false, useCache: false, reset: true }).catch((err) => toast(err.message, 'err'));
  });
});

$$('.view-mode-btn').forEach((btn) => {
  btn.addEventListener('click', () => {
    state.displayMode = btn.dataset.mode;
    savePrefs();
    updateDisplayModeUi();
    renderStockView();
    maybeLoadMoreStock();
  });
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    if (!$('#product-picker-modal')?.classList.contains('hidden')) {
      closeProductPickerModal();
      return;
    }
    if (!$('#product-modal')?.classList.contains('hidden')) {
      closeProductDetail();
      return;
    }
    if (!$('#warehouse-modal')?.classList.contains('hidden')) {
      closeWarehouseModal();
      return;
    }
  }
  if (e.target.matches('input, textarea, select')) {
    if (e.key === 'Escape') e.target.blur();
    return;
  }
  if (e.key === '/') {
    e.preventDefault();
    searchEl.focus();
    searchEl.select();
  } else if (e.key === 'r' || e.key === 'R' || e.key === 'к' || e.key === 'К') {
    refreshStockData().catch((err) => toast(err.message, 'err'));
  } else if (e.key >= '1' && e.key <= '2') {
    setView(VIEW_KEYS[e.key]);
  }
});

$('#btn-refresh-stock')?.addEventListener('click', () => refreshStockData().catch((e) => toast(e.message, 'err')));
$('#btn-export').addEventListener('click', () => exportCsv().catch((e) => toast(e.message, 'err')));
$('#phone-copy')?.addEventListener('click', () => {
  const url = $('#phone-url')?.href;
  if (url && url !== '#') copyText(url, 'Ссылка');
});
$('#mobile-refresh')?.addEventListener('click', () => refreshStockData().catch((e) => toast(e.message, 'err')));
$('#empty-refresh-btn')?.addEventListener('click', () => refreshStockData().catch((e) => toast(e.message, 'err')));

$('#menu-toggle').addEventListener('click', () => $('#sidebar').classList.toggle('open'));

$$('.product-picker[data-picker-for]').forEach((btn) => {
  btn.addEventListener('click', () => {
    state.movementPickerFor = btn.dataset.pickerFor || 'receipt';
    openProductPickerModal();
  });
});
$('#product-picker-modal-close')?.addEventListener('click', () => closeProductPickerModal());
$('#product-picker-modal-backdrop')?.addEventListener('click', () => closeProductPickerModal());
$('#product-picker-list')?.addEventListener('click', (e) => {
  const btn = e.target.closest('.warehouse-item[data-product-id]');
  if (!btn) return;
  const id = Number(btn.dataset.productId);
  const row = state.productPickerRows.find((r) => Number(r.product_id) === id);
  if (!row) return;
  selectMovementProduct(row);
  closeProductPickerModal();
});
$('#product-picker-list')?.addEventListener('scroll', () => maybeLoadMoreProductPicker());

$('#btn-import-receipt')?.addEventListener('click', () => importMovementsFile('receipt'));
$('#btn-import-expense')?.addEventListener('click', () => importMovementsFile('expense'));
$('#btn-new-transfer')?.addEventListener('click', () => openTransferCreateModal());
$('#transfer-modal-close')?.addEventListener('click', () => closeTransferModal());
$('#transfer-modal-backdrop')?.addEventListener('click', () => closeTransferModal());
$$('[data-transfer-create-close]').forEach((el) => {
  el.addEventListener('click', () => closeTransferCreateModal());
});
$('#form-transfer')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const productId = fd.get('product_id');
  const article = String(fd.get('supplier_article') || '').trim();
  const barcode = String(fd.get('barcode') || '').trim();
  if (!productId && !article && !barcode) {
    toast('Выберите товар', 'err');
    state.movementPickerFor = 'transfer';
    openProductPickerModal();
    return;
  }
  try {
    await api('/transfers', {
      method: 'POST',
      body: JSON.stringify({
        from_warehouse_id: Number(fd.get('from_warehouse_id')),
        to_warehouse_id: Number(fd.get('to_warehouse_id')),
        doc_number: fd.get('doc_number') || '',
        comment: fd.get('comment') || '',
        items: [{
          product_id: productId ? Number(productId) : null,
          supplier_article: article,
          barcode,
          quantity: Number(fd.get('quantity')),
        }],
      }),
    });
    toast('Перемещение проведено', 'ok');
    e.target.reset();
    closeTransferCreateModal();
    loadTransfersList().catch(() => {});
    fetchStock({ silent: true });
  } catch (err) { toast(err.message, 'err'); }
});

let productPickerSearchTimer;
const productPickerSearchEl = $('#product-picker-search');
productPickerSearchEl?.addEventListener('input', (e) => {
  clearTimeout(productPickerSearchTimer);
  productPickerSearchTimer = setTimeout(() => {
    state.productPickerSearch = e.target.value;
    updateProductPickerSearchClear();
    fetchProductPickerRows({ reset: true }).catch((err) => toast(err.message, 'err'));
  }, 300);
});
$('#product-picker-search-clear')?.addEventListener('click', () => {
  if (!productPickerSearchEl) return;
  productPickerSearchEl.value = '';
  state.productPickerSearch = '';
  updateProductPickerSearchClear();
  fetchProductPickerRows({ reset: true }).catch((err) => toast(err.message, 'err'));
  productPickerSearchEl.focus();
});

let logsSearchTimer;
const logsSearchEl = $('#logs-search');
logsSearchEl?.addEventListener('input', (e) => {
  clearTimeout(logsSearchTimer);
  logsSearchTimer = setTimeout(() => {
    state.logsSearch = e.target.value;
    const clearBtn = $('#logs-search-clear');
    clearBtn?.classList.toggle('hidden', !state.logsSearch.trim());
    if (state.movementTab === 'logs') fetchLogs({ reset: true }).catch((err) => toast(err.message, 'err'));
  }, 300);
});
$('#logs-search-clear')?.addEventListener('click', () => {
  if (!logsSearchEl) return;
  logsSearchEl.value = '';
  state.logsSearch = '';
  $('#logs-search-clear')?.classList.add('hidden');
  if (state.movementTab === 'logs') fetchLogs({ reset: true }).catch((err) => toast(err.message, 'err'));
  logsSearchEl.focus();
});
$('#list-logs')?.addEventListener('scroll', () => maybeLoadMoreLogs());

bindMovementForm();
setMovementTab('receipt', { refresh: false });
clearMovementProduct();

loadPrefs();
restoreWarehouseId();
const onlyStockEl = $('#only-stock');
if (onlyStockEl) onlyStockEl.checked = state.onlyStock;
savePrefs();
updateSortUi();
updateDisplayModeUi();
updateSearchClear();
setupStockInfiniteScroll();
ensureStockInteractions();

window.addEventListener('online', () => {
  fetchStock({ silent: true }).catch(() => {});
});

(async () => {
  perfMark('boot-start');
  // Phone / QR — после первого кадра, не блокирует каталог
  const deferPhone = () => {
    if (typeof requestIdleCallback === 'function') requestIdleCallback(() => loadPhoneAccess());
    else setTimeout(loadPhoneAccess, 400);
  };
  deferPhone();

  const bootView = (() => {
    const v = new URLSearchParams(location.search).get('view');
    return ['stock', 'movements', 'orders', 'docs'].includes(v) ? v : 'stock';
  })();

  // Instant paint из localStorage
  const whCached = loadWarehousesCache();
  if (whCached?.rows?.length) {
    applyWarehousesList(whCached.rows, { persistId: false });
  }
  const cached = loadStockCache();
  if (cached?.rows?.length) {
    state.stockRows = cached.rows;
    state.stockHasMore = cached.rows.length >= STOCK_PAGE_SIZE;
    renderCurrentData();
  }

  try {
    const tasks = [loadWarehouses({ silent: true, light: true })];
    if (bootView === 'stock') {
      const fresh = cached?.savedAt && Date.now() - cached.savedAt < STOCK_CACHE_TTL_MS;
      tasks.push(fetchStock({ silent: true, useCache: !fresh, reset: true }));
    }
    await Promise.all(tasks);
    setView(bootView);
    startStockPolling();
  } catch (e) {
    toast(e.message, 'err');
    await loadWarehouses({ silent: true, light: true }).catch(() => {});
    if (bootView === 'stock') {
      await fetchStock({ silent: true, useCache: true }).catch(() => {});
    }
    setView(bootView);
    startStockPolling();
  } finally {
    perfMark('boot-end');
    perfMeasure('boot', 'boot-start', 'boot-end');
  }
})();
