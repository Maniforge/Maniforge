package rbac

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/pquerna/otp/totp"
	"maniforge/internal/platform/apitest"
)

// rbacLiveRoutes — уникальные Fiber-маршруты RBAC (без дубля /rbac).
var rbacLiveRoutes = []string{
	"GET /health",
	"GET /api/v1/privacy/notice",
	"POST /api/v1/auth/register",
	"POST /api/v1/auth/login",
	"POST /api/v1/auth/refresh",
	"POST /api/v1/auth/logout",
	"POST /api/v1/auth/logout-all",
	"POST /api/v1/auth/reauth",
	"POST /api/v1/auth/switch-context",
	"POST /api/v1/auth/switch-project",
	"POST /api/v1/auth/accept-invite",
	"GET /api/v1/me",
	"GET /api/v1/me/profile",
	"GET /api/v1/me/permissions",
	"GET /api/v1/me/contexts",
	"GET /api/v1/me/access",
	"GET /api/v1/me/console-access",
	"PATCH /api/v1/me/profile",
	"PATCH /api/v1/me/identity",
	"POST /api/v1/me/change-password",
	"POST /api/v1/me/security/password",
	"GET /api/v1/me/personal-data",
	"GET /api/v1/me/personal-data/consents",
	"POST /api/v1/me/personal-data/consents",
	"POST /api/v1/me/personal-data/consents/revoke",
	"GET /api/v1/me/personal-data/subject-requests",
	"POST /api/v1/me/personal-data/subject-requests",
	"GET /api/v1/me/mfa",
	"POST /api/v1/me/mfa/enroll",
	"POST /api/v1/me/mfa/verify",
	"POST /api/v1/me/mfa/disable",
	"GET /api/v1/projects",
	"POST /api/v1/projects",
	"POST /api/v1/projects/memberships",
	"GET /api/v1/projects/:code",
	"PATCH /api/v1/projects/:code",
	"GET /api/v1/global-variables",
	"POST /api/v1/global-variables",
	"GET /api/v1/admin/users",
	"POST /api/v1/admin/users",
	"PATCH /api/v1/admin/users",
	"DELETE /api/v1/admin/users",
	"POST /api/v1/admin/users/batch-status",
	"POST /api/v1/admin/user-roles/assign",
	"POST /api/v1/admin/user-roles/revoke",
	"POST /api/v1/admin/user-roles/batch",
	"GET /api/v1/admin/user-roles",
	"GET /api/v1/admin/effective-access",
	"GET /api/v1/admin/policies",
	"POST /api/v1/admin/policies",
	"GET /api/v1/admin/ops-summary",
	"GET /api/v1/admin/sessions",
	"POST /api/v1/admin/sessions/revoke",
	"POST /api/v1/admin/sessions/batch-revoke",
	"GET /api/v1/admin/audit",
	"GET /api/v1/admin/audit/export",
	"GET /api/v1/admin/security-events",
	"GET /api/v1/admin/roles",
	"POST /api/v1/admin/roles",
	"PATCH /api/v1/admin/roles",
	"DELETE /api/v1/admin/roles",
	"GET /api/v1/admin/permissions",
	"GET /api/v1/admin/role-permissions",
	"PUT /api/v1/admin/role-permissions",
	"GET /api/v1/admin/personal-data/operator-profile",
	"PUT /api/v1/admin/personal-data/operator-profile",
	"GET /api/v1/admin/personal-data/compliance-status",
	"GET /api/v1/admin/personal-data/purposes",
	"POST /api/v1/admin/personal-data/purposes",
	"PATCH /api/v1/admin/personal-data/purposes",
	"GET /api/v1/admin/personal-data/subject-requests",
	"POST /api/v1/admin/personal-data/subject-requests/resolve",
	"POST /api/v1/admin/personal-data/dpa-acknowledge",
	"POST /api/v1/admin/registration-invites",
	"POST /api/v1/admin/organization-members",
	"POST /internal/v1/tenant-events",
}

var rbacProtectedPrefixes = []string{
	"POST /api/v1/auth/logout",
	"POST /api/v1/auth/reauth",
	"POST /api/v1/auth/switch-context",
	"GET /api/v1/me",
	"GET /api/v1/projects",
	"GET /api/v1/admin/users",
}

func TestRBACFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), rbacLiveRoutes)
}

func TestRBACProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	for _, key := range rbacProtectedPrefixes {
		method, path := splitRoute(key)
		status, out := c.JSON(method, "/rbac"+path, map[string]any{})
		if status != http.StatusUnauthorized {
			t.Errorf("%s: status %d want 401 body=%v", key, status, out)
		}
	}
}

func TestRBACEmptyCSRFRejected(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	app := NewApp(cfg, sqlDB)
	admin := apitest.RegisterTenantAdmin(t, app, "+7920", "CSRF Neg")
	apitest.MustForbidden(apitest.WithoutCSRF(admin), "POST", "/rbac/api/v1/auth/logout", nil)
}

func TestRBACHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	app := NewApp(cfg, sqlDB)
	hit := map[string]struct{}{}
	mark := func(method, path string) {
		hit[apitest.CanonicalRoute(method, path)] = struct{}{}
	}

	admin := &apitest.Client{T: t, App: app}
	password := "HttpApiCover!123"
	phone := apitest.UniquePhone("+7901")
	extraPhone := apitest.UniquePhone("+7902")
	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)

	reg := admin.MustStatus("POST", "/rbac/api/v1/auth/register", map[string]any{
		"phone": phone, "password": password,
		"email":                 "httpcover_" + suffix + "@example.test",
		"organization_name":     "HTTP Cover " + suffix,
		"platform_dpa_accepted": true,
		"consents":              []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, http.StatusCreated)
	mark("POST", "/api/v1/auth/register")
	tenant := apitest.Map(reg["tenant"])
	admin.Session.TenantID = fmt.Sprint(tenant["tenant_id"])
	admin.Session.SubtenantID = fmt.Sprint(tenant["subtenant_id"])
	admin.Session.Phone = phone
	admin.Session.Password = password
	if admin.Session.SubtenantID == "" || admin.Session.SubtenantID == "<nil>" {
		admin.Session.SubtenantID = "main"
	}
	admin.Tenant = true

	status, notice := admin.JSON("GET", "/rbac/api/v1/privacy/notice", nil)
	if status != http.StatusOK {
		t.Fatalf("privacy/notice: %d %v", status, notice)
	}
	mark("GET", "/api/v1/privacy/notice")

	health := admin.MustOK("GET", "/rbac/health", nil)
	if health["ok"] != true {
		t.Fatalf("health: %v", health)
	}
	mark("GET", "/health")

	login := admin.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password,
		"tenant_id": admin.Session.TenantID, "subtenant_id": admin.Session.SubtenantID,
	})
	mark("POST", "/api/v1/auth/login")
	applyLogin(t, admin, login)
	admin.Auth = true

	reauth := admin.MustOK("POST", "/rbac/api/v1/auth/reauth", map[string]any{"password": password})
	mark("POST", "/api/v1/auth/reauth")
	action := apitest.Map(apitest.Nested(reauth, "credentials", "action"))
	admin.Session.ActionToken = fmt.Sprint(action["action_token"])
	if admin.Session.ActionToken == "" || admin.Session.ActionToken == "<nil>" {
		t.Fatalf("нет action_token: %v", reauth)
	}
	admin.Action = true

	admin.MustOK("GET", "/rbac/api/v1/me", nil)
	mark("GET", "/api/v1/me")
	admin.MustOK("GET", "/rbac/api/v1/me/profile", nil)
	mark("GET", "/api/v1/me/profile")
	admin.MustOK("GET", "/rbac/api/v1/me/permissions", nil)
	mark("GET", "/api/v1/me/permissions")
	admin.MustOK("GET", "/rbac/api/v1/me/contexts", nil)
	mark("GET", "/api/v1/me/contexts")
	admin.MustOK("GET", "/rbac/api/v1/me/access", nil)
	mark("GET", "/api/v1/me/access")
	admin.MustOK("GET", "/rbac/api/v1/me/console-access", nil)
	mark("GET", "/api/v1/me/console-access")
	admin.MustOK("PATCH", "/rbac/api/v1/me/profile", map[string]any{"display_name": "HTTP Cover Admin"})
	mark("PATCH", "/api/v1/me/profile")
	admin.MustOK("GET", "/rbac/api/v1/me/personal-data", nil)
	mark("GET", "/api/v1/me/personal-data")
	admin.MustOK("GET", "/rbac/api/v1/me/personal-data/consents", nil)
	mark("GET", "/api/v1/me/personal-data/consents")
	admin.MustStatus("POST", "/rbac/api/v1/me/personal-data/consents", map[string]any{
		"purpose_code": "support", "policy_version": "1.0",
	}, http.StatusCreated)
	mark("POST", "/api/v1/me/personal-data/consents")
	admin.MustOK("POST", "/rbac/api/v1/me/personal-data/consents/revoke", map[string]any{
		"purpose_code": "support",
	})
	mark("POST", "/api/v1/me/personal-data/consents/revoke")
	admin.MustOK("GET", "/rbac/api/v1/me/personal-data/subject-requests", nil)
	mark("GET", "/api/v1/me/personal-data/subject-requests")
	admin.MustStatus("POST", "/rbac/api/v1/me/personal-data/subject-requests", map[string]any{
		"request_type": "access",
		"payload":      map[string]any{"note": "http_api_cover"},
	}, http.StatusCreated)
	mark("POST", "/api/v1/me/personal-data/subject-requests")
	admin.MustOK("GET", "/rbac/api/v1/me/mfa", nil)
	mark("GET", "/api/v1/me/mfa")

	enroll := admin.MustOK("POST", "/rbac/api/v1/me/mfa/enroll", map[string]any{"label": "http-test"})
	mark("POST", "/api/v1/me/mfa/enroll")
	secret := fmt.Sprint(enroll["secret"])
	code, err := totp.GenerateCode(secret, time.Now())
	if err != nil {
		t.Fatalf("totp: %v", err)
	}
	admin.MustOK("POST", "/rbac/api/v1/me/mfa/verify", map[string]any{"code": code})
	mark("POST", "/api/v1/me/mfa/verify")
	admin.MustOK("POST", "/rbac/api/v1/me/mfa/disable", map[string]any{"password": password})
	mark("POST", "/api/v1/me/mfa/disable")

	admin.MustOK("POST", "/rbac/api/v1/auth/switch-context", map[string]any{
		"tenant_id": admin.Session.TenantID, "subtenant_id": admin.Session.SubtenantID,
	})
	mark("POST", "/api/v1/auth/switch-context")

	admin.MustOK("GET", "/rbac/api/v1/projects", nil)
	mark("GET", "/api/v1/projects")
	projCode := "httpproj" + suffix[:6]
	createdProj := admin.MustStatus("POST", "/rbac/api/v1/projects", map[string]any{
		"code": projCode, "name": "HTTP Project",
	}, http.StatusCreated)
	mark("POST", "/api/v1/projects")
	projectID := apitest.AsInt64(apitest.Nested(createdProj, "project", "id"))
	if projectID == 0 {
		t.Fatalf("нет project.id: %v", createdProj)
	}
	admin.MustOK("GET", "/rbac/api/v1/projects/"+projCode, nil)
	mark("GET", "/api/v1/projects/:code")
	admin.MustOK("PATCH", "/rbac/api/v1/projects/"+projCode, map[string]any{
		"name": "HTTP Project renamed",
	})
	mark("PATCH", "/api/v1/projects/:code")
	admin.MustOK("POST", "/rbac/api/v1/global-variables", map[string]any{
		"key": "http_cover_flag", "value": "1", "scope_level": "subtenant",
	})
	mark("POST", "/api/v1/global-variables")
	admin.MustOK("GET", "/rbac/api/v1/global-variables", nil)
	mark("GET", "/api/v1/global-variables")
	admin.MustOK("POST", "/rbac/api/v1/auth/switch-project", map[string]any{"project_id": projectID})
	mark("POST", "/api/v1/auth/switch-project")

	users := admin.MustOK("GET", "/rbac/api/v1/admin/users", nil)
	mark("GET", "/api/v1/admin/users")
	created := admin.MustStatus("POST", "/rbac/api/v1/admin/users", map[string]any{
		"login":    "httpuser" + suffix[:8],
		"password": password,
		"phone":    extraPhone,
		"email":    "httpuser_" + suffix + "@example.test",
		"status":   "active",
		"reason":   "http_api_cover",
	}, http.StatusCreated)
	mark("POST", "/api/v1/admin/users")
	extraID := apitest.AsInt64(apitest.Nested(created, "user", "id"))
	if extraID == 0 {
		t.Fatalf("нет user.id: %v", created)
	}
	admin.MustOK("PATCH", "/rbac/api/v1/admin/users", map[string]any{
		"user_id": extraID, "email": "httpuser_patched_" + suffix + "@example.test", "reason": "http_api_cover",
	})
	mark("PATCH", "/api/v1/admin/users")
	admin.MustStatus("POST", "/rbac/api/v1/projects/memberships", map[string]any{
		"user_id": extraID, "project_code": projCode,
	}, http.StatusCreated)
	mark("POST", "/api/v1/projects/memberships")

	admin.MustOK("POST", "/rbac/api/v1/admin/user-roles/assign", map[string]any{
		"user_id": extraID, "role_code": "user", "reason": "http_api_cover",
	})
	mark("POST", "/api/v1/admin/user-roles/assign")
	admin.MustOK("POST", "/rbac/api/v1/admin/user-roles/assign", map[string]any{
		"user_id": extraID, "role_code": "support_operator", "reason": "http_api_cover",
	})
	admin.MustOK("POST", "/rbac/api/v1/admin/user-roles/batch", map[string]any{
		"reason":  "http_api_cover_dry",
		"dry_run": true,
		"items":   []map[string]any{{"user_id": extraID, "role_code": "support_operator", "action": "revoke"}},
	})
	mark("POST", "/api/v1/admin/user-roles/batch")
	admin.MustOK("POST", "/rbac/api/v1/admin/user-roles/revoke", map[string]any{
		"user_id": extraID, "role_code": "support_operator", "reason": "http_api_cover",
	})
	mark("POST", "/api/v1/admin/user-roles/revoke")
	admin.MustOK("GET", fmt.Sprintf("/rbac/api/v1/admin/user-roles?user_id=%d", extraID), nil)
	mark("GET", "/api/v1/admin/user-roles")
	admin.MustOK("GET", fmt.Sprintf("/rbac/api/v1/admin/effective-access?user_id=%d", extraID), nil)
	mark("GET", "/api/v1/admin/effective-access")
	admin.MustOK("POST", "/rbac/api/v1/admin/users/batch-status", map[string]any{
		"reason":  "http_api_cover_dry",
		"dry_run": true,
		"items":   []map[string]any{{"user_id": extraID, "status": "active"}},
	})
	mark("POST", "/api/v1/admin/users/batch-status")

	policies := admin.MustOK("GET", "/rbac/api/v1/admin/policies", nil)
	mark("GET", "/api/v1/admin/policies")
	rules := apitest.Map(policies["rules"])
	if rules == nil {
		rules = map[string]any{}
	}
	admin.MustOK("POST", "/rbac/api/v1/admin/policies", map[string]any{
		"reason":                   "http_api_cover_noop",
		"allowed_ips":              []any{},
		"allowed_hour_start_utc":   0,
		"allowed_hour_end_utc":     23,
		"require_step_up":          true,
		"require_mfa_enrollment":   false,
	})
	mark("POST", "/api/v1/admin/policies")
	_ = rules
	_ = users

	admin.MustOK("GET", "/rbac/api/v1/admin/ops-summary", nil)
	mark("GET", "/api/v1/admin/ops-summary")
	admin.MustOK("GET", "/rbac/api/v1/admin/audit", nil)
	mark("GET", "/api/v1/admin/audit")
	admin.MustOK("GET", "/rbac/api/v1/admin/audit/export", nil)
	mark("GET", "/api/v1/admin/audit/export")
	admin.MustOK("GET", "/rbac/api/v1/admin/security-events", nil)
	mark("GET", "/api/v1/admin/security-events")
	admin.MustOK("GET", "/rbac/api/v1/admin/permissions", nil)
	mark("GET", "/api/v1/admin/permissions")
	admin.MustOK("GET", "/rbac/api/v1/admin/roles", nil)
	mark("GET", "/api/v1/admin/roles")

	roleCode := "httprole" + suffix[:6]
	admin.MustStatus("POST", "/rbac/api/v1/admin/roles", map[string]any{
		"code": roleCode, "name": "HTTP Role", "reason": "http_api_cover",
	}, http.StatusCreated)
	mark("POST", "/api/v1/admin/roles")
	admin.MustOK("PATCH", "/rbac/api/v1/admin/roles", map[string]any{
		"code": roleCode, "name": "HTTP Role 2", "reason": "http_api_cover",
	})
	mark("PATCH", "/api/v1/admin/roles")
	admin.MustOK("GET", "/rbac/api/v1/admin/role-permissions?role_code="+roleCode, nil)
	mark("GET", "/api/v1/admin/role-permissions")
	admin.MustOK("PUT", "/rbac/api/v1/admin/role-permissions", map[string]any{
		"role_code":   roleCode,
		"permissions": []any{"projects.read"},
		"reason":      "http_api_cover",
	})
	mark("PUT", "/api/v1/admin/role-permissions")
	admin.MustOK("DELETE", "/rbac/api/v1/admin/roles", map[string]any{
		"code": roleCode, "reason": "http_api_cover",
	})
	mark("DELETE", "/api/v1/admin/roles")

	admin.MustOK("GET", "/rbac/api/v1/admin/personal-data/operator-profile", nil)
	mark("GET", "/api/v1/admin/personal-data/operator-profile")
	admin.MustOK("PUT", "/rbac/api/v1/admin/personal-data/operator-profile", map[string]any{
		"operator_name": "HTTP Cover Operator",
	})
	mark("PUT", "/api/v1/admin/personal-data/operator-profile")
	admin.MustOK("POST", "/rbac/api/v1/admin/personal-data/dpa-acknowledge", map[string]any{})
	mark("POST", "/api/v1/admin/personal-data/dpa-acknowledge")
	admin.MustOK("GET", "/rbac/api/v1/admin/personal-data/compliance-status", nil)
	mark("GET", "/api/v1/admin/personal-data/compliance-status")
	admin.MustOK("GET", "/rbac/api/v1/admin/personal-data/purposes", nil)
	mark("GET", "/api/v1/admin/personal-data/purposes")
	purposeCode := "http_purpose_" + suffix[:8]
	admin.MustStatus("POST", "/rbac/api/v1/admin/personal-data/purposes", map[string]any{
		"code": purposeCode, "title": "HTTP purpose", "legal_basis": "consent",
	}, http.StatusCreated)
	mark("POST", "/api/v1/admin/personal-data/purposes")
	admin.MustOK("PATCH", "/rbac/api/v1/admin/personal-data/purposes", map[string]any{
		"code": purposeCode, "title": "HTTP purpose 2",
	})
	mark("PATCH", "/api/v1/admin/personal-data/purposes")
	admin.MustOK("GET", "/rbac/api/v1/admin/personal-data/subject-requests", nil)
	mark("GET", "/api/v1/admin/personal-data/subject-requests")
	resolveStatus, resolveBody := admin.JSON("POST", "/rbac/api/v1/admin/personal-data/subject-requests/resolve", map[string]any{
		"request_id": 999999999, "status": "rejected", "handler_note": "no such request in cover",
	})
	if resolveStatus != http.StatusNotFound {
		t.Fatalf("resolve want 404, got %d %v", resolveStatus, resolveBody)
	}
	mark("POST", "/api/v1/admin/personal-data/subject-requests/resolve")

	invite := admin.MustStatus("POST", "/rbac/api/v1/admin/registration-invites", map[string]any{
		"invite_type": "user", "role_code": "user",
	}, http.StatusCreated)
	mark("POST", "/api/v1/admin/registration-invites")
	inviteToken := fmt.Sprint(invite["invite_token"])
	if inviteToken == "" || inviteToken == "<nil>" {
		t.Fatalf("нет invite_token: %v", invite)
	}

	guestPhone := apitest.UniquePhone("+7904")
	guest := registerCoverUser(t, app, guestPhone, password, "HTTP Guest")
	guest.MustStatus("POST", "/rbac/api/v1/auth/accept-invite", map[string]any{
		"invite_token": inviteToken,
	}, http.StatusCreated)
	mark("POST", "/api/v1/auth/accept-invite")

	attachPhone := apitest.UniquePhone("+7905")
	_ = registerCoverUser(t, app, attachPhone, password, "HTTP Attach")
	admin.MustStatus("POST", "/rbac/api/v1/admin/organization-members", map[string]any{
		"phone": attachPhone, "role_code": "user", "reason": "http_api_cover",
	}, http.StatusCreated)
	mark("POST", "/api/v1/admin/organization-members")

	extra := &apitest.Client{T: t, App: app, Tenant: true, Session: apitest.Session{
		TenantID: admin.Session.TenantID, SubtenantID: admin.Session.SubtenantID,
		Phone: extraPhone, Password: password,
	}}
	extraLogin := extra.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": extraPhone, "password": password,
		"tenant_id": extra.Session.TenantID, "subtenant_id": extra.Session.SubtenantID,
	})
	applyLogin(t, extra, extraLogin)
	extra.Auth = true
	extra.MustOK("PATCH", "/rbac/api/v1/me/identity", map[string]any{
		"email": "httpuser_renamed_" + suffix + "@example.test",
	})
	mark("PATCH", "/api/v1/me/identity")

	extraLogin2 := extra.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": extraPhone, "password": password,
		"tenant_id": extra.Session.TenantID, "subtenant_id": extra.Session.SubtenantID,
	})
	applyLogin(t, extra, extraLogin2)
	newPass := "HttpApiCover!456"
	extra.MustOK("POST", "/rbac/api/v1/me/change-password", map[string]any{
		"current_password": password, "new_password": newPass,
	})
	mark("POST", "/api/v1/me/change-password")
	afterChange := extra.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": extraPhone, "password": newPass,
		"tenant_id": extra.Session.TenantID, "subtenant_id": extra.Session.SubtenantID,
	})
	applyLogin(t, extra, afterChange)
	aliasPass := "HttpApiCover!789"
	extra.MustOK("POST", "/rbac/api/v1/me/security/password", map[string]any{
		"current_password": newPass, "new_password": aliasPass,
	})
	mark("POST", "/api/v1/me/security/password")
	newPass = aliasPass

	sessions := admin.MustOK("GET", "/rbac/api/v1/admin/sessions", nil)
	mark("GET", "/api/v1/admin/sessions")
	var extraSessionID string
	for _, item := range apitest.Slice(sessions["items"]) {
		row := apitest.Map(item)
		if apitest.AsInt64(row["user_id"]) == extraID {
			extraSessionID = fmt.Sprint(row["id"])
			break
		}
	}
	if extraSessionID == "" || extraSessionID == "<nil>" {
		items := apitest.Slice(sessions["items"])
		if len(items) > 0 {
			for _, item := range items {
				row := apitest.Map(item)
				id := fmt.Sprint(row["id"])
				if id != "" && id != admin.Session.Token && id != fmt.Sprint(apitest.Nested(login, "session", "session_id")) {
					if id != admin.Session.Token {
						extraSessionID = id
					}
				}
			}
		}
	}
	adminSessionID := fmt.Sprint(apitest.Nested(login, "session", "session_id"))
	if extraSessionID == "" || extraSessionID == adminSessionID {
		extraLogin3 := extra.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
			"phone": extraPhone, "password": newPass,
			"tenant_id": extra.Session.TenantID, "subtenant_id": extra.Session.SubtenantID,
		})
		applyLogin(t, extra, extraLogin3)
		sessions = admin.MustOK("GET", "/rbac/api/v1/admin/sessions", nil)
		for _, item := range apitest.Slice(sessions["items"]) {
			row := apitest.Map(item)
			if apitest.AsInt64(row["user_id"]) == extraID {
				extraSessionID = fmt.Sprint(row["id"])
				break
			}
		}
	}
	if extraSessionID == "" {
		t.Fatalf("нет session_id extra user: %v", sessions)
	}

	admin.MustOK("POST", "/rbac/api/v1/admin/sessions/batch-revoke", map[string]any{
		"reason": "http_api_cover_dry", "dry_run": true, "session_ids": []any{extraSessionID},
	})
	mark("POST", "/api/v1/admin/sessions/batch-revoke")
	admin.MustOK("POST", "/rbac/api/v1/admin/sessions/revoke", map[string]any{
		"session_id": extraSessionID, "reason": "http_api_cover",
	})
	mark("POST", "/api/v1/admin/sessions/revoke")

	extraLoginAll := extra.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": extraPhone, "password": newPass,
		"tenant_id": extra.Session.TenantID, "subtenant_id": extra.Session.SubtenantID,
	})
	applyLogin(t, extra, extraLoginAll)
	extra.MustOK("POST", "/rbac/api/v1/auth/logout-all", nil)
	mark("POST", "/api/v1/auth/logout-all")

	admin.MustOK("DELETE", "/rbac/api/v1/admin/users", map[string]any{
		"user_id": extraID, "reason": "http_api_cover",
	})
	mark("DELETE", "/api/v1/admin/users")

	internal := &apitest.Client{T: t, App: app, Auth: true, Session: apitest.Session{Token: apitest.InternalToken}}
	internal.MustOK("POST", "/rbac/internal/v1/tenant-events", map[string]any{
		"event_type": "license.assigned", "tenant_code": admin.Session.TenantID,
		"payload": map[string]any{"source": "http_api_cover"},
	})
	mark("POST", "/internal/v1/tenant-events")

	refresh := &apitest.Client{T: t, App: app}
	refreshed := refresh.MustOK("POST", "/rbac/api/v1/auth/refresh", map[string]any{
		"refresh_token": admin.Session.Refresh,
	})
	mark("POST", "/api/v1/auth/refresh")
	oldCSRF := admin.Session.CSRF
	applyLogin(t, admin, refreshed)
	admin.Session.CSRF = oldCSRF

	admin.MustOK("POST", "/rbac/api/v1/auth/logout", nil)
	mark("POST", "/api/v1/auth/logout")

	for _, want := range rbacLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван HTTP-тестом: %s", want)
		}
	}
}

func applyLogin(t *testing.T, c *apitest.Client, login map[string]any) {
	t.Helper()
	sess := apitest.Map(login["session"])
	if sess == nil {
		sess = apitest.Map(apitest.Nested(login, "credentials", "session"))
	}
	c.Session.Token = fmt.Sprint(sess["access_token"])
	c.Session.CSRF = fmt.Sprint(sess["csrf_token"])
	c.Session.Refresh = fmt.Sprint(sess["refresh_token"])
	c.Session.UserID = apitest.AsInt64(sess["user_id"])
	if c.Session.Token == "" || c.Session.Token == "<nil>" {
		t.Fatalf("нет access_token: %v", login)
	}
	if c.Session.CSRF == "" || c.Session.CSRF == "<nil>" {
		c.Session.CSRF = fmt.Sprint(login["csrf_token"])
	}
}

func splitRoute(key string) (method, path string) {
	method, path, _ = strings.Cut(key, " ")
	return
}

func registerCoverUser(t *testing.T, app *fiber.App, phone, password, org string) *apitest.Client {
	t.Helper()
	c := &apitest.Client{T: t, App: app}
	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	reg := c.MustStatus("POST", "/rbac/api/v1/auth/register", map[string]any{
		"phone": phone, "password": password,
		"email":                 "cover_" + suffix + "@example.test",
		"organization_name":     org + " " + suffix,
		"platform_dpa_accepted": true,
		"consents":              []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, http.StatusCreated)
	tenant := apitest.Map(reg["tenant"])
	c.Session.TenantID = fmt.Sprint(tenant["tenant_id"])
	c.Session.SubtenantID = fmt.Sprint(tenant["subtenant_id"])
	if c.Session.SubtenantID == "" || c.Session.SubtenantID == "<nil>" {
		c.Session.SubtenantID = "main"
	}
	c.Tenant = true
	login := c.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password,
		"tenant_id": c.Session.TenantID, "subtenant_id": c.Session.SubtenantID,
	})
	applyLogin(t, c, login)
	c.Auth = true
	return c
}
