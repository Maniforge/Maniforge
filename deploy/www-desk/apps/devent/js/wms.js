const API = (window.deventUrl || ((p) => p))('/api/v1/wms');
const EMBED = new URLSearchParams(location.search).get('embed') || '';

const state = {
  warehouses: [],
  warehouseId: null,
  tab: EMBED === 'orders' ? 'orders' : 'stock',
  search: '',
  orders: [],
};

const $ = (s, r = document) => r.querySelector(s);

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

function esc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
}

function whId() {
  return state.warehouseId || state.warehouses[0]?.id;
}

function applyWarehouseSelect() {
  const sel = $('#warehouse-id');
  if (!sel) return;
  const base = state.warehouses.filter((w) => String(w.code || '').startsWith('_base/'));
  const list = base.length ? base : state.warehouses.filter((w) => !w.is_wb);
  const finalList = list.length ? list : state.warehouses;
  sel.innerHTML = finalList.map((w) =>
    `<option value="${w.id}">${esc(w.name)}</option>`
  ).join('');
  const saved = localStorage.getItem('wms-warehouse-id');
  const savedNum = saved ? Number(saved) : null;
  const valid = finalList.some((w) => w.id === savedNum);
  state.warehouseId = valid ? savedNum : finalList[0]?.id;
  if (state.warehouseId) sel.value = String(state.warehouseId);
}

async function loadWarehouses({ light = true } = {}) {
  try {
    const raw = JSON.parse(localStorage.getItem('wms-warehouses-cache') || 'null');
    if (raw?.v === 1 && Array.isArray(raw.rows) && raw.rows.length) {
      state.warehouses = raw.rows;
      applyWarehouseSelect();
    }
  } catch { /* ignore */ }
  const qs = light ? '?include_stats=false' : '';
  state.warehouses = await api(`/warehouses${qs}`);
  try {
    localStorage.setItem('wms-warehouses-cache', JSON.stringify({ v: 1, rows: state.warehouses, savedAt: Date.now() }));
  } catch { /* ignore */ }
  applyWarehouseSelect();
}

function setWmsStatus(text) {
  const el = $('#wms-status');
  if (el) el.textContent = text;
}

async function refreshWms({ silent = false } = {}) {
  const btn = $('#btn-refresh-wms');
  const prev = btn?.textContent;
  if (!silent && btn) {
    btn.disabled = true;
    btn.textContent = '…';
  }
  try {
    await loadWarehouses({ light: true });
    if (state.tab === 'stock') await loadStockList();
    else if (state.tab === 'products') await loadProductsList();
    else if (state.tab === 'orders') await loadOrdersList().catch(() => {});
    setWmsStatus(`WMS · ${new Date().toLocaleTimeString('ru-RU')}`);
    if (!silent) toast('Данные WMS обновлены', 'ok');
  } finally {
    if (!silent && btn) {
      btn.disabled = false;
      btn.textContent = prev || '↻';
    }
  }
}

function startWmsPolling(intervalSec = 60) {
  const ms = Math.max(15, intervalSec) * 1000;
  if (startWmsPolling._timer) clearInterval(startWmsPolling._timer);
  startWmsPolling._timer = setInterval(() => {
    if (document.visibilityState !== 'visible') return;
    if (state.tab === 'stock' || state.tab === 'products') {
      refreshWms({ silent: true }).catch(() => {});
    }
  }, ms);
}

function renderStockPanel() {
  return `
    <input type="search" class="wms-search" id="wms-q" placeholder="Артикул, штрихкод…" value="${esc(state.search)}" />
    <ul class="wms-list" id="wms-list"><li class="wms-empty">Загрузка…</li></ul>
  `;
}

function renderProductsPanel() {
  return `
    <input type="search" class="wms-search" id="wms-q" placeholder="Найти товар…" value="${esc(state.search)}" />
    <ul class="wms-list" id="wms-list"><li class="wms-empty">Загрузка…</li></ul>
  `;
}

function renderMovementForm(type) {
  const title = type === 'receipt' ? 'Приход на склад' : 'Расход со склада';
  return `
    <form class="wms-form card" id="mov-form">
      <h2 class="section-title">${title}</h2>
      <label>Артикул <input name="supplier_article" autocomplete="off" /></label>
      <label>Штрихкод <input name="barcode" inputmode="numeric" autocomplete="off" /></label>
      <label>Количество <input name="quantity" type="number" min="1" value="1" required /></label>
      <label>№ документа <input name="doc_number" autocomplete="off" /></label>
      <label>Комментарий <textarea name="comment"></textarea></label>
      <button type="submit" class="btn btn-primary">Провести</button>
    </form>
    <h3 class="section-title" style="margin-top:20px">Последние операции</h3>
    <ul class="wms-list" id="mov-history"></ul>
  `;
}

function renderOrdersPanel() {
  return `
    <section class="wms-orders-block">
      <div class="wms-orders-header">
        <h2 class="section-title">Список заказов</h2>
        <button type="button" class="btn btn-primary btn-sm wms-orders-add" id="btn-new-order" title="Новая заявка" aria-label="Новая заявка">+</button>
      </div>
      <ul class="wms-list" id="order-list"></ul>
    </section>

    <div class="wms-modal hidden" id="order-modal" aria-hidden="true">
      <div class="wms-modal-backdrop" data-order-close></div>
      <div class="wms-modal-panel" role="dialog" aria-modal="true" aria-labelledby="order-modal-title">
        <div class="wms-modal-header">
          <h2 id="order-modal-title">Заказ</h2>
          <button type="button" class="wms-modal-close" data-order-close aria-label="Закрыть">×</button>
        </div>
        <div class="wms-modal-body" id="order-modal-body"></div>
      </div>
    </div>

    <div class="wms-modal hidden" id="order-create-modal" aria-hidden="true">
      <div class="wms-modal-backdrop" data-create-close></div>
      <div class="wms-modal-panel" role="dialog" aria-modal="true" aria-labelledby="order-create-title">
        <div class="wms-modal-header">
          <h2 id="order-create-title">Новая заявка</h2>
          <button type="button" class="wms-modal-close" data-create-close aria-label="Закрыть">×</button>
        </div>
        <div class="wms-modal-body">
          <form class="wms-form" id="order-form">
            <label>Клиент
              <select name="client_id" id="order-client" required>
                <option value="">— выберите —</option>
              </select>
            </label>
            <div class="wms-inline-client">
              <input id="new-client-name" placeholder="Новый клиент (название)" />
              <button type="button" class="btn btn-sm" id="btn-add-client">+ клиент</button>
            </div>
            <label>Артикул <input name="supplier_article" autocomplete="off" /></label>
            <label>Штрихкод <input name="barcode" inputmode="numeric" /></label>
            <label>Количество <input name="quantity" type="number" min="1" value="1" required /></label>
            <label>Комментарий <textarea name="comment"></textarea></label>
            <div class="wms-order-actions">
              <button type="submit" class="btn btn-primary">Создать</button>
              <button type="button" class="btn" data-create-close>Отмена</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  `;
}

function closeCreateOrderModal() {
  const modal = $('#order-create-modal');
  if (!modal) return;
  modal.classList.add('hidden');
  modal.setAttribute('aria-hidden', 'true');
  if ($('#order-modal')?.classList.contains('hidden')) {
    document.body.classList.remove('wms-modal-open');
  }
}

function openCreateOrderModal() {
  const modal = $('#order-create-modal');
  if (!modal) return;
  modal.classList.remove('hidden');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('wms-modal-open');
  $('#order-client')?.focus();
}

function closeOrderModal() {
  const modal = $('#order-modal');
  if (!modal) return;
  modal.classList.add('hidden');
  modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('wms-modal-open');
}

function openOrderModal(orderId) {
  const order = state.orders.find((o) => String(o.id) === String(orderId));
  const modal = $('#order-modal');
  const body = $('#order-modal-body');
  const title = $('#order-modal-title');
  if (!order || !modal || !body || !title) return;

  const lines = order.items || [];
  title.textContent = `Заказ #${order.id}`;
  body.innerHTML = `
    <div class="wms-modal-meta">
      <span class="wms-status ${esc(order.status)}">${esc(order.status)}</span>
      <strong>${esc(order.client_name || 'Без клиента')}</strong>
      <span class="wms-item-meta">${new Date(order.created_at).toLocaleString('ru-RU')}</span>
      ${order.comment ? `<p class="wms-item-meta">${esc(order.comment)}</p>` : ''}
    </div>
    <h3 class="wms-modal-section">Состав</h3>
    <ul class="wms-order-lines">
      ${lines.length ? lines.map((i) => `
        <li class="wms-order-line">
          <span>${esc(i.supplier_article || i.product_name || '—')} ×${i.quantity}</span>
          <span class="wms-order-line-actions">
            ${order.status === 'pending' && i.quantity > 1
              ? `<button type="button" class="btn btn-sm" data-qty-dec="${order.id}" data-item="${i.id}" data-qty="${i.quantity}" title="Уменьшить">−1</button>`
              : ''}
            ${order.status === 'pending'
              ? `<button type="button" class="btn btn-sm" data-item-remove="${order.id}" data-item="${i.id}" title="Убрать из счёта">×</button>`
              : ''}
          </span>
        </li>
      `).join('') : '<li class="wms-empty">Пусто</li>'}
    </ul>
    ${order.status === 'pending' ? `
      <div class="wms-order-actions">
        <button type="button" class="btn btn-primary btn-sm" data-complete="${order.id}">Выполнить</button>
        <button type="button" class="btn btn-sm" data-cancel="${order.id}">Отмена</button>
      </div>` : ''}
  `;

  modal.classList.remove('hidden');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('wms-modal-open');
  bindOrderDetailActions(body);
}

function bindOrderDetailActions(root) {
  root.querySelectorAll('[data-complete]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await api(`/orders/${btn.dataset.complete}/complete`, { method: 'POST', body: '{}' });
        toast('Заявка выполнена, остатки списаны', 'ok');
        closeOrderModal();
        await loadOrdersList();
        if (state.tab === 'stock') loadStockList();
      } catch (e) { toast(e.message, 'err'); }
    });
  });
  root.querySelectorAll('[data-cancel]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await api(`/orders/${btn.dataset.cancel}/cancel`, { method: 'POST', body: '{}' });
        toast('Заявка отменена', 'ok');
        closeOrderModal();
        await loadOrdersList();
      } catch (e) { toast(e.message, 'err'); }
    });
  });
  root.querySelectorAll('[data-item-remove]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        const order = await api(`/orders/${btn.dataset.itemRemove}/items/${btn.dataset.item}`, {
          method: 'DELETE',
        });
        toast(order.status === 'cancelled' ? 'Позиция убрана, заявка пустая — отменена' : 'Позиция убрана из счёта', 'ok');
        await loadOrdersList();
        if (order.status === 'cancelled') closeOrderModal();
        else openOrderModal(order.id);
      } catch (e) { toast(e.message, 'err'); }
    });
  });
  root.querySelectorAll('[data-qty-dec]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const next = Math.max(0, Number(btn.dataset.qty) - 1);
      try {
        const order = await api(`/orders/${btn.dataset.qtyDec}/items/${btn.dataset.item}`, {
          method: 'PATCH',
          body: JSON.stringify({ quantity: next }),
        });
        toast(order.status === 'cancelled' ? 'Позиция убрана, заявка отменена' : `Количество → ${next}`, 'ok');
        await loadOrdersList();
        if (order.status === 'cancelled') closeOrderModal();
        else openOrderModal(order.id);
      } catch (e) { toast(e.message, 'err'); }
    });
  });
}

async function loadOrdersList() {
  const list = $('#order-list');
  if (!list) return;
  const rows = await api(`/orders?warehouse_id=${whId()}&status=pending&limit=50`);
  state.orders = rows;
  list.innerHTML = rows.length ? rows.map((o) => {
    const n = (o.items || []).length;
    const qty = (o.items || []).reduce((s, i) => s + (i.quantity || 0), 0);
    return `
    <li class="wms-item wms-order-row" role="button" tabindex="0" data-open-order="${o.id}">
      <span class="wms-status ${esc(o.status)}">${esc(o.status)}</span>
      <div class="wms-item-title">${esc(o.client_name || 'Без клиента')} · #${o.id}</div>
      <div class="wms-item-meta">${n} поз. · ${qty} шт. · ${new Date(o.created_at).toLocaleString('ru-RU')}</div>
    </li>`;
  }).join('') : '<li class="wms-empty">Текущих заказов нет</li>';

  list.querySelectorAll('[data-open-order]').forEach((row) => {
    const open = () => openOrderModal(row.dataset.openOrder);
    row.addEventListener('click', open);
    row.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        open();
      }
    });
  });

  const modal = $('#order-modal');
  if (modal && !modal.dataset.bound) {
    modal.dataset.bound = '1';
    modal.querySelectorAll('[data-order-close]').forEach((el) => {
      el.addEventListener('click', closeOrderModal);
    });
  }
}

function stockItemHtml(row) {
  const q = row.quantity ?? row.free_qty ?? 0;
  const free = row.free_qty ?? q;
  const asm = row.assembly_qty ?? 0;
  return `
    <li class="wms-item">
      <span class="wms-qty ${free > 0 ? '' : 'zero'}">${free}</span>
      <div class="wms-item-title">${esc(row.supplier_article || row.name || '—')}</div>
      <div class="wms-item-meta">${esc(row.barcode || '—')}${row.brand ? ` · ${esc(row.brand)}` : ''}</div>
      <div class="wms-item-meta">Остаток ${q} · наборка ${asm} · свободно ${free}</div>
    </li>
  `;
}

async function loadStockList() {
  const list = $('#wms-list');
  if (!list) return;
  try {
    const q = state.search.trim();
    const params = new URLSearchParams({
      warehouse_id: String(whId()),
      limit: '80',
      only_with_stock: 'true',
      sort: 'free',
      sort_dir: 'desc',
    });
    if (q) params.set('q', q);
    const rows = await api(`/stock?${params}`);
    list.innerHTML = rows.length
      ? rows.map(stockItemHtml).join('')
      : '<li class="wms-empty">Нет остатков на этом складе WMS</li>';
  } catch (e) {
    list.innerHTML = `<li class="wms-empty">${esc(e.message)}</li>`;
  }
}

async function loadProductsList() {
  const list = $('#wms-list');
  if (!list) return;
  try {
    const params = new URLSearchParams({
      warehouse_id: String(whId()),
      limit: '80',
    });
    if (state.search.trim()) params.set('q', state.search.trim());
    const rows = await api(`/products?${params}`);
    list.innerHTML = rows.length
      ? rows.map(stockItemHtml).join('')
      : '<li class="wms-empty">Товары не найдены</li>';
  } catch (e) {
    list.innerHTML = `<li class="wms-empty">${esc(e.message)}</li>`;
  }
}

async function loadMovementHistory(type) {
  const list = $('#mov-history');
  if (!list) return;
  const rows = await api(`/movements?warehouse_id=${whId()}&movement_type=${type}&limit=20`);
  list.innerHTML = rows.length ? rows.map((m) => `
    <li class="wms-item">
      <div class="wms-item-title">${type === 'receipt' ? '+' : '−'}${m.quantity} · ${esc(m.product_name || m.supplier_article)}</div>
      <div class="wms-item-meta">${esc(m.doc_number)} · ${new Date(m.created_at).toLocaleString('ru-RU')}</div>
    </li>
  `).join('') : '<li class="wms-empty">Пока нет операций</li>';
}

function bindSearch() {
  const inp = $('#wms-q');
  if (!inp) return;
  let t;
  inp.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => {
      state.search = inp.value;
      if (state.tab === 'stock') loadStockList();
      else if (state.tab === 'products') loadProductsList();
    }, 300);
  });
}

function bindMovementForm(type) {
  const form = $('#mov-form');
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const body = {
      warehouse_id: whId(),
      supplier_article: fd.get('supplier_article') || '',
      barcode: fd.get('barcode') || '',
      quantity: Number(fd.get('quantity')),
      doc_number: fd.get('doc_number') || '',
      comment: fd.get('comment') || '',
    };
    try {
      await api(`/movements/${type}`, { method: 'POST', body: JSON.stringify(body) });
      toast(type === 'receipt' ? 'Приход проведён' : 'Расход проведён', 'ok');
      form.reset();
      loadMovementHistory(type);
    } catch (err) { toast(err.message, 'err'); }
  });
  loadMovementHistory(type);
}

function bindOrderForm() {
  const form = $('#order-form');
  if (!form) return;

  async function fillClients(selectedId) {
    const sel = $('#order-client');
    if (!sel) return;
    const clients = await api('/clients?limit=500');
    sel.innerHTML = '<option value="">— выберите —</option>' + clients.map((c) =>
      `<option value="${c.id}"${String(c.id) === String(selectedId) ? ' selected' : ''}>${esc(c.name)}${c.inn ? ` (${esc(c.inn)})` : ''}</option>`
    ).join('');
  }

  $('#btn-new-order')?.addEventListener('click', () => openCreateOrderModal());

  const createModal = $('#order-create-modal');
  if (createModal && !createModal.dataset.bound) {
    createModal.dataset.bound = '1';
    createModal.querySelectorAll('[data-create-close]').forEach((el) => {
      el.addEventListener('click', closeCreateOrderModal);
    });
  }

  if (!document.body.dataset.wmsEscBound) {
    document.body.dataset.wmsEscBound = '1';
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      if (!$('#order-create-modal')?.classList.contains('hidden')) closeCreateOrderModal();
      else if (!$('#order-modal')?.classList.contains('hidden')) closeOrderModal();
    });
  }

  $('#btn-add-client')?.addEventListener('click', async () => {
    const name = ($('#new-client-name')?.value || '').trim();
    if (!name) { toast('Укажите название клиента', 'err'); return; }
    try {
      const c = await api('/clients', { method: 'POST', body: JSON.stringify({ name }) });
      toast('Клиент создан', 'ok');
      if ($('#new-client-name')) $('#new-client-name').value = '';
      await fillClients(c.id);
    } catch (err) { toast(err.message, 'err'); }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const clientId = Number(fd.get('client_id'));
    if (!clientId) { toast('Выберите клиента', 'err'); return; }
    const body = {
      warehouse_id: whId(),
      comment: fd.get('comment') || '',
      client_id: clientId,
      items: [{
        supplier_article: fd.get('supplier_article') || '',
        barcode: fd.get('barcode') || '',
        quantity: Number(fd.get('quantity')),
      }],
    };
    try {
      await api('/orders', { method: 'POST', body: JSON.stringify(body) });
      toast('Заявка создана', 'ok');
      form.reset();
      closeCreateOrderModal();
      await fillClients();
      loadOrdersList();
    } catch (err) { toast(err.message, 'err'); }
  });
  fillClients().then(() => loadOrdersList()).catch((e) => toast(e.message, 'err'));
}

async function showTab(tab) {
  state.tab = tab;
  state.search = '';
  $('#wms-nav').querySelectorAll('button').forEach((b) => {
    b.classList.toggle('active', b.dataset.tab === tab);
  });
  const main = $('#wms-main');
  if (tab === 'stock') {
    main.innerHTML = renderStockPanel();
    bindSearch();
    await loadStockList();
  } else if (tab === 'products') {
    main.innerHTML = renderProductsPanel();
    bindSearch();
    await loadProductsList();
  } else if (tab === 'receipt') {
    main.innerHTML = renderMovementForm('receipt');
    bindMovementForm('receipt');
  } else if (tab === 'expense') {
    main.innerHTML = renderMovementForm('expense');
    bindMovementForm('expense');
  } else if (tab === 'orders') {
    main.innerHTML = renderOrdersPanel();
    bindOrderForm();
  }
  window.scrollTo({ top: 0, behavior: 'instant' });
}

$('#warehouse-id').addEventListener('change', (e) => {
  state.warehouseId = Number(e.target.value);
  localStorage.setItem('wms-warehouse-id', String(state.warehouseId));
  showTab(state.tab);
});

$('#wms-nav').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-tab]');
  if (btn) showTab(btn.dataset.tab);
});

$('#btn-refresh-wms')?.addEventListener('click', () => refreshWms({ silent: false }));

(async () => {
  if (EMBED) {
    document.body.classList.add('wms-embed');
    if (EMBED === 'orders') document.body.classList.add('wms-embed-orders');
  }
  try {
    await loadWarehouses({ light: true });
    setWmsStatus('WMS');
    await showTab(state.tab);
    if (!EMBED) startWmsPolling(60);
  } catch (e) {
    toast(e.message, 'err');
    setWmsStatus(e.message);
    try {
      await loadWarehouses({ light: true });
      await showTab(state.tab);
    } catch (e2) {
      toast(e2.message, 'err');
    }
  }
})();
