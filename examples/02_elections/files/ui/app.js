(() => {
  const $ = (id) => document.getElementById(id);
  const state = { token: localStorage.getItem("mf_el_token") || "" };
  const schemas = [
    "/manifest.election_event.json",
    "/manifest.polling_station.json",
    "/manifest.candidacy.json",
  ];

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
    dump($("deskOut"), { ok: true, message: "Схемы выборов готовы" });
  }

  async function seed() {
    await ensure();
    const event = { code: "mun-2026", title: "Муниципальные выборы 2026", level: "municipal", vote_date: "2026-09-13", status: "preparation", region: "Примерский край" };
    const stations = [
      { code: "UIK-0101", election_code: "mun-2026", title: "УИК №101", address: "ул. Центральная, 1", capacity: 1200, status: "active" },
      { code: "UIK-0102", election_code: "mun-2026", title: "УИК №102", address: "пр. Мира, 15", capacity: 900, status: "active" },
      { code: "UIK-0103", election_code: "mun-2026", title: "УИК №103", address: "ул. Школьная, 8", capacity: 750, status: "active" },
    ];
    const cands = [
      { election_code: "mun-2026", full_name: "Алексеев П.С.", party: "Самовыдвижение", district: "округ-1", status: "registered", reg_number: "K-001" },
      { election_code: "mun-2026", full_name: "Морозова Е.В.", party: "Партия развития", district: "округ-1", status: "registered", reg_number: "K-002" },
      { election_code: "mun-2026", full_name: "Гусев И.Н.", party: "Городской союз", district: "округ-1", status: "registered", reg_number: "K-003" },
    ];
    await api(`${me()}/api/data/election_event`, { method: "POST", body: JSON.stringify(event) });
    for (const s of stations) await api(`${me()}/api/data/polling_station`, { method: "POST", body: JSON.stringify(s) });
    for (const c of cands) await api(`${me()}/api/data/candidacy`, { method: "POST", body: JSON.stringify(c) });
    dump($("deskOut"), { ok: true, message: "Демо-кампания засеяна" });
    await refresh();
  }

  function renderList(el, rows, htmlFn) {
    el.innerHTML = "";
    if (!rows.length) { el.innerHTML = `<p class="meta">Пусто</p>`; return; }
    for (const row of rows) {
      const d = dataOf(row);
      const card = document.createElement("div");
      card.className = "card";
      card.innerHTML = htmlFn(d);
      el.appendChild(card);
    }
  }

  async function refresh() {
    const [ev, st, ca] = await Promise.all([
      api(`${me()}/api/data/election_event`),
      api(`${me()}/api/data/polling_station`),
      api(`${me()}/api/data/candidacy`),
    ]);
    renderList($("events"), records(ev), (d) =>
      `<strong>${d.title || d.code}</strong><span class="badge">${d.status}</span>
       <div class="meta">${d.level} · ${d.vote_date} · ${d.region || ""} · ${d.code}</div>`);
    renderList($("stations"), records(st), (d) =>
      `<strong>${d.title || d.code}</strong><span class="badge">${d.status}</span>
       <div class="meta">${d.code} · ${d.address} · вместимость ${d.capacity}</div>`);
    renderList($("cands"), records(ca), (d) =>
      `<strong>${d.full_name}</strong><span class="badge">${d.status}</span>
       <div class="meta">${d.party || "—"} · ${d.district} · ${d.reg_number || ""}</div>`);
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
    localStorage.setItem("mf_el_token", token);
    showDesk(true);
    await refresh();
  }

  async function addCand() {
    const payload = {
      election_code: $("cElection").value.trim(),
      full_name: $("cName").value.trim(),
      party: $("cParty").value.trim(),
      district: $("cDistrict").value.trim(),
      status: "registered",
      reg_number: "K-" + String(Date.now()).slice(-4),
    };
    const created = await api(`${me()}/api/data/candidacy`, { method: "POST", body: JSON.stringify(payload) });
    dump($("deskOut"), created);
    await refresh();
  }

  $("btnLogin").onclick = () => login().catch((e) => dump($("loginOut"), e.body || String(e)));
  $("btnLogout").onclick = () => { state.token = ""; localStorage.removeItem("mf_el_token"); showDesk(false); };
  $("btnEnsure").onclick = () => ensure().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnSeed").onclick = () => seed().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnRefresh").onclick = () => refresh().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnAddCand").onclick = () => addCand().catch((e) => dump($("deskOut"), e.body || String(e)));

  if (state.token) { showDesk(true); refresh().catch(() => showDesk(false)); }
})();
