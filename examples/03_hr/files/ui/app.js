(() => {
  const $ = (id) => document.getElementById(id);
  const state = { token: localStorage.getItem("mf_hr_token") || "" };
  const schemas = ["/manifest.hr_vacancy.json", "/manifest.hr_leave_request.json"];

  const from = new Date(); from.setDate(from.getDate() + 14);
  const until = new Date(); until.setDate(until.getDate() + 28);
  $("lFrom").value = from.toISOString().slice(0, 10);
  $("lUntil").value = until.toISOString().slice(0, 10);

  function showDesk(on) {
    $("login-panel").hidden = on;
    $("desk-panel").hidden = !on;
  }
  function dump(el, data) {
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
  function dataOf(row) { return row.data || row; }

  async function ensure() {
    for (const path of schemas) {
      const manifest = await fetch(path).then((r) => r.json());
      try {
        await api(`${me()}/api/v1/manifests`, { method: "POST", body: JSON.stringify(manifest) });
      } catch (e) { if (e.status !== 409) throw e; }
    }
    dump($("deskOut"), { ok: true, message: "Схемы HR готовы" });
  }

  async function seed() {
    await ensure();
    const vacancies = [
      { code: "VAC-WH-01", title: "Кладовщик", unit_code: "ops-wh", employment_type: "full_time", status: "open", opened_at: "2026-08-01", note: "Сменный график" },
      { code: "VAC-SALES-01", title: "Менеджер B2B", unit_code: "com-sales", employment_type: "full_time", status: "open", opened_at: "2026-07-15", note: "Опыт с дистрибьюторами" },
    ];
    const leaves = [
      { employee_name: "Никитин Р.А.", unit_code: "ops-wh", leave_type: "vacation", date_from: "2026-09-01", date_until: "2026-09-14", status: "pending", approver: "Павлов Е.Н.", note: "Ежегодный отпуск" },
      { employee_name: "Орлова М.С.", unit_code: "com-sales", leave_type: "vacation", date_from: "2026-08-20", date_until: "2026-08-27", status: "approved", approver: "Козлов Д.И.", note: "" },
    ];
    for (const v of vacancies) await api(`${me()}/api/data/hr_vacancy`, { method: "POST", body: JSON.stringify(v) });
    for (const l of leaves) await api(`${me()}/api/data/hr_leave_request`, { method: "POST", body: JSON.stringify(l) });
    dump($("deskOut"), { ok: true, message: "Демо HR засеяно" });
    await refresh();
  }

  function renderList(el, rows, htmlFn) {
    el.innerHTML = "";
    if (!rows.length) { el.innerHTML = `<p class="meta">Пусто</p>`; return; }
    for (const row of rows) {
      const d = dataOf(row);
      const card = document.createElement("div");
      card.className = "card";
      card.innerHTML = htmlFn(d, row);
      el.appendChild(card);
    }
  }

  async function refresh() {
    const [v, l] = await Promise.all([
      api(`${me()}/api/data/hr_vacancy`),
      api(`${me()}/api/data/hr_leave_request`),
    ]);
    renderList($("vacancies"), records(v), (d) =>
      `<strong>${d.title}</strong><span class="badge">${d.status}</span>
       <div class="meta">${d.code} · ${d.unit_code} · ${d.employment_type || ""}</div>`);
    renderList($("leaves"), records(l), (d) =>
      `<strong>${d.employee_name}</strong><span class="badge">${d.status}</span>
       <div class="meta">${d.leave_type} · ${d.date_from} → ${d.date_until} · ${d.unit_code}</div>`);
  }

  async function login() {
    const body = {
      phone: $("phone").value.trim(), password: $("password").value,
      tenant_id: $("tenantId").value.trim(), subtenant_id: $("subtenantId").value.trim(),
    };
    const data = await api(`${rbac()}/api/v1/auth/login`, { method: "POST", body: JSON.stringify(body) });
    const token = data?.session?.access_token || data?.credentials?.session?.access_token;
    if (!token) throw new Error("Нет access_token");
    state.token = token;
    localStorage.setItem("mf_hr_token", token);
    showDesk(true);
    await refresh();
  }

  async function addVac() {
    const payload = {
      code: $("vCode").value.trim(),
      title: $("vTitle").value.trim(),
      unit_code: $("vUnit").value.trim(),
      employment_type: $("vType").value,
      status: "open",
      opened_at: new Date().toISOString().slice(0, 10),
      note: "",
    };
    dump($("deskOut"), await api(`${me()}/api/data/hr_vacancy`, { method: "POST", body: JSON.stringify(payload) }));
    await refresh();
  }

  async function addLeave() {
    const payload = {
      employee_name: $("lName").value.trim(),
      unit_code: $("lUnit").value.trim(),
      leave_type: "vacation",
      date_from: $("lFrom").value,
      date_until: $("lUntil").value,
      status: "pending",
      approver: "",
      note: "",
    };
    dump($("deskOut"), await api(`${me()}/api/data/hr_leave_request`, { method: "POST", body: JSON.stringify(payload) }));
    await refresh();
  }

  $("btnLogin").onclick = () => login().catch((e) => dump($("loginOut"), e.body || String(e)));
  $("btnLogout").onclick = () => { state.token = ""; localStorage.removeItem("mf_hr_token"); showDesk(false); };
  $("btnEnsure").onclick = () => ensure().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnSeed").onclick = () => seed().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnRefresh").onclick = () => refresh().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnAddVac").onclick = () => addVac().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnAddLeave").onclick = () => addLeave().catch((e) => dump($("deskOut"), e.body || String(e)));

  if (state.token) { showDesk(true); refresh().catch(() => showDesk(false)); }
})();
