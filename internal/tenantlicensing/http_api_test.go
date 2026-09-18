package tenantlicensing

import (
	"fmt"
	"net/http"
	"testing"
	"time"

	"maniforge/internal/platform/apitest"
)

var tlLiveRoutes = []string{
	"GET /health",
	"GET /api/v1/tenants",
	"POST /api/v1/tenants",
	"PATCH /api/v1/tenants/:tenantCode",
	"GET /api/v1/tenants/:tenantCode/subtenants",
	"POST /api/v1/tenants/:tenantCode/subtenants",
	"PATCH /api/v1/tenants/:tenantCode/subtenants/:subtenantCode",
	"GET /api/v1/plans",
	"POST /api/v1/plans",
	"PATCH /api/v1/plans/:code",
	"GET /api/v1/licenses",
	"POST /api/v1/licenses/assign",
	"PATCH /api/v1/licenses/:id",
	"POST /api/v1/licenses/revoke",
	"GET /api/v1/tenants/:tenantCode/entitlements",
	"GET /api/v1/tenants/:tenantCode/quota",
	"GET /api/v1/tenants/:tenantCode/managed-tenants",
	"POST /api/v1/tenants/:tenantCode/managed-tenants",
	"DELETE /api/v1/tenants/:tenantCode/managed-tenants/:managedCode",
	"POST /api/v1/tenants/:tenantCode/managed-tenants/:managedCode/revoke",
	"GET /api/v1/ops/summary",
	"GET /api/v1/audit",
	"GET /api/v1/events",
	"GET /internal/v1/tenants/:tenantCode/projects/:projectCode/access-state",
	"GET /internal/v1/tenants/:tenantCode/subtenants/:subtenantCode/access-state",
	"GET /internal/v1/events/pending",
	"POST /internal/v1/events/:id/ack",
}

func TestTLFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), tlLiveRoutes)
}

func TestTLProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	status, out := c.JSON("GET", "/tenant-licensing/api/v1/tenants", nil)
	if status != http.StatusUnauthorized {
		t.Fatalf("GET tenants без токена: %d %v", status, out)
	}
}

func TestTLHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	app := NewApp(cfg, sqlDB)
	admin := &apitest.Client{T: t, App: app, Auth: true, Session: apitest.Session{Token: apitest.TLAdminToken}}
	internal := &apitest.Client{T: t, App: app, Auth: true, Session: apitest.Session{Token: apitest.InternalToken}}
	hit := map[string]struct{}{}
	mark := func(key string) { hit[key] = struct{}{} }

	admin.MustOK("GET", "/tenant-licensing/health", nil)
	mark("GET /health")

	admin.MustOK("GET", "/tenant-licensing/api/v1/tenants", nil)
	mark("GET /api/v1/tenants")
	admin.MustOK("GET", "/tenant-licensing/api/v1/plans", nil)
	mark("GET /api/v1/plans")

	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	tenantCode := "httptl" + suffix[:8]
	admin.MustStatus("POST", "/tenant-licensing/api/v1/tenants", map[string]any{
		"code": tenantCode, "name": "HTTP TL " + suffix,
	}, http.StatusCreated)
	mark("POST /api/v1/tenants")

	admin.MustOK("PATCH", "/tenant-licensing/api/v1/tenants/"+tenantCode, map[string]any{
		"name": "HTTP TL renamed", "status": "active",
	})
	mark("PATCH /api/v1/tenants/:tenantCode")

	subCode := "httpws"
	admin.MustStatus("POST", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/subtenants", map[string]any{
		"code": subCode, "name": "HTTP workspace",
	}, http.StatusCreated)
	mark("POST /api/v1/tenants/:tenantCode/subtenants")
	admin.MustOK("GET", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/subtenants", nil)
	mark("GET /api/v1/tenants/:tenantCode/subtenants")
	admin.MustOK("PATCH", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/subtenants/"+subCode, map[string]any{
		"name": "HTTP workspace 2", "status": "active",
	})
	mark("PATCH /api/v1/tenants/:tenantCode/subtenants/:subtenantCode")

	admin.MustOK("POST", "/tenant-licensing/api/v1/licenses/assign", map[string]any{
		"tenant_code": tenantCode, "plan_code": "starter",
	})
	mark("POST /api/v1/licenses/assign")
	licenses := admin.MustOK("GET", "/tenant-licensing/api/v1/licenses", nil)
	mark("GET /api/v1/licenses")
	licenseID := int64(0)
	for _, item := range apitest.Slice(licenses["items"]) {
		row := apitest.Map(item)
		if fmt.Sprint(row["tenant_code"]) == tenantCode {
			licenseID = apitest.AsInt64(row["id"])
			break
		}
	}
	if licenseID == 0 {
		t.Fatalf("нет license id: %v", licenses)
	}
	admin.MustOK("PATCH", fmt.Sprintf("/tenant-licensing/api/v1/licenses/%d", licenseID), map[string]any{
		"status": "active", "seats_max": 25,
	})
	mark("PATCH /api/v1/licenses/:id")

	planCode := "httpplan" + suffix[:8]
	admin.MustStatus("POST", "/tenant-licensing/api/v1/plans", map[string]any{
		"code": planCode, "name": "HTTP Plan", "status": "active",
		"features": map[string]any{"rbac": true},
		"limits":   map[string]any{"max_users": 10},
	}, http.StatusCreated)
	mark("POST /api/v1/plans")
	admin.MustOK("PATCH", "/tenant-licensing/api/v1/plans/"+planCode, map[string]any{
		"name": "HTTP Plan 2", "status": "active",
	})
	mark("PATCH /api/v1/plans/:code")

	managedCode := "httpmg" + suffix[:8]
	admin.MustStatus("POST", "/tenant-licensing/api/v1/tenants", map[string]any{
		"code": managedCode, "name": "HTTP managed " + suffix,
	}, http.StatusCreated)
	admin.MustStatus("POST", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/managed-tenants", map[string]any{
		"managed_tenant_code": managedCode, "grant_level": "operator",
	}, http.StatusCreated)
	mark("POST /api/v1/tenants/:tenantCode/managed-tenants")
	admin.MustOK("GET", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/managed-tenants", nil)
	mark("GET /api/v1/tenants/:tenantCode/managed-tenants")
	admin.MustOK("POST", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/managed-tenants/"+managedCode+"/revoke", nil)
	mark("POST /api/v1/tenants/:tenantCode/managed-tenants/:managedCode/revoke")
	admin.MustStatus("POST", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/managed-tenants", map[string]any{
		"managed_tenant_code": managedCode, "grant_level": "read_only",
	}, http.StatusCreated)
	admin.MustOK("DELETE", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/managed-tenants/"+managedCode, nil)
	mark("DELETE /api/v1/tenants/:tenantCode/managed-tenants/:managedCode")

	admin.MustOK("GET", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/quota", nil)
	mark("GET /api/v1/tenants/:tenantCode/quota")
	admin.MustOK("GET", "/tenant-licensing/api/v1/ops/summary", nil)
	mark("GET /api/v1/ops/summary")
	admin.MustOK("GET", "/tenant-licensing/api/v1/audit?tenant_code="+tenantCode, nil)
	mark("GET /api/v1/audit")

	admin.MustOK("POST", "/tenant-licensing/api/v1/licenses/revoke", map[string]any{
		"tenant_code": tenantCode, "reason": "http_api_cover",
	})
	mark("POST /api/v1/licenses/revoke")

	admin.MustOK("GET", "/tenant-licensing/api/v1/tenants/"+tenantCode+"/entitlements", nil)
	mark("GET /api/v1/tenants/:tenantCode/entitlements")
	admin.MustOK("GET", "/tenant-licensing/api/v1/events?tenant_code="+tenantCode, nil)
	mark("GET /api/v1/events")

	internal.MustOK("GET", "/tenant-licensing/internal/v1/tenants/"+tenantCode+"/projects/main/access-state", nil)
	mark("GET /internal/v1/tenants/:tenantCode/projects/:projectCode/access-state")
	internal.MustOK("GET", "/tenant-licensing/internal/v1/tenants/"+tenantCode+"/subtenants/"+subCode+"/access-state", nil)
	mark("GET /internal/v1/tenants/:tenantCode/subtenants/:subtenantCode/access-state")

	pending := internal.MustOK("GET", "/tenant-licensing/internal/v1/events/pending", nil)
	mark("GET /internal/v1/events/pending")
	ackID := int64(1)
	if items := apitest.Slice(pending["items"]); len(items) > 0 {
		ackID = apitest.AsInt64(apitest.Map(items[0])["id"])
	}
	status, out := internal.JSON("POST", fmt.Sprintf("/tenant-licensing/internal/v1/events/%d/ack", ackID), map[string]any{})
	if status != http.StatusOK {
		t.Fatalf("ack: %d %v", status, out)
	}
	mark("POST /internal/v1/events/:id/ack")

	for _, want := range tlLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван HTTP-тестом: %s", want)
		}
	}
}
