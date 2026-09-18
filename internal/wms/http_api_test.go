package wms

import (
	"fmt"
	"net/http"
	"testing"

	"maniforge/internal/platform/apitest"
	"maniforge/internal/products"
	"maniforge/internal/rbac"
	"maniforge/internal/warehouses"
)

var wmsLiveRoutes = []string{
	"GET /health",
	"GET /api/v1/packs",
	"POST /api/v1/packs",
	"GET /api/v1/packs/:id",
	"DELETE /api/v1/packs/:id",
	"POST /api/v1/packs/:id/seal",
	"POST /api/v1/packs/:id/disaggregate",
	"POST /api/v1/packs/:id/markings",
	"POST /api/v1/packs/:id/children",
	"GET /api/v1/markings",
	"GET /api/v1/markings/:id/trace",
	"GET /api/v1/markings/:id",
	"POST /api/v1/markings",
	"POST /api/v1/markings/bulk",
	"GET /api/v1/scan",
	"POST /api/v1/scan",
	"POST /api/v1/movements/scan",
}

func TestWmsFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), wmsLiveRoutes)
}

func TestWmsProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	status, out := c.JSON("GET", "/api/v1/packs", nil)
	if status != http.StatusUnauthorized {
		t.Fatalf("GET /api/v1/packs: %d %v", status, out)
	}
}

func TestWmsHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	auth := apitest.RegisterTenantAdmin(t, rbac.NewApp(cfg, sqlDB), "+7908", "WMS Cover")
	wh := &apitest.Client{T: t, App: warehouses.NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	pr := &apitest.Client{T: t, App: products.NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	hit := map[string]struct{}{}
	mark := func(key string) { hit[key] = struct{}{} }

	c.MustOK("GET", "/health", nil)
	mark("GET /health")

	stock := apitest.Map(wh.MustStatus("POST", "/api/v1/stocks", map[string]any{"name": "WMS склад", "type": "warehouse"}, http.StatusCreated)["stock"])
	stockID := apitest.AsInt64(stock["id"])
	prod := apitest.Map(pr.MustStatus("POST", "/api/v1/products", map[string]any{"name": "WMS SKU"}, http.StatusCreated)["product"])
	productID := apitest.AsInt64(prod["id"])

	pack := c.MustStatus("POST", "/api/v1/packs", map[string]any{
		"unit_type": "consumer", "product_id": productID, "stock_id": stockID,
	}, http.StatusCreated)
	mark("POST /api/v1/packs")
	packID := apitest.AsInt64(apitest.Map(pack["pack"])["id"])
	c.MustOK("GET", "/api/v1/packs", nil)
	mark("GET /api/v1/packs")
	c.MustOK("GET", fmt.Sprintf("/api/v1/packs/%d", packID), nil)
	mark("GET /api/v1/packs/:id")

	mk := c.MustStatus("POST", "/api/v1/markings", map[string]any{
		"product_id": productID, "code": "KIZ-HTTP-1",
	}, http.StatusCreated)
	mark("POST /api/v1/markings")
	mid := apitest.AsInt64(apitest.Map(mk["marking"])["id"])
	c.MustStatus("POST", "/api/v1/markings/bulk", map[string]any{
		"product_id": productID, "codes": []string{"KIZ-HTTP-2"},
	}, http.StatusCreated)
	mark("POST /api/v1/markings/bulk")
	c.MustOK("GET", "/api/v1/markings", nil)
	mark("GET /api/v1/markings")
	c.MustOK("GET", fmt.Sprintf("/api/v1/markings/%d", mid), nil)
	mark("GET /api/v1/markings/:id")
	c.MustOK("GET", fmt.Sprintf("/api/v1/markings/%d/trace", mid), nil)
	mark("GET /api/v1/markings/:id/trace")

	c.MustOK("POST", fmt.Sprintf("/api/v1/packs/%d/markings", packID), map[string]any{"marking_code_id": mid})
	mark("POST /api/v1/packs/:id/markings")
	c.MustOK("POST", fmt.Sprintf("/api/v1/packs/%d/seal", packID), nil)
	mark("POST /api/v1/packs/:id/seal")

	pallet := c.MustStatus("POST", "/api/v1/packs", map[string]any{"unit_type": "pallet"}, http.StatusCreated)
	palletID := apitest.AsInt64(apitest.Map(pallet["pack"])["id"])
	c.MustOK("POST", fmt.Sprintf("/api/v1/packs/%d/children", palletID), map[string]any{"child_pack_unit_id": packID})
	mark("POST /api/v1/packs/:id/children")

	c.MustOK("GET", "/api/v1/scan?code=KIZ-HTTP-1", nil)
	mark("GET /api/v1/scan")
	c.MustOK("POST", "/api/v1/scan", map[string]any{"code": "KIZ-HTTP-1"})
	mark("POST /api/v1/scan")
	c.MustStatus("POST", "/api/v1/movements/scan", map[string]any{
		"movement_type": "receipt", "stock_id": stockID, "pack_unit_id": packID, "product_id": productID,
	}, http.StatusCreated)
	mark("POST /api/v1/movements/scan")

	c.MustOK("POST", fmt.Sprintf("/api/v1/packs/%d/disaggregate", packID), nil)
	mark("POST /api/v1/packs/:id/disaggregate")

	draft := c.MustStatus("POST", "/api/v1/packs", map[string]any{"unit_type": "group"}, http.StatusCreated)
	did := apitest.AsInt64(apitest.Map(draft["pack"])["id"])
	c.MustOK("DELETE", fmt.Sprintf("/api/v1/packs/%d", did), nil)
	mark("DELETE /api/v1/packs/:id")

	for _, want := range wmsLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван в сценарии: %s", want)
		}
	}
}

func TestWmsNegativeHTTP(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	rbacApp := rbac.NewApp(cfg, sqlDB)
	app := NewApp(cfg, sqlDB)
	admin := apitest.RegisterTenantAdmin(t, rbacApp, "+7924", "WMS Neg")
	apitest.MustUnprocessable(apitest.DomainClient(t, app, admin.Session), "POST", "/api/v1/packs", map[string]any{})

	member := apitest.RegisterUserWithoutWrite(t, rbacApp, admin)
	apitest.MustForbidden(apitest.DomainClient(t, app, member.Session), "POST", "/api/v1/packs", map[string]any{
		"unit_type": "consumer",
	})
}
