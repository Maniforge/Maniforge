package manifestengine

import (
	"fmt"
	"net/http"
	"testing"
	"time"

	"maniforge/internal/platform/apitest"
	"maniforge/internal/rbac"
)

var manifestLiveRoutes = []string{
	"GET /health",
	"GET /api/v1/catalog/field-types",
	"GET /api/v1/manifests/presets",
	"POST /api/v1/manifests/presets/:code",
	"POST /api/v1/manifests",
	"GET /api/v1/manifests",
	"GET /api/v1/manifests/:code",
	"PATCH /api/v1/manifests/:code",
	"DELETE /api/v1/manifests/:code",
	"GET /api/v1/manifests/:code/openapi.yaml",
	"GET /api/v1/manifests/:code/openapi",
	"GET /api/data/:entity",
	"POST /api/data/:entity",
	"GET /api/data/:entity/:id",
	"PATCH /api/data/:entity/:id",
	"DELETE /api/data/:entity/:id",
	"PUT /api/data/:entity/:id/*",
	"DELETE /api/data/:entity/:id/*",
}

func TestManifestFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), manifestLiveRoutes)
}

func TestManifestProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	status, out := c.JSON("GET", "/api/v1/manifests", nil)
	if status != http.StatusUnauthorized {
		t.Fatalf("GET /api/v1/manifests: %d %v", status, out)
	}
}

func TestManifestHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	rbacApp := rbac.NewApp(cfg, sqlDB)
	meApp := NewApp(cfg, sqlDB)

	password := "HttpApiCover!123"
	phone := apitest.UniquePhone("+7903")
	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	code := "http_note_" + suffix[:8]

	auth := &apitest.Client{T: t, App: rbacApp}
	reg := auth.MustStatus("POST", "/rbac/api/v1/auth/register", map[string]any{
		"phone": phone, "password": password,
		"email":                 "me_cover_" + suffix + "@example.test",
		"organization_name":     "ME Cover " + suffix,
		"platform_dpa_accepted": true,
		"consents":              []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, http.StatusCreated)
	tenant := apitest.Map(reg["tenant"])
	auth.Session.TenantID = fmt.Sprint(tenant["tenant_id"])
	auth.Session.SubtenantID = fmt.Sprint(tenant["subtenant_id"])
	auth.Tenant = true
	login := auth.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password,
		"tenant_id": auth.Session.TenantID, "subtenant_id": auth.Session.SubtenantID,
	})
	sess := apitest.Map(login["session"])
	token := fmt.Sprint(sess["access_token"])
	if token == "" || token == "<nil>" {
		t.Fatalf("нет access_token: %v", login)
	}

	c := &apitest.Client{T: t, App: meApp, Auth: true, Session: apitest.Session{Token: token}}
	hit := map[string]struct{}{}
	mark := func(key string) { hit[key] = struct{}{} }

	c.MustOK("GET", "/health", nil)
	mark("GET /health")

	c.MustOK("GET", "/api/v1/catalog/field-types", nil)
	mark("GET /api/v1/catalog/field-types")
	c.MustOK("GET", "/api/v1/manifests/presets", nil)
	mark("GET /api/v1/manifests/presets")
	c.MustOK("POST", "/api/v1/manifests/presets/product", map[string]any{})
	mark("POST /api/v1/manifests/presets/:code")

	c.MustStatus("POST", "/api/v1/manifests", map[string]any{
		"code": code, "name": "HTTP Note",
		"fields": []map[string]any{
			{"name": "title", "type": "string", "required": true, "max_length": 200},
			{"name": "body", "type": "string"},
		},
	}, http.StatusCreated)
	mark("POST /api/v1/manifests")
	c.MustOK("GET", "/api/v1/manifests", nil)
	mark("GET /api/v1/manifests")
	c.MustOK("GET", "/api/v1/manifests/"+code, nil)
	mark("GET /api/v1/manifests/:code")
	c.MustOK("PATCH", "/api/v1/manifests/"+code, map[string]any{
		"name": "HTTP Note 2",
		"fields": []map[string]any{
			{"name": "title", "type": "string", "required": true, "max_length": 200},
			{"name": "body", "type": "string"},
		},
	})
	mark("PATCH /api/v1/manifests/:code")
	c.MustOK("GET", "/api/v1/manifests/"+code+"/openapi", nil)
	mark("GET /api/v1/manifests/:code/openapi")
	st, _, raw := c.Do("GET", "/api/v1/manifests/"+code+"/openapi.yaml", nil)
	if st != http.StatusOK || len(raw) == 0 {
		t.Fatalf("openapi.yaml status %d body %s", st, string(raw))
	}
	mark("GET /api/v1/manifests/:code/openapi.yaml")

	created := c.MustStatus("POST", "/api/data/"+code, map[string]any{
		"title": "hello http cover", "body": "phase 1",
	}, http.StatusCreated)
	mark("POST /api/data/:entity")
	rec := apitest.Map(created["record"])
	id := apitest.AsInt64(rec["id"])
	if id == 0 {
		t.Fatalf("нет record.id: %v", created)
	}
	idPath := fmt.Sprintf("/api/data/%s/%d", code, id)
	c.MustOK("GET", idPath, nil)
	mark("GET /api/data/:entity/:id")
	c.MustOK("GET", "/api/data/"+code, nil)
	mark("GET /api/data/:entity")
	c.MustOK("PATCH", idPath, map[string]any{"body": "updated"})
	mark("PATCH /api/data/:entity/:id")
	c.MustOK("PUT", idPath+"/title", map[string]any{"value": "title via field"})
	mark("PUT /api/data/:entity/:id/*")
	c.MustOK("DELETE", idPath+"/body", nil)
	mark("DELETE /api/data/:entity/:id/*")
	c.MustOK("DELETE", idPath, nil)
	mark("DELETE /api/data/:entity/:id")
	c.MustOK("DELETE", "/api/v1/manifests/"+code, nil)
	mark("DELETE /api/v1/manifests/:code")

	for _, want := range manifestLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван HTTP-тестом: %s", want)
		}
	}
}
