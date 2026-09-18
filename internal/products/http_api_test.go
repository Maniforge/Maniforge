package products

import (
	"fmt"
	"net/http"
	"testing"

	"maniforge/internal/platform/apitest"
	"maniforge/internal/rbac"
)

var productsLiveRoutes = []string{
	"GET /health",
	"GET /api/v1/delegation/grant-peers",
	"GET /api/v1/products",
	"POST /api/v1/products",
	"GET /api/v1/products/by-barcode/:code",
	"GET /api/v1/products/:id",
	"PATCH /api/v1/products/:id",
	"PUT /api/v1/products/:id",
	"DELETE /api/v1/products/:id",
	"POST /api/v1/products/:id/external-meta",
}

func TestProductsFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), productsLiveRoutes)
}

func TestProductsProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	status, out := c.JSON("GET", "/api/v1/products", nil)
	if status != http.StatusUnauthorized {
		t.Fatalf("GET /api/v1/products: %d %v", status, out)
	}
}

func TestProductsHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	auth := apitest.RegisterTenantAdmin(t, rbac.NewApp(cfg, sqlDB), "+7906", "PR Cover")
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	hit := map[string]struct{}{}
	mark := func(key string) { hit[key] = struct{}{} }

	c.MustOK("GET", "/health", nil)
	mark("GET /health")
	c.MustOK("GET", "/api/v1/delegation/grant-peers", nil)
	mark("GET /api/v1/delegation/grant-peers")

	ean := MakeEAN13("46012345678")
	created := c.MustStatus("POST", "/api/v1/products", map[string]any{
		"name": "Подушка HTTP", "unit": "pcs", "barcode_ean13": ean,
	}, http.StatusCreated)
	mark("POST /api/v1/products")
	p := apitest.Map(created["product"])
	id := apitest.AsInt64(p["id"])
	path := fmt.Sprintf("/api/v1/products/%d", id)

	c.MustOK("GET", "/api/v1/products", nil)
	mark("GET /api/v1/products")
	c.MustOK("GET", path, nil)
	mark("GET /api/v1/products/:id")
	c.MustOK("GET", "/api/v1/products/by-barcode/"+ean, nil)
	mark("GET /api/v1/products/by-barcode/:code")
	c.MustOK("PATCH", path, map[string]any{"name": "Подушка HTTP 2"})
	mark("PATCH /api/v1/products/:id")
	c.MustOK("PUT", path, map[string]any{"name": "Подушка HTTP 3"})
	mark("PUT /api/v1/products/:id")
	c.MustOK("POST", path+"/external-meta", map[string]any{"type": "1c", "external_id": "SKU-1"})
	mark("POST /api/v1/products/:id/external-meta")

	other := c.MustStatus("POST", "/api/v1/products", map[string]any{"name": "Архив SKU"}, http.StatusCreated)
	oid := apitest.AsInt64(apitest.Map(other["product"])["id"])
	c.MustOK("DELETE", fmt.Sprintf("/api/v1/products/%d", oid), nil)
	mark("DELETE /api/v1/products/:id")

	for _, want := range productsLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван в сценарии: %s", want)
		}
	}
}

func TestProductsNegativeHTTP(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	rbacApp := rbac.NewApp(cfg, sqlDB)
	app := NewApp(cfg, sqlDB)
	admin := apitest.RegisterTenantAdmin(t, rbacApp, "+7922", "PR Neg")
	apitest.MustUnprocessable(apitest.DomainClient(t, app, admin.Session), "POST", "/api/v1/products", map[string]any{})

	member := apitest.RegisterUserWithoutWrite(t, rbacApp, admin)
	apitest.MustForbidden(apitest.DomainClient(t, app, member.Session), "POST", "/api/v1/products", map[string]any{
		"name": "SKU без write",
	})
}
