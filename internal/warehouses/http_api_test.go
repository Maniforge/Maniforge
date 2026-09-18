package warehouses

import (
	"fmt"
	"net/http"
	"testing"

	"maniforge/internal/platform/apitest"
	"maniforge/internal/rbac"
)

var warehousesLiveRoutes = []string{
	"GET /health",
	"GET /api/v1/stock-types",
	"GET /api/v1/stocks",
	"GET /api/v1/stocks/tree",
	"GET /api/v1/delegation/grant-peers",
	"POST /api/v1/stocks",
	"GET /api/v1/stocks/:id",
	"PATCH /api/v1/stocks/:id",
	"PUT /api/v1/stocks/:id",
	"DELETE /api/v1/stocks/:id",
	"GET /api/v1/stocks/:id/children",
	"POST /api/v1/stocks/:id/external-meta",
	"GET /api/v1/stocks/:id/audit",
}

func TestWarehousesFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), warehousesLiveRoutes)
}

func TestWarehousesProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	status, out := c.JSON("GET", "/api/v1/stocks", nil)
	if status != http.StatusUnauthorized {
		t.Fatalf("GET /api/v1/stocks: %d %v", status, out)
	}
}

func TestWarehousesHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	auth := apitest.RegisterTenantAdmin(t, rbac.NewApp(cfg, sqlDB), "+7905", "WH Cover")
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	hit := map[string]struct{}{}
	mark := func(key string) { hit[key] = struct{}{} }

	c.MustOK("GET", "/health", nil)
	mark("GET /health")
	c.MustOK("GET", "/api/v1/stock-types", nil)
	mark("GET /api/v1/stock-types")
	c.MustOK("GET", "/api/v1/delegation/grant-peers", nil)
	mark("GET /api/v1/delegation/grant-peers")

	wh := c.MustStatus("POST", "/api/v1/stocks", map[string]any{"name": "Склад HTTP", "type": "warehouse"}, http.StatusCreated)
	mark("POST /api/v1/stocks")
	stock := apitest.Map(wh["stock"])
	id := apitest.AsInt64(stock["id"])
	if id <= 0 {
		t.Fatalf("нет stock.id: %v", wh)
	}
	path := fmt.Sprintf("/api/v1/stocks/%d", id)

	c.MustOK("GET", "/api/v1/stocks", nil)
	mark("GET /api/v1/stocks")
	c.MustOK("GET", "/api/v1/stocks/tree", nil)
	mark("GET /api/v1/stocks/tree")
	c.MustOK("GET", path, nil)
	mark("GET /api/v1/stocks/:id")
	c.MustOK("PATCH", path, map[string]any{"name": "Склад HTTP 2"})
	mark("PATCH /api/v1/stocks/:id")
	c.MustOK("PUT", path, map[string]any{"name": "Склад HTTP 3"})
	mark("PUT /api/v1/stocks/:id")
	c.MustOK("GET", path+"/children", nil)
	mark("GET /api/v1/stocks/:id/children")
	c.MustOK("POST", path+"/external-meta", map[string]any{"type": "ozon", "external_id": "WH-1"})
	mark("POST /api/v1/stocks/:id/external-meta")
	c.MustOK("GET", path+"/audit", nil)
	mark("GET /api/v1/stocks/:id/audit")

	zone := c.MustStatus("POST", "/api/v1/stocks", map[string]any{
		"name": "Зона А", "type": "zone", "parent_id": id,
	}, http.StatusCreated)
	zid := apitest.AsInt64(apitest.Map(zone["stock"])["id"])
	c.MustOK("DELETE", fmt.Sprintf("/api/v1/stocks/%d", zid), nil)
	mark("DELETE /api/v1/stocks/:id")

	for _, want := range warehousesLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван в сценарии: %s", want)
		}
	}
}

func TestWarehousesNegativeHTTP(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	rbacApp := rbac.NewApp(cfg, sqlDB)
	app := NewApp(cfg, sqlDB)
	admin := apitest.RegisterTenantAdmin(t, rbacApp, "+7921", "WH Neg")
	apitest.MustUnprocessable(apitest.DomainClient(t, app, admin.Session), "POST", "/api/v1/stocks", map[string]any{})

	member := apitest.RegisterUserWithoutWrite(t, rbacApp, admin)
	apitest.MustForbidden(apitest.DomainClient(t, app, member.Session), "POST", "/api/v1/stocks", map[string]any{
		"name": "Склад без write", "type": "warehouse",
	})
}
