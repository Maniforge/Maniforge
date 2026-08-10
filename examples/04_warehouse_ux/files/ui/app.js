(() => {
  const $ = (id) => document.getElementById(id);
  const state = {
    token: localStorage.getItem("mf_wh_token") || "",
    screen: localStorage.getItem("mf_wh_screen") || "dashboard",
    cache: {},
  };

  const SCREENS = [
    { id: "dashboard", num: "01", title: "Обзор склада", lead: "KPI, алерты по срокам и черновики документов" },
    { id: "receipt", num: "02", title: "Приёмка", lead: "Входящие поставки на зону RCV" },
    { id: "putaway", num: "03", title: "Размещение", lead: "Задачи putaway из приёмки в ячейку" },
    { id: "balances", num: "04", title: "Остатки", lead: "Балансы SKU × ячейка" },
    { id: "transfer", num: "05", title: "Перемещение", lead: "Внутренние трансферы между ячейками" },
    { id: "shipment", num: "06", title: "Отгрузка", lead: "Подбор и отгрузка по заказам" },
    { id: "stocktake", num: "07", title: "Инвентаризация", lead: "Книга vs факт, расхождения" },
    { id: "lots", num: "08", title: "Партии / FEFO", lead: "Сроки годности и карантин" },
    { id: "reserves", num: "09", title: "Резервы", lead: "Удержание остатка под заказ" },
    { id: "scanner", num: "10", title: "ТСД / сканер", lead: "Мобильный контур штрихкода" },
  ];

  const ENTITIES = [
    "wh_location", "wh_sku", "wh_balance", "wh_receipt", "wh_putaway",
    "wh_transfer", "wh_shipment", "wh_stocktake", "wh_lot", "wh_reserve",
  ];

  function dump(data) {
    const el = $("deskOut");
    el.hidden = false;
    el.textContent = typeof data === "string" ? data : JSON.stringify(data, null, 2);
  }
  function rbac() { return $("rbacUrl").value.replace(/\/$/, ""); }
  function me() { return $("manifestUrl").value.replace(/\/$/, ""); }

  async function api(url, opts = {}) {
    const headers = Object.assign({ "Content-Type": "application/json" }, opts.headers || {});
    if (state.token) headers.Authorization = `Bearer ${state.token}`;
    const res = await fetch(url, { ...opts, headers });
    const text = await res.text();
    let body;
    try { body = text ? JSON.parse(text) : null; } catch { body = { raw: text }; }
    if (!res.ok) {
      const err = new Error(body?.error || res.statusText);
      err.status = res.status; err.body = body; throw err;
    }
    return body;
  }

  function records(body) {
    if (Array.isArray(body?.records)) return body.records;
    if (Array.isArray(body?.items)) return body.items;
    if (Array.isArray(body)) return body;
    return [];
  }
  function dataOf(row) { return row?.data || row || {}; }

  async function loadAll() {
    const entries = await Promise.all(ENTITIES.map(async (code) => {
      try {
        const body = await api(`${me()}/api/data/${code}`);
        return [code, records(body).map(dataOf)];
      } catch {
        return [code, []];
      }
    }));
    state.cache = Object.fromEntries(entries);
  }

  async function ensure() {
    for (const code of ENTITIES) {
      const manifest = await fetch(`/manifests/manifest.${code}.json`).then((r) => r.json());
      try {
        await api(`${me()}/api/v1/manifests`, { method: "POST", body: JSON.stringify(manifest) });
      } catch (e) { if (e.status !== 409) throw e; }
    }
    dump({ ok: true, message: "10 складских схем готовы" });
  }

  async function seed() {
    await ensure();
    // Use same payloads as scripts/03_seed.sh via sequential posts
    const payload = await fetch("/seed-bundle.json").catch(() => null);
    // Inline seed if file missing
    const batches = {
      wh_location: [
        { code: "A-01-01", name: "Стеллаж A ряд 1", zone: "A", aisle: "01", rack: "01", bin: "01", status: "active" },
        { code: "A-01-02", name: "Стеллаж A ряд 2", zone: "A", aisle: "01", rack: "02", bin: "01", status: "active" },
        { code: "RCV-01", name: "Зона приёмки", zone: "RCV", aisle: "00", rack: "00", bin: "01", status: "active" },
        { code: "SHIP-01", name: "Зона отгрузки", zone: "SHIP", aisle: "00", rack: "00", bin: "01", status: "active" },
      ],
      wh_sku: [
        { sku: "SKU-MILK-1L", name: "Молоко 1л", uom: "шт", barcode: "4601234567890", track_lot: true, status: "active" },
        { sku: "SKU-BOX-M", name: "Короб M", uom: "шт", barcode: "4601234567891", track_lot: false, status: "active" },
        { sku: "SKU-TAPE", name: "Скотч 48мм", uom: "шт", barcode: "4601234567892", track_lot: false, status: "active" },
      ],
      wh_balance: [
        { sku: "SKU-MILK-1L", location_code: "A-01-01", qty: 120, lot_code: "LOT-2408", expires_at: "2026-09-20", status: "available" },
        { sku: "SKU-BOX-M", location_code: "A-01-02", qty: 80, lot_code: "", expires_at: "", status: "available" },
        { sku: "SKU-TAPE", location_code: "A-01-02", qty: 40, lot_code: "", expires_at: "", status: "available" },
      ],
      wh_receipt: [
        { doc_no: "RCV-1001", supplier: "ООО МолПром", sku: "SKU-MILK-1L", qty: 50, location_code: "RCV-01", lot_code: "LOT-2410", status: "draft", received_at: "2026-08-11" },
      ],
      wh_putaway: [
        { task_no: "PUT-2001", sku: "SKU-MILK-1L", qty: 50, from_location: "RCV-01", to_location: "A-01-01", status: "open" },
      ],
      wh_transfer: [
        { doc_no: "TR-3001", sku: "SKU-BOX-M", qty: 10, from_location: "A-01-02", to_location: "SHIP-01", status: "draft" },
      ],
      wh_shipment: [
        { doc_no: "SHP-4001", order_ref: "SO-7781", sku: "SKU-MILK-1L", qty: 24, location_code: "A-01-01", status: "picking", customer: "Магазин Север" },
      ],
      wh_stocktake: [
        { doc_no: "INV-5001", sku: "SKU-TAPE", location_code: "A-01-02", book_qty: 40, count_qty: 38, status: "counted" },
      ],
      wh_lot: [
        { lot_code: "LOT-2408", sku: "SKU-MILK-1L", qty: 120, produced_at: "2026-08-01", expires_at: "2026-09-20", status: "active" },
        { lot_code: "LOT-2410", sku: "SKU-MILK-1L", qty: 50, produced_at: "2026-08-10", expires_at: "2026-09-30", status: "quarantine" },
      ],
      wh_reserve: [
        { reserve_no: "RSV-6001", order_ref: "SO-7781", sku: "SKU-MILK-1L", qty: 24, location_code: "A-01-01", status: "held", expires_at: "2026-08-12" },
      ],
    };
    void payload;
    for (const [code, rows] of Object.entries(batches)) {
      for (const row of rows) {
        await api(`${me()}/api/data/${code}`, { method: "POST", body: JSON.stringify(row) });
      }
    }
    dump({ ok: true, message: "Демо-склад засеян" });
    await refresh();
  }

  function cardList(rows, htmlFn) {
    if (!rows.length) return `<div class="card meta">Пока пусто — нажмите Seed или создайте запись справа.</div>`;
    return `<div class="list">${rows.map((d) => `<div class="card">${htmlFn(d)}</div>`).join("")}</div>`;
  }

  function renderScreen() {
    const screen = SCREENS.find((s) => s.id === state.screen) || SCREENS[0];
    $("screenTitle").textContent = `${screen.num}. ${screen.title}`;
    $("screenLead").textContent = screen.lead;
    document.querySelectorAll(".nav button").forEach((b) => b.classList.toggle("active", b.dataset.id === screen.id));

    const c = state.cache;
    const kpis = $("kpis");
    const content = $("content");

    if (screen.id === "dashboard") {
      kpis.hidden = false;
      const expiring = (c.wh_lot || []).filter((l) => l.expires_at && l.expires_at <= "2026-09-25").length;
      kpis.innerHTML = `
        <div class="kpi"><div class="v">${(c.wh_sku || []).length}</div><div class="l">SKU</div></div>
        <div class="kpi"><div class="v">${(c.wh_balance || []).reduce((s, b) => s + Number(b.qty || 0), 0)}</div><div class="l">Ед. на остатке</div></div>
        <div class="kpi"><div class="v">${(c.wh_receipt || []).filter((r) => r.status === "draft").length + (c.wh_putaway || []).filter((r) => r.status === "open").length}</div><div class="l">Открытые задачи</div></div>
        <div class="kpi"><div class="v">${expiring}</div><div class="l">Партии с ближним сроком</div></div>`;
      content.innerHTML = `
        <div class="grid-2">
          <div>${cardList(c.wh_shipment || [], (d) => `<strong>${d.doc_no}</strong><span class="badge">${d.status}</span><div class="meta">${d.order_ref} · ${d.sku} × ${d.qty} · ${d.customer || ""}</div>`)}</div>
          <div>${cardList((c.wh_lot || []).filter((l) => l.status === "quarantine" || (l.expires_at && l.expires_at <= "2026-09-25")), (d) => `<strong>${d.lot_code}</strong><span class="badge warn">${d.status}</span><div class="meta">${d.sku} · до ${d.expires_at} · ${d.qty}</div>`)}</div>
        </div>`;
      return;
    }

    kpis.hidden = true;

    const forms = {
      receipt: {
        entity: "wh_receipt",
        fields: [
          ["doc_no", "RCV-" + Date.now().toString().slice(-4)],
          ["supplier", "ООО Поставщик"],
          ["sku", "SKU-MILK-1L"],
          ["qty", "20"],
          ["location_code", "RCV-01"],
          ["lot_code", "LOT-NEW"],
        ],
        defaults: { status: "draft", received_at: new Date().toISOString().slice(0, 10) },
        list: () => cardList(c.wh_receipt || [], (d) => `<strong>${d.doc_no}</strong><span class="badge">${d.status}</span><div class="meta">${d.supplier} · ${d.sku} × ${d.qty} → ${d.location_code}</div>`),
      },
      putaway: {
        entity: "wh_putaway",
        fields: [["task_no", "PUT-" + Date.now().toString().slice(-4)], ["sku", "SKU-MILK-1L"], ["qty", "20"], ["from_location", "RCV-01"], ["to_location", "A-01-01"]],
        defaults: { status: "open" },
        list: () => cardList(c.wh_putaway || [], (d) => `<strong>${d.task_no}</strong><span class="badge">${d.status}</span><div class="meta">${d.sku} × ${d.qty} · ${d.from_location} → ${d.to_location}</div>`),
      },
      balances: {
        entity: "wh_balance",
        fields: [["sku", "SKU-BOX-M"], ["location_code", "A-01-02"], ["qty", "5"], ["lot_code", ""], ["expires_at", ""]],
        defaults: { status: "available" },
        list: () => cardList(c.wh_balance || [], (d) => `<strong>${d.sku}</strong><span class="badge">${d.status}</span><div class="meta">${d.location_code} · qty ${d.qty}${d.lot_code ? " · lot " + d.lot_code : ""}</div>`),
      },
      transfer: {
        entity: "wh_transfer",
        fields: [["doc_no", "TR-" + Date.now().toString().slice(-4)], ["sku", "SKU-TAPE"], ["qty", "5"], ["from_location", "A-01-02"], ["to_location", "SHIP-01"]],
        defaults: { status: "draft" },
        list: () => cardList(c.wh_transfer || [], (d) => `<strong>${d.doc_no}</strong><span class="badge">${d.status}</span><div class="meta">${d.sku} × ${d.qty} · ${d.from_location} → ${d.to_location}</div>`),
      },
      shipment: {
        entity: "wh_shipment",
        fields: [["doc_no", "SHP-" + Date.now().toString().slice(-4)], ["order_ref", "SO-9001"], ["sku", "SKU-MILK-1L"], ["qty", "12"], ["location_code", "A-01-01"], ["customer", "Клиент"]],
        defaults: { status: "picking" },
        list: () => cardList(c.wh_shipment || [], (d) => `<strong>${d.doc_no}</strong><span class="badge">${d.status}</span><div class="meta">${d.order_ref} · ${d.sku} × ${d.qty} · ${d.customer || ""}</div>`),
      },
      stocktake: {
        entity: "wh_stocktake",
        fields: [["doc_no", "INV-" + Date.now().toString().slice(-4)], ["sku", "SKU-BOX-M"], ["location_code", "A-01-02"], ["book_qty", "80"], ["count_qty", "80"]],
        defaults: { status: "counted" },
        list: () => cardList(c.wh_stocktake || [], (d) => {
          const diff = Number(d.count_qty) - Number(d.book_qty);
          return `<strong>${d.doc_no}</strong><span class="badge ${diff !== 0 ? "warn" : ""}">${d.status}</span><div class="meta">${d.sku} @ ${d.location_code} · книга ${d.book_qty} / факт ${d.count_qty} (Δ ${diff})</div>`;
        }),
      },
      lots: {
        entity: "wh_lot",
        fields: [["lot_code", "LOT-" + Date.now().toString().slice(-4)], ["sku", "SKU-MILK-1L"], ["qty", "30"], ["produced_at", "2026-08-11"], ["expires_at", "2026-10-01"]],
        defaults: { status: "active" },
        list: () => cardList(c.wh_lot || [], (d) => `<strong>${d.lot_code}</strong><span class="badge ${d.status === "quarantine" ? "warn" : ""}">${d.status}</span><div class="meta">${d.sku} · ${d.qty} · до ${d.expires_at}</div>`),
      },
      reserves: {
        entity: "wh_reserve",
        fields: [["reserve_no", "RSV-" + Date.now().toString().slice(-4)], ["order_ref", "SO-9002"], ["sku", "SKU-BOX-M"], ["qty", "8"], ["location_code", "A-01-02"], ["expires_at", "2026-08-15"]],
        defaults: { status: "held" },
        list: () => cardList(c.wh_reserve || [], (d) => `<strong>${d.reserve_no}</strong><span class="badge">${d.status}</span><div class="meta">${d.order_ref} · ${d.sku} × ${d.qty} @ ${d.location_code || "—"}</div>`),
      },
    };

    if (screen.id === "scanner") {
      content.innerHTML = `
        <div class="grid-2">
          <div class="scanner">
            <div class="brand" style="font-size:1.3rem">Скан ШК / ячейки</div>
            <p class="meta">Эмуляция ТСД: введите barcode или код ячейки и Enter</p>
            <input id="scanInput" placeholder="4601234567890 или A-01-01" />
            <div id="scanResult" class="meta" style="margin-top:0.85rem"></div>
          </div>
          <div>
            <h3 style="margin:0 0 0.5rem;font-size:0.9rem;color:var(--muted)">ЛОКАЦИИ</h3>
            ${cardList(c.wh_location || [], (d) => `<strong>${d.code}</strong><div class="meta">${d.name} · зона ${d.zone}</div>`)}
          </div>
        </div>`;
      const input = $("scanInput");
      input.focus();
      input.onkeydown = (e) => {
        if (e.key !== "Enter") return;
        const q = input.value.trim();
        const sku = (c.wh_sku || []).find((s) => s.barcode === q || s.sku === q);
        const loc = (c.wh_location || []).find((l) => l.code === q);
        const bal = (c.wh_balance || []).filter((b) => b.sku === (sku?.sku || q) || b.location_code === q);
        $("scanResult").innerHTML = sku
          ? `SKU <strong>${sku.sku}</strong> — ${sku.name}<br/>остатки: ${bal.map((b) => `${b.location_code}:${b.qty}`).join(", ") || "нет"}`
          : loc
            ? `Ячейка <strong>${loc.code}</strong> — ${loc.name}<br/>содержимое: ${bal.map((b) => `${b.sku}:${b.qty}`).join(", ") || "пусто"}`
            : `Ничего не найдено по «${q}»`;
      };
      return;
    }

    const cfg = forms[screen.id];
    if (!cfg) return;
    content.innerHTML = `
      <div class="grid-2">
        <div>${cfg.list()}</div>
        <div class="card">
          <strong>Новая запись</strong>
          <div class="form-grid" style="margin-top:0.75rem">
            ${cfg.fields.map(([name, val]) => `<label>${name}<input data-f="${name}" value="${val}" /></label>`).join("")}
          </div>
          <button type="button" id="btnCreate" style="margin-top:0.85rem">Сохранить</button>
        </div>
      </div>`;
    $("btnCreate").onclick = async () => {
      const payload = { ...cfg.defaults };
      content.querySelectorAll("[data-f]").forEach((el) => {
        const key = el.getAttribute("data-f");
        let v = el.value;
        if (["qty", "book_qty", "count_qty", "capacity"].includes(key)) v = Number(v);
        payload[key] = v;
      });
      try {
        const created = await api(`${me()}/api/data/${cfg.entity}`, { method: "POST", body: JSON.stringify(payload) });
        dump(created);
        await refresh();
      } catch (e) {
        dump(e.body || String(e));
      }
    };
  }

  function buildNav() {
    const nav = $("nav");
    nav.innerHTML = "";
    for (const s of SCREENS) {
      const b = document.createElement("button");
      b.type = "button";
      b.dataset.id = s.id;
      b.innerHTML = `<span class="num">${s.num}</span>${s.title}`;
      b.onclick = () => {
        state.screen = s.id;
        localStorage.setItem("mf_wh_screen", s.id);
        renderScreen();
      };
      nav.appendChild(b);
    }
  }

  async function refresh() {
    await loadAll();
    renderScreen();
  }

  async function login() {
    const body = {
      phone: $("phone").value.trim(),
      password: $("password").value,
      tenant_id: $("tenantId").value.trim(),
      subtenant_id: $("subtenantId").value.trim(),
    };
    const data = await api(`${rbac()}/api/v1/auth/login`, { method: "POST", body: JSON.stringify(body) });
    const token = data?.session?.access_token || data?.credentials?.session?.access_token;
    if (!token) throw new Error("Нет access_token");
    state.token = token;
    localStorage.setItem("mf_wh_token", token);
    $("login-view").hidden = true;
    $("app-view").hidden = false;
    buildNav();
    await refresh();
  }

  $("btnLogin").onclick = () => login().catch((e) => {
    const el = $("loginOut"); el.hidden = false; el.textContent = JSON.stringify(e.body || String(e), null, 2);
  });
  $("btnLogout").onclick = () => {
    state.token = "";
    localStorage.removeItem("mf_wh_token");
    $("app-view").hidden = true;
    $("login-view").hidden = false;
  };
  $("btnEnsure").onclick = () => ensure().catch((e) => dump(e.body || String(e)));
  $("btnSeed").onclick = () => seed().catch((e) => dump(e.body || String(e)));
  $("btnRefresh").onclick = () => refresh().catch((e) => dump(e.body || String(e)));

  if (state.token) {
    $("login-view").hidden = true;
    $("app-view").hidden = false;
    buildNav();
    refresh().catch(() => {
      $("app-view").hidden = true;
      $("login-view").hidden = false;
    });
  }
})();
