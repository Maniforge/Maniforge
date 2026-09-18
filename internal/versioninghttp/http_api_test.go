package versioninghttp

import (
	"fmt"
	"net/http"
	"testing"
	"time"

	"maniforge/internal/platform/apitest"
	"maniforge/internal/rbac"
)

var versioningLiveRoutes = []string{
	"GET /health",
	"GET /api/v1/changes",
	"GET /api/v1/changes/:id",
	"GET /api/v1/registry",
}

func TestVersioningFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), versioningLiveRoutes)
}

func TestVersioningProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	status, out := c.JSON("GET", "/versioning/api/v1/changes", nil)
	if status != http.StatusUnauthorized {
		t.Fatalf("GET changes без токена: %d %v", status, out)
	}
}

func TestVersioningHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	rbacApp := rbac.NewApp(cfg, sqlDB)
	verApp := NewApp(cfg, sqlDB)

	password := "HttpApiCover!123"
	phone := apitest.UniquePhone("+7906")
	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	auth := &apitest.Client{T: t, App: rbacApp}
	reg := auth.MustStatus("POST", "/rbac/api/v1/auth/register", map[string]any{
		"phone": phone, "password": password,
		"email":                 "ver_cover_" + suffix + "@example.test",
		"organization_name":     "Ver Cover " + suffix,
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
	applySession(t, auth, login)
	auth.Auth = true
	reauth := auth.MustOK("POST", "/rbac/api/v1/auth/reauth", map[string]any{"password": password})
	action := apitest.Map(apitest.Nested(reauth, "credentials", "action"))
	auth.Session.ActionToken = fmt.Sprint(action["action_token"])
	auth.Action = true
	auth.MustStatus("POST", "/rbac/api/v1/projects", map[string]any{
		"code": "verproj" + suffix[:6], "name": "Versioning Project",
	}, http.StatusCreated)

	c := &apitest.Client{T: t, App: verApp, Auth: true, Session: auth.Session}
	hit := map[string]struct{}{}
	mark := func(key string) { hit[key] = struct{}{} }

	c.MustOK("GET", "/versioning/health", nil)
	mark("GET /health")
	c.MustOK("GET", "/versioning/api/v1/registry", nil)
	mark("GET /api/v1/registry")
	changes := c.MustOK("GET", "/versioning/api/v1/changes", nil)
	mark("GET /api/v1/changes")
	changeID := int64(0)
	if items := apitest.Slice(changes["items"]); len(items) > 0 {
		changeID = apitest.AsInt64(apitest.Map(items[0])["id"])
	}
	if changeID == 0 {
		st, out := c.JSON("GET", "/versioning/api/v1/changes/1", nil)
		if st != http.StatusNotFound && st != http.StatusOK {
			t.Fatalf("GET change by id: %d %v", st, out)
		}
	} else {
		c.MustOK("GET", fmt.Sprintf("/versioning/api/v1/changes/%d", changeID), nil)
	}
	mark("GET /api/v1/changes/:id")

	for _, want := range versioningLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван HTTP-тестом: %s", want)
		}
	}
}

func TestVersioningNegativeHTTP(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	rbacApp := rbac.NewApp(cfg, sqlDB)
	verApp := NewApp(cfg, sqlDB)
	admin := apitest.RegisterTenantAdmin(t, rbacApp, "+7925", "Ver Neg")
	reauth := admin.MustOK("POST", "/rbac/api/v1/auth/reauth", map[string]any{"password": admin.Session.Password})
	action := apitest.Map(apitest.Nested(reauth, "credentials", "action"))
	admin.Session.ActionToken = fmt.Sprint(action["action_token"])
	admin.Action = true

	member := apitest.RegisterUserWithoutWrite(t, rbacApp, admin)
	if member.Session.UserID <= 0 {
		me := apitest.DomainClient(t, rbacApp, member.Session).MustOK("GET", "/rbac/api/v1/me", nil)
		member.Session.UserID = apitest.AsInt64(apitest.Nested(me, "user", "id"))
		if member.Session.UserID <= 0 {
			member.Session.UserID = apitest.AsInt64(me["id"])
		}
	}
	if member.Session.UserID <= 0 {
		t.Fatalf("нет user id у member: %+v", member.Session)
	}

	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	roleCode := "vernone" + suffix[:6]
	created := admin.MustStatus("POST", "/rbac/api/v1/admin/roles", map[string]any{
		"code": roleCode, "name": "Versioning no registry", "reason": "ver_neg",
	}, http.StatusCreated)
	fullCode := fmt.Sprint(apitest.Map(created["role"])["code"])
	if fullCode == "" || fullCode == "<nil>" {
		t.Fatalf("нет role.code: %v", created)
	}
	admin.MustOK("PUT", "/rbac/api/v1/admin/role-permissions", map[string]any{
		"role_code": fullCode, "permissions": []any{"versioning.read"}, "reason": "ver_neg",
	})
	admin.MustOK("POST", "/rbac/api/v1/admin/user-roles/assign", map[string]any{
		"user_id": member.Session.UserID, "role_code": fullCode, "reason": "ver_neg",
	})
	admin.MustOK("POST", "/rbac/api/v1/admin/user-roles/revoke", map[string]any{
		"user_id": member.Session.UserID, "role_code": "user", "reason": "ver_neg",
	})

	c := apitest.DomainClient(t, verApp, member.Session)
	apitest.MustForbidden(c, "GET", "/versioning/api/v1/registry", nil)
	apitest.MustBadRequest(c, "GET", "/versioning/api/v1/changes/not-an-id", nil)
}

func applySession(t *testing.T, c *apitest.Client, login map[string]any) {
	t.Helper()
	sess := apitest.Map(login["session"])
	if sess == nil {
		sess = apitest.Map(apitest.Nested(login, "credentials", "session"))
	}
	c.Session.Token = fmt.Sprint(sess["access_token"])
	c.Session.CSRF = fmt.Sprint(sess["csrf_token"])
	c.Session.Refresh = fmt.Sprint(sess["refresh_token"])
	if c.Session.Token == "" || c.Session.Token == "<nil>" {
		t.Fatalf("нет access_token: %v", login)
	}
}
