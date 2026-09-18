package apitest

import (
	"fmt"
	"net/http"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
)

// SeedOperatorGrant создаёт TL tenant с code=principal (если ещё нет), managed tenant
// и active grant operator. Возвращает managed_tenant_code.
func SeedOperatorGrant(t *testing.T, tlApp *fiber.App, principalTenant string) string {
	t.Helper()
	admin := &Client{T: t, App: tlApp, Auth: true, Session: Session{Token: TLAdminToken}}
	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	managed := "peer" + suffix
	if len(managed) > 24 {
		managed = managed[:24]
	}
	admin.MustStatus("POST", "/tenant-licensing/api/v1/tenants", map[string]any{
		"code": managed, "name": "Managed peer " + suffix,
	}, http.StatusCreated)
	grantPath := "/tenant-licensing/api/v1/tenants/" + principalTenant + "/managed-tenants"
	grantBody := map[string]any{"managed_tenant_code": managed, "grant_level": "operator"}
	st, out := admin.JSON("POST", grantPath, grantBody)
	if st == http.StatusNotFound {
		created, cout := admin.JSON("POST", "/tenant-licensing/api/v1/tenants", map[string]any{
			"code": principalTenant, "name": "Principal " + principalTenant,
		})
		if created != http.StatusCreated && created != http.StatusConflict {
			t.Fatalf("create principal TL tenant: status %d body=%v", created, cout)
		}
		admin.MustStatus("POST", grantPath, grantBody, http.StatusCreated)
		return managed
	}
	if st != http.StatusCreated {
		t.Fatalf("create managed grant: status %d body=%v", st, out)
	}
	return managed
}

// RegisterSameTenantUser создаёт второго пользователя того же tenant с ролью user (не tenant_admin).
func RegisterSameTenantUser(t *testing.T, rbacApp *fiber.App, admin *Client) *Client {
	t.Helper()
	rbacAdmin := &Client{T: t, App: rbacApp, Auth: true, Tenant: true, Session: admin.Session}
	reauth := rbacAdmin.MustOK("POST", "/rbac/api/v1/auth/reauth", map[string]any{
		"password": admin.Session.Password,
	})
	action := Map(Nested(reauth, "credentials", "action"))
	rbacAdmin.Session.ActionToken = fmt.Sprint(action["action_token"])
	if rbacAdmin.Session.ActionToken == "" || rbacAdmin.Session.ActionToken == "<nil>" {
		t.Fatalf("нет action_token: %v", reauth)
	}
	rbacAdmin.Action = true

	password := "HttpApiCover!123"
	phone := UniquePhone("+7919")
	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	created := rbacAdmin.MustStatus("POST", "/rbac/api/v1/admin/users", map[string]any{
		"login":    "gpuser" + suffix[:8],
		"password": password,
		"phone":    phone,
		"email":    "gpuser_" + suffix + "@example.test",
		"status":   "active",
		"reason":   "grant_peers_cover",
	}, http.StatusCreated)
	userID := AsInt64(Nested(created, "user", "id"))
	if userID <= 0 {
		t.Fatalf("нет user.id: %v", created)
	}
	rbacAdmin.MustOK("POST", "/rbac/api/v1/admin/user-roles/assign", map[string]any{
		"user_id": userID, "role_code": "user", "reason": "grant_peers_cover",
	})

	member := &Client{T: t, App: rbacApp, Tenant: true, Session: Session{
		TenantID: admin.Session.TenantID, SubtenantID: admin.Session.SubtenantID,
		Phone: phone, Password: password,
	}}
	login := member.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password,
		"tenant_id": member.Session.TenantID, "subtenant_id": member.Session.SubtenantID,
	})
	ApplySession(t, member, login)
	member.Auth = true
	return member
}

// AssertGrantPeersFromTL сеет TL grant, проверяет GET /api/v1/delegation/grant-peers
// (items не пустой, tenant_id = managed code) и 403 для не-admin.
func AssertGrantPeersFromTL(t *testing.T, domainApp, rbacApp, tlApp *fiber.App, phonePrefix string) {
	t.Helper()
	admin := RegisterTenantAdmin(t, rbacApp, phonePrefix, "GrantPeers")
	managed := SeedOperatorGrant(t, tlApp, admin.Session.TenantID)

	c := &Client{T: t, App: domainApp, Auth: true, Session: admin.Session}
	out := c.MustOK("GET", "/api/v1/delegation/grant-peers", nil)
	items := Slice(out["items"])
	if len(items) < 1 {
		t.Fatalf("ожидали peer из TL grant, получили %v", out)
	}
	found := false
	for _, raw := range items {
		row := Map(raw)
		if fmt.Sprint(row["tenant_id"]) == managed {
			found = true
			if fmt.Sprint(row["grant_level"]) != "operator" {
				t.Fatalf("grant_level: %v", row)
			}
			if fmt.Sprint(row["status"]) != "active" {
				t.Fatalf("status: %v", row)
			}
		}
	}
	if !found {
		t.Fatalf("нет tenant_id=%s в items: %v", managed, out)
	}

	member := RegisterSameTenantUser(t, rbacApp, admin)
	denied := &Client{T: t, App: domainApp, Auth: true, Session: member.Session}
	body := denied.MustStatus("GET", "/api/v1/delegation/grant-peers", nil, http.StatusForbidden)
	if fmt.Sprint(body["error"]) != "Требуется tenant_admin" {
		t.Fatalf("не-admin: %v", body)
	}
}
