(() => {
  const $ = (id) => document.getElementById(id);
  const state = {
    token: localStorage.getItem("mf_access_token") || "",
    loginName: localStorage.getItem("mf_login") || "agency-admin",
  };

  const until = new Date();
  until.setDate(until.getDate() + 7);
  $("validUntil").value = until.toISOString().slice(0, 10);

  function showDesk(on) {
    $("login-panel").hidden = on;
    $("desk-panel").hidden = !on;
  }

  function dump(el, data) {
    el.hidden = false;
    el.textContent = typeof data === "string" ? data : JSON.stringify(data, null, 2);
  }

  async function api(url, opts = {}) {
    const headers = Object.assign({ "Content-Type": "application/json" }, opts.headers || {});
    if (state.token) headers.Authorization = `Bearer ${state.token}`;
    const res = await fetch(url, { ...opts, headers });
    const text = await res.text();
    let body;
    try {
      body = text ? JSON.parse(text) : null;
    } catch {
      body = { raw: text };
    }
    if (!res.ok) {
      const err = new Error(body?.error || body?.message || res.statusText);
      err.status = res.status;
      err.body = body;
      throw err;
    }
    return body;
  }

  function manifestBase() {
    return $("manifestUrl").value.replace(/\/$/, "");
  }
  function rbacBase() {
    return $("rbacUrl").value.replace(/\/$/, "");
  }

  async function login() {
    const body = {
      phone: $("phone").value.trim(),
      password: $("password").value,
      tenant_id: $("tenantId").value.trim(),
      subtenant_id: $("subtenantId").value.trim(),
    };
    const data = await api(`${rbacBase()}/api/v1/auth/login`, {
      method: "POST",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
    });
    const token = data?.session?.access_token || data?.access_token;
    if (!token) throw new Error("Нет access_token в ответе login");
    state.token = token;
    state.loginName = body.phone;
    localStorage.setItem("mf_access_token", token);
    localStorage.setItem("mf_login", body.phone);
    dump($("loginOut"), { ok: true, tenant: data?.session?.tenant_id || body.tenant_id });
    showDesk(true);
    await refreshList();
  }

  async function ensureManifest() {
    const manifest = await fetch("../manifest.access_pass.json").then((r) => r.json());
    try {
      const created = await api(`${manifestBase()}/api/v1/manifests`, {
        method: "POST",
        body: JSON.stringify(manifest),
      });
      dump($("deskOut"), created);
    } catch (e) {
      const existing = await api(`${manifestBase()}/api/v1/manifests/access_pass`);
      dump($("deskOut"), { note: "Схема уже есть или POST отклонён", existing, error: e.body || String(e) });
    }
  }

  function recordId(row) {
    return row?.id || row?.record_id || row?.record?.id || row?.data?.id || null;
  }

  async function issuePass() {
    const payload = {
      guest_name: $("guestName").value.trim(),
      guest_phone: $("guestPhone").value.trim(),
      zone: $("zone").value,
      valid_from: new Date().toISOString().slice(0, 10),
      valid_until: $("validUntil").value,
      status: "active",
      issued_by: state.loginName,
      note: $("note").value.trim(),
    };
    const created = await api(`${manifestBase()}/api/data/access_pass`, {
      method: "POST",
      body: JSON.stringify(payload),
    });
    dump($("deskOut"), created);
    await refreshList();
  }

  async function revoke(id) {
    try {
      await api(`${manifestBase()}/api/data/access_pass/${id}/fields/status`, {
        method: "PATCH",
        body: JSON.stringify({ value: "revoked" }),
      });
    } catch {
      await api(`${manifestBase()}/api/data/access_pass/${id}`, {
        method: "PATCH",
        body: JSON.stringify({ status: "revoked" }),
      });
    }
    await refreshList();
  }

  function normalizeList(body) {
    if (Array.isArray(body)) return body;
    if (Array.isArray(body?.items)) return body.items;
    if (Array.isArray(body?.data)) return body.data;
    if (Array.isArray(body?.records)) return body.records;
    return [];
  }

  async function refreshList() {
    const body = await api(`${manifestBase()}/api/data/access_pass`);
    const rows = normalizeList(body);
    const root = $("list");
    root.innerHTML = "";
    if (!rows.length) {
      root.innerHTML = `<p class="muted">Пока пусто — выдайте первый пропуск.</p>`;
      return;
    }
    for (const row of rows) {
      const data = row.data || row.payload || row;
      const id = recordId(row) || recordId(data);
      const status = data.status || "—";
      const el = document.createElement("article");
      el.className = "card";
      el.innerHTML = `
        <div>
          <strong>${data.guest_name || "—"}</strong>
          <span class="badge ${status}">${status}</span>
        </div>
        <div class="meta">${data.zone || "—"} · до ${data.valid_until || "—"} · ${data.guest_phone || ""}</div>
        <div class="meta">${data.note || ""}</div>
      `;
      if (id && status === "active") {
        const btn = document.createElement("button");
        btn.className = "ghost";
        btn.textContent = "Отозвать";
        btn.style.marginTop = "0.6rem";
        btn.onclick = () => revoke(id).catch((e) => dump($("deskOut"), e.body || String(e)));
        el.appendChild(btn);
      }
      root.appendChild(el);
    }
  }

  $("btnLogin").onclick = () =>
    login().catch((e) => dump($("loginOut"), e.body || { error: String(e) }));
  $("btnLogout").onclick = () => {
    state.token = "";
    localStorage.removeItem("mf_access_token");
    showDesk(false);
  };
  $("btnEnsureManifest").onclick = () =>
    ensureManifest().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnIssue").onclick = () =>
    issuePass().catch((e) => dump($("deskOut"), e.body || String(e)));
  $("btnRefresh").onclick = () =>
    refreshList().catch((e) => dump($("deskOut"), e.body || String(e)));

  if (state.token) {
    showDesk(true);
    refreshList().catch(() => showDesk(false));
  }
})();
