(() => {
  const $ = (id) => document.getElementById(id);
  const state = { token: localStorage.getItem("mf_org_token") || "" };

  function showDesk(on) {
    $("login-panel").hidden = on;
    $("desk-panel").hidden = !on;
  }
  function dump(el, data) {
    el.hidden = false;
    el.textContent = typeof data === "string" ? data : JSON.stringify(data, null, 2);
  }
  function rbacBase() { return $("rbacUrl").value.replace(/\/$/, ""); }
  function meBase() { return $("manifestUrl").value.replace(/\/$/, ""); }

  async function api(url, opts = {}) {
    const headers = Object.assign({ "Content-Type": "application/json" }, opts.headers || {});
    if (state.token) headers.Authorization = `Bearer ${state.token}`;
    const res = await fetch(url, { ...opts, headers });
    const text = await res.text();
    let body;
    try { body = text ? JSON.parse(text) : null; } catch { body = { raw: text }; }
    if (!res.ok) {
      const err = new Error(body?.error || res.statusText);
      err.status = res.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  function records(body) {
    if (Array.isArray(body?.records)) return body.records;
    if (Array.isArray(body?.items)) return body.items;
    if (Array.isArray(body)) return body;
    return [];
  }

  async function ensureSchemas() {
    for (const path of ["/manifest.org_unit.json", "/manifest.org_employee.json"]) {
      const manifest = await fetch(path).then((r) => r.json());
      try {
        await api(`${meBase()}/api/v1/manifests`, { method: "POST", body: JSON.stringify(manifest) });
      } catch (e) {
        if (e.status !== 409) throw e;
      }
    }
    dump($("deskOut"), { ok: true, message: "Схемы org_unit / org_employee готовы" });
  }

  async function seedDemo() {
    await ensureSchemas();
    const units = [
      { code: "co", name: "ООО «Север Торг»", unit_type: "company", parent_code: "", status: "active", head_title: "Генеральный директор" },
      { code: "commercial", name: "Коммерция", unit_type: "division", parent_code: "co", status: "active", head_title: "Коммерческий директор" },
      { code: "operations", name: "Операции", unit_type: "division", parent_code: "co", status: "active", head_title: "Операционный директор" },
      { code: "finance", name: "Финансы", unit_type: "division", parent_code: "co", status: "active", head_title: "Финдиректор" },
      { code: "ops-wh", name: "Склад", unit_type: "department", parent_code: "operations", status: "active", head_title: "Начальник склада" },
      { code: "ops-log", name: "Логистика", unit_type: "department", parent_code: "operations", status: "active", head_title: "Руководитель логистики" },
      { code: "com-sales", name: "Продажи B2B", unit_type: "department", parent_code: "commercial", status: "active", head_title: "РОП" },
    ];
    const people = [
      { full_name: "Смирнова А.В.", unit_code: "co", position: "Генеральный директор", email: "ceo@sever-torg.example", phone: "+79001110001", status: "active", hired_at: "2019-03-01" },
      { full_name: "Козлов Д.И.", unit_code: "commercial", position: "Коммерческий директор", email: "cco@sever-torg.example", phone: "+79001110002", status: "active", hired_at: "2020-06-15" },
      { full_name: "Орлова М.С.", unit_code: "com-sales", position: "Менеджер B2B", email: "sales1@sever-torg.example", phone: "+79001110003", status: "active", hired_at: "2022-01-10" },
      { full_name: "Павлов Е.Н.", unit_code: "operations", position: "Операционный директор", email: "coo@sever-torg.example", phone: "+79001110004", status: "active", hired_at: "2018-11-20" },
      { full_name: "Никитин Р.А.", unit_code: "ops-wh", position: "Кладовщик", email: "wh1@sever-torg.example", phone: "+79001110005", status: "active", hired_at: "2023-04-01" },
      { full_name: "Белова И.К.", unit_code: "finance", position: "Главный бухгалтер", email: "cfo@sever-torg.example", phone: "+79001110006", status: "active", hired_at: "2017-09-01" },
    ];
    for (const u of units) {
      await api(`${meBase()}/api/data/org_unit`, { method: "POST", body: JSON.stringify(u) });
    }
    for (const p of people) {
      await api(`${meBase()}/api/data/org_employee`, { method: "POST", body: JSON.stringify(p) });
    }
    dump($("deskOut"), { ok: true, message: "Демо-компания засеяна" });
    await refresh();
  }

  function buildTree(units) {
    const byParent = new Map();
    for (const u of units) {
      const p = u.parent_code || "";
      if (!byParent.has(p)) byParent.set(p, []);
      byParent.get(p).push(u);
    }
    const out = [];
    const walk = (parent, depth) => {
      for (const u of byParent.get(parent) || []) {
        out.push({ ...u, depth });
        walk(u.code, depth + 1);
      }
    };
    walk("", 0);
    // orphans (parent missing)
    for (const u of units) {
      if (!out.find((x) => x.code === u.code)) out.push({ ...u, depth: 0 });
    }
    return out;
  }

  async function refresh() {
    const unitsBody = await api(`${meBase()}/api/data/org_unit`);
    const empBody = await api(`${meBase()}/api/data/org_employee`);
    const units = records(unitsBody).map((r) => r.data || r);
    const people = records(empBody).map((r) => r.data || r);

    const tree = $("tree");
    tree.innerHTML = "";
    for (const u of buildTree(units)) {
      const el = document.createElement("div");
      el.className = "node";
      el.style.setProperty("--depth", String(u.depth || 0));
      el.innerHTML = `<strong>${u.name || u.code}</strong> <span class="meta">${u.unit_type} · ${u.code}${u.parent_code ? " ← " + u.parent_code : ""}</span>`;
      el.onclick = () => { $("empUnit").value = u.code; };
      tree.appendChild(el);
    }
    if (!units.length) tree.innerHTML = `<p class="meta">Пусто — нажмите «Засеять демо-компанию».</p>`;

    const list = $("people");
    list.innerHTML = "";
    for (const p of people) {
      const el = document.createElement("div");
      el.className = "card";
      el.innerHTML = `<strong>${p.full_name}</strong><div class="meta">${p.position} · ${p.unit_code} · ${p.status}</div>`;
      list.appendChild(el);
    }
    if (!people.length) list.innerHTML = `<p class="meta">Сотрудников пока нет.</p>`;
  }

  async function login() {
    const body = {
      phone: $("phone").value.trim(),
      password: $("password").value,
      tenant_id: $("tenantId").value.trim(),
      subtenant_id: $("subtenantId").value.trim(),
    };
    const data = await api(`${rbacBase()}/api/v1/auth/login`, { method: "POST", body: JSON.stringify(body) });
    const token = data?.session?.access_token || data?.credentials?.session?.access_token;
    if (!token) throw new Error("Нет access_token");
    state.token = token;
    localStorage.setItem("mf_org_token", token);
    showDesk(true);
    await refresh();
  }

  async function addEmp() {
    const payload = {
      full_name: $("empName").value.trim(),
      unit_code: $("empUnit").value.trim(),
      position: $("empPos").value.trim(),
      phone: $("empPhone").value.trim(),
      status: "active",
      hired_at: new Date().toISOString().slice(0, 10),
    };
    const created = await api(`${meBase()}/api/data/org_employee`, { method: "POST", body: JSON.stringify(payload) });
    dump($("deskOut"), created);
    await refresh();
  }

  $("btnLogin").onclick = () => login().catch((e) => dump($("loginOut"), e.body || String(e)));
  $("btnLogout").onclick = () => { state.token = ""; localStorage.removeItem("mf_org_token"); showDesk(false); };
  $("btnEnsure").onclick = () => ensureSchemas().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnSeed").onclick = () => seedDemo().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnRefresh").onclick = () => refresh().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnAddEmp").onclick = () => addEmp().catch((e) => dump($("deskOut"), e.body || String(e)));

  if (state.token) {
    showDesk(true);
    refresh().catch(() => showDesk(false));
  }
})();
