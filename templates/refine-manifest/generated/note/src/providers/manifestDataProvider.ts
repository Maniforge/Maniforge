import { DataProvider } from "@refinedev/core";

const API_BASE = "http://127.0.0.1:8095/api/data";
const ENTITY = "note";

function token(): string {
  return localStorage.getItem("maniforge_admin_access_token") || "";
}

async function request(method: string, path: string, body?: unknown) {
  const res = await fetch(API_BASE + path, {
    method,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: "Bearer " + token(),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const json = await res.json().catch(() => ({ ok: false }));
  if (!res.ok || !json.ok) {
    throw new Error(json.error || "HTTP " + res.status);
  }
  return json;
}

/** Data provider для Manifest Engine /api/data/{entity} */
export const manifestDataProvider: DataProvider = {
  getList: async ({ pagination }) => {
    const limit = pagination?.pageSize ?? 50;
    const page = pagination?.current ?? 1;
    const offset = (page - 1) * limit;
    const json = await request("GET", "/" + ENTITY + "?limit=" + limit + "&offset=" + offset);
    const records = (json.records || []).map((r: { id: number; data: Record<string, unknown> }) => ({
      id: r.id,
      ...r.data,
    }));
    return { data: records, total: json.meta?.total ?? records.length };
  },
  getOne: async ({ id }) => {
    const json = await request("GET", "/" + ENTITY + "/" + id);
    const rec = json.record;
    return { data: { id: rec.id, ...rec.data } };
  },
  create: async ({ variables }) => {
    const json = await request("POST", "/" + ENTITY, variables);
    const rec = json.record;
    return { data: { id: rec.id, ...rec.data } };
  },
  update: async ({ id, variables }) => {
    const json = await request("PATCH", "/" + ENTITY + "/" + id, variables);
    const rec = json.record;
    return { data: { id: rec.id, ...rec.data } };
  },
  deleteOne: async ({ id }) => {
    await request("DELETE", "/" + ENTITY + "/" + id);
    return { data: { id } };
  },
  getApiUrl: () => API_BASE,
};
