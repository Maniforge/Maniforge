// Package refine — генерация Refine UI scaffold из manifest / OpenAPI.
//
// Файл: generate.go
// Назначение: статические TS/React файлы для копирования в Refine-проект.
// См. также: templates/refine-manifest/, docs/MANIFORGE_MANIFEST_ENGINE_PLAN.md
package refine

import (
	"fmt"
	"strings"

	"maniforge/internal/manifestengine/model"
)

// Scaffold — набор файлов path → content для Refine-проекта.
type Scaffold struct {
	EntityCode string
	Files      map[string]string
}

// GenerateFromManifest строит scaffold Refine v4 из описания manifest.
func GenerateFromManifest(m *model.Manifest, apiBase string) (*Scaffold, error) {
	if m == nil {
		return nil, fmt.Errorf("manifest is nil")
	}
	code := strings.TrimSpace(m.Code)
	if code == "" {
		return nil, fmt.Errorf("manifest code обязателен")
	}
	if apiBase == "" {
		apiBase = "http://127.0.0.1:8095/api/data"
	}
	apiBase = strings.TrimRight(apiBase, "/")

	entity := sanitizeIdent(code)
	title := strings.TrimSpace(m.Name)
	if title == "" {
		title = code
	}

	files := map[string]string{
		"package.json":                        packageJSON(title),
		"vite.config.ts":                      viteConfig(),
		"index.html":                          indexHTML(title),
		"src/index.tsx":                       indexTSX(),
		"src/App.tsx":                         appTSX(entity, title),
		"src/providers/manifestDataProvider.ts": dataProviderTS(apiBase, code),
		"src/resources/" + entity + ".ts":     resourceTS(entity, code, title, m.Fields),
		"README.txt":                        readmeTXT(code, title),
	}

	return &Scaffold{EntityCode: code, Files: files}, nil
}

func sanitizeIdent(code string) string {
	out := strings.Map(func(r rune) rune {
		if (r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') || r == '_' {
			return r
		}
		return '_'
	}, code)
	if out == "" {
		return "entity"
	}
	if out[0] >= '0' && out[0] <= '9' {
		return "e_" + out
	}
	return out
}

func packageJSON(title string) string {
	return fmt.Sprintf(`{
  "name": "maniforge-refine-%s",
  "private": true,
  "version": "0.1.0",
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview"
  },
  "dependencies": {
    "@refinedev/core": "^4.57.0",
    "@refinedev/react-router-v6": "^4.6.0",
    "react": "^18.3.1",
    "react-dom": "^18.3.1",
    "react-router-dom": "^6.28.0"
  },
  "devDependencies": {
    "@types/react": "^18.3.12",
    "@types/react-dom": "^18.3.1",
    "@vitejs/plugin-react": "^4.3.4",
    "typescript": "^5.6.3",
    "vite": "^5.4.11"
  }
}
`, strings.ToLower(strings.ReplaceAll(title, " ", "-")))
}

func viteConfig() string {
	return `import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  server: { port: 5173 },
});
`
}

func indexHTML(title string) string {
	return fmt.Sprintf(`<!DOCTYPE html>
<html lang="ru">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>%s — Maniforge Refine</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/index.tsx"></script>
  </body>
</html>
`, title)
}

func indexTSX() string {
	return `import React from "react";
import { createRoot } from "react-dom/client";
import App from "./App";

createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
`
}

func appTSX(entity, title string) string {
	return fmt.Sprintf(`import { Refine } from "@refinedev/core";
import routerProvider from "@refinedev/react-router-v6";
import { BrowserRouter, Routes, Route, Outlet } from "react-router-dom";
import { manifestDataProvider } from "./providers/manifestDataProvider";
import { %sResource } from "./resources/%s";

/** Сгенерировано Maniforge manifest-refine-gen. Токен: localStorage maniforge_admin_access_token */
export default function App() {
  return (
    <BrowserRouter>
      <Refine
        routerProvider={routerProvider}
        dataProvider={manifestDataProvider}
        resources={[{%sResource}]}
        options={{ syncWithLocation: true }}
      >
        <Routes>
          <Route element={<Outlet />}>
            <Route index element={<div>%s — выберите resource в меню Refine</div>} />
          </Route>
        </Routes>
      </Refine>
    </BrowserRouter>
  );
}
`, entity, entity, entity, title)
}

func dataProviderTS(apiBase, code string) string {
	return fmt.Sprintf(`import { DataProvider } from "@refinedev/core";

const API_BASE = %q;
const ENTITY = %q;

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
`, apiBase, code)
}

func resourceTS(entity, code, title string, fields []model.FieldDef) string {
	var b strings.Builder
	b.WriteString(fmt.Sprintf(`import type { ResourceProps } from "@refinedev/core";

export const %sResource: ResourceProps = {
  name: %q,
  list: "/%s",
  create: "/%s/create",
  edit: "/%s/edit/:id",
  show: "/%s/show/:id",
  meta: { label: %q, manifestCode: %q },
};

export const %sFields = [
`, entity, code, code, code, code, code, title, code, entity))
	for _, f := range fields {
		b.WriteString(fmt.Sprintf("  { name: %q, type: %q, required: %t },\n", f.Name, f.Type, f.Required))
	}
	b.WriteString("] as const;\n")
	return b.String()
}

func readmeTXT(code, title string) string {
	return fmt.Sprintf(`Maniforge Refine scaffold — %s (%s)

1. npm install
2. Убедитесь, что access_token в localStorage (ключ maniforge_admin_access_token)
3. npm run dev
4. Manifest Engine: %s

Сгенерировано manifest-refine-gen из OpenAPI/manifest.
`, title, code, code)
}
