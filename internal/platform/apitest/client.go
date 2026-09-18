// Package apitest — общий HTTP-клиент для Fiber app.Test (RBAC, Manifest).
package apitest

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
)

const TestTimeoutMS = 30000

const InternalToken = "http-api-test-internal"
const TLAdminToken = "http-api-test-tl-admin"

// PIIKeyB64 — 32 байта AES для MFA enroll (Encrypt требует ключ даже без PII-at-rest).
var PIIKeyB64 = base64.StdEncoding.EncodeToString(bytes.Repeat([]byte{0x11}, 32))

type Session struct {
	Token       string
	CSRF        string
	Refresh     string
	ActionToken string
	TenantID    string
	SubtenantID string
	Password    string
	Phone       string
	UserID      int64
}

type Client struct {
	T       *testing.T
	App     *fiber.App
	Session Session
	Auth    bool
	Action  bool
	Tenant  bool
}

func TestConfig() config.Config {
	cfg, err := config.Load()
	if err != nil {
		cfg = config.Config{}
	}
	cfg.AppEnv = "test"
	cfg.AppName = "maniforge-http-test"
	cfg.TenancyMode = "single"
	cfg.RBACRegistrationEnabled = true
	cfg.RBACRegistrationPlan = "starter"
	cfg.RBACRegistrationDefaultSubtenantID = "main"
	cfg.RBACRegistrationBootstrapRole = "tenant_admin"
	cfg.RBACRegistrationDefaultRole = "user"
	cfg.RBACPasswordMinLength = 12
	cfg.RBACPDDpaSelfSignOnRegister = true
	cfg.RBACPDRegisterConsentRequired = false
	cfg.TenantLicensingEnforcement = "optional"
	cfg.RBACInternalToken = InternalToken
	cfg.TenantLicensingInternalToken = InternalToken
	cfg.TenantLicensingAdminToken = TLAdminToken
	cfg.RBACPIIEncryptionEnabled = false
	cfg.RBACPIIEncryptionKey = PIIKeyB64
	cfg.VersioningEnabled = true
	cfg.RBACRateLimitMax = 10000
	cfg.RBACRateLimitLoginMax = 10000
	cfg.RBACRateLimitRegisterMax = 10000
	cfg.RBACRateLimitAdminMax = 10000
	cfg.RBACRateLimitWindowSec = 60
	if cfg.GoDBHost == "" {
		cfg.GoDBHost = "127.0.0.1"
	}
	if cfg.GoDBPort == 0 {
		cfg.GoDBPort = 5433
	}
	if cfg.GoDBName == "" {
		cfg.GoDBName = "maniforge"
	}
	if cfg.GoDBUser == "" {
		cfg.GoDBUser = "maniforge"
	}
	if cfg.GoDBPass == "" {
		cfg.GoDBPass = "maniforge"
	}
	if cfg.GoDBSSLMode == "" {
		cfg.GoDBSSLMode = "disable"
	}
	return cfg
}

func UniquePhone(prefix string) string {
	n := time.Now().UnixNano() % 10000000
	if n < 0 {
		n = -n
	}
	return fmt.Sprintf("%s%07d", prefix, n)
}

func (c *Client) Do(method, path string, body any) (int, map[string]any, []byte) {
	c.T.Helper()
	var raw []byte
	if body != nil {
		var err error
		raw, err = json.Marshal(body)
		if err != nil {
			c.T.Fatalf("json: %v", err)
		}
	}
	req := httptest.NewRequest(method, path, bytes.NewReader(raw))
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("Accept", "application/json")
	if c.Auth && c.Session.Token != "" {
		req.Header.Set("Authorization", "Bearer "+c.Session.Token)
	}
	if c.Auth && c.Session.CSRF != "" {
		req.Header.Set("X-CSRF-Token", c.Session.CSRF)
	}
	if c.Action && c.Session.ActionToken != "" {
		req.Header.Set("X-Action-Token", c.Session.ActionToken)
	}
	if c.Tenant && c.Session.TenantID != "" {
		req.Header.Set("X-Tenant-ID", c.Session.TenantID)
		req.Header.Set("X-Subtenant-ID", c.Session.SubtenantID)
	}
	resp, err := c.App.Test(req, TestTimeoutMS)
	if err != nil {
		c.T.Fatalf("%s %s: %v", method, path, err)
	}
	defer resp.Body.Close()
	payload, _ := io.ReadAll(resp.Body)
	var out map[string]any
	if len(payload) > 0 && (payload[0] == '{' || payload[0] == '[') {
		_ = json.Unmarshal(payload, &out)
	}
	return resp.StatusCode, out, payload
}

func (c *Client) JSON(method, path string, body any) (int, map[string]any) {
	c.T.Helper()
	status, out, raw := c.Do(method, path, body)
	if out == nil {
		c.T.Fatalf("%s %s status %d: тело не JSON: %s", method, path, status, truncate(raw))
	}
	return status, out
}

func (c *Client) MustOK(method, path string, body any) map[string]any {
	c.T.Helper()
	status, out := c.JSON(method, path, body)
	if status < 200 || status >= 300 {
		c.T.Fatalf("%s %s: status %d body=%v", method, path, status, out)
	}
	if ok, _ := out["ok"].(bool); !ok {
		c.T.Fatalf("%s %s: ok=false body=%v", method, path, out)
	}
	return out
}

func (c *Client) MustStatus(method, path string, body any, want int) map[string]any {
	c.T.Helper()
	status, out := c.JSON(method, path, body)
	if status != want {
		c.T.Fatalf("%s %s: status %d want %d body=%v", method, path, status, want, out)
	}
	return out
}

func AsInt64(v any) int64 {
	switch t := v.(type) {
	case float64:
		return int64(t)
	case json.Number:
		n, _ := t.Int64()
		return n
	case int64:
		return t
	case int:
		return int64(t)
	case string:
		var n int64
		_, _ = fmt.Sscan(t, &n)
		return n
	default:
		return 0
	}
}

func Map(v any) map[string]any {
	m, _ := v.(map[string]any)
	return m
}

func Slice(v any) []any {
	s, _ := v.([]any)
	return s
}

func Nested(root map[string]any, keys ...string) any {
	var cur any = root
	for _, k := range keys {
		m := Map(cur)
		if m == nil {
			return nil
		}
		cur = m[k]
	}
	return cur
}

func CanonicalRoute(method, path string) string {
	method = strings.ToUpper(method)
	for _, prefix := range []string{"/rbac", "/manifest-engine", "/tenant-licensing", "/versioning", "/realtime", "/warehouses", "/products", "/inventory", "/wms"} {
		path = strings.TrimPrefix(path, prefix)
	}
	if path == "" {
		path = "/"
	}
	if len(path) > 1 {
		path = strings.TrimRight(path, "/")
	}
	return method + " " + path
}

func FiberRouteSet(app *fiber.App) map[string]struct{} {
	out := make(map[string]struct{})
	for _, r := range app.GetRoutes(true) {
		if r.Method == http.MethodHead || r.Method == http.MethodOptions {
			continue
		}
		out[CanonicalRoute(r.Method, r.Path)] = struct{}{}
	}
	return out
}

func AssertLiveRoutesExact(t *testing.T, app *fiber.App, live []string) {
	t.Helper()
	got := FiberRouteSet(app)
	want := map[string]struct{}{}
	for _, r := range live {
		want[r] = struct{}{}
		if _, ok := got[r]; !ok {
			t.Errorf("нет маршрута %s", r)
		}
	}
	for r := range got {
		if _, ok := want[r]; !ok {
			t.Errorf("лишний маршрут %s", r)
		}
	}
}

func ApplySession(t *testing.T, c *Client, login map[string]any) {
	t.Helper()
	sess := Map(login["session"])
	if sess == nil {
		sess = Map(Nested(login, "credentials", "session"))
	}
	c.Session.Token = fmt.Sprint(sess["access_token"])
	c.Session.CSRF = fmt.Sprint(sess["csrf_token"])
	c.Session.Refresh = fmt.Sprint(sess["refresh_token"])
	c.Session.UserID = AsInt64(sess["user_id"])
	if c.Session.Token == "" || c.Session.Token == "<nil>" {
		t.Fatalf("нет access_token: %v", login)
	}
	if c.Session.CSRF == "" || c.Session.CSRF == "<nil>" {
		c.Session.CSRF = fmt.Sprint(login["csrf_token"])
	}
}

func RegisterTenantAdmin(t *testing.T, rbacApp *fiber.App, phonePrefix, org string) *Client {
	t.Helper()
	password := "HttpApiCover!123"
	phone := UniquePhone(phonePrefix)
	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	c := &Client{T: t, App: rbacApp}
	reg := c.MustStatus("POST", "/rbac/api/v1/auth/register", map[string]any{
		"phone": phone, "password": password,
		"email":                 "cover_" + suffix + "@example.test",
		"organization_name":     org + " " + suffix,
		"platform_dpa_accepted": true,
		"consents":              []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, http.StatusCreated)
	tenant := Map(reg["tenant"])
	c.Session.TenantID = fmt.Sprint(tenant["tenant_id"])
	c.Session.SubtenantID = fmt.Sprint(tenant["subtenant_id"])
	if c.Session.SubtenantID == "" || c.Session.SubtenantID == "<nil>" {
		c.Session.SubtenantID = "main"
	}
	c.Session.Password = password
	c.Session.Phone = phone
	c.Tenant = true
	login := c.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password,
		"tenant_id": c.Session.TenantID, "subtenant_id": c.Session.SubtenantID,
	})
	ApplySession(t, c, login)
	c.Auth = true
	return c
}

func truncate(b []byte) string {
	s := string(b)
	if len(s) > 400 {
		return s[:400] + "…"
	}
	return s
}
