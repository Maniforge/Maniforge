// Файл: main.go (cmd/platform-ops-journey)
// Назначение: Go smoke journey platform ops (TL suspend/reactivate + internal access-state).
// Заменяет PHP platform_ops_journey.php для production box (без maniforge/ PHP).
package main

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"

	"maniforge/internal/config"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		fmt.Fprintf(os.Stderr, "config: %v\n", err)
		os.Exit(2)
	}

	rbacBase := env("JOURNEY_BASE_URL", cfg.AppURL+"/rbac")
	tlBase := env("JOURNEY_TL_URL", cfg.AppURL+"/tenant-licensing")
	tlAdmin := cfg.TenantLicensingAdminToken
	tlInternal := cfg.TenantLicensingInternalToken
	rbacInternal := cfg.RBACInternalTokenEffective()

	fmt.Printf("[INFO] RBAC base: %s\n", rbacBase)
	fmt.Printf("[INFO] TL base: %s\n", tlBase)

	if tlAdmin == "" || tlInternal == "" || rbacInternal == "" {
		fmt.Fprintln(os.Stderr, "TENANT_LICENSING_ADMIN_TOKEN, TENANT_LICENSING_INTERNAL_TOKEN, RBAC_INTERNAL_TOKEN required")
		os.Exit(2)
	}
	if !cfg.RBACRegistrationEnabled {
		fmt.Fprintln(os.Stderr, "RBAC_REGISTRATION_ENABLED must be true for platform-ops journey")
		os.Exit(2)
	}

	suffix := randomSuffix(4)
	phone := fmt.Sprintf("+7903%07d", os.Getpid()%10000000)
	password := "PlatformOpsJourney!123"
	adminLogin := "ops_admin_" + suffix

	var failed int
	step := func(name string, fn func() error) {
		if err := fn(); err != nil {
			failed++
			fmt.Printf("[FAIL] %s: %v\n", name, err)
		} else {
			fmt.Printf("[OK] %s\n", name)
		}
	}

	var tenantID, subtenantID string
	tenantHeaders := func() map[string]string {
		h := map[string]string{}
		if tenantID != "" {
			h["X-Tenant-ID"] = tenantID
			h["X-Subtenant-ID"] = subtenantID
		}
		return h
	}

	step("Register tenant for platform ops", func() error {
		body := map[string]any{
			"password": password,
			"phone":    phone,
			"email":    adminLogin + "@example.test",
			"organization_name": "Platform Ops Org " + suffix,
			"consents": []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
		}
		resp, status, err := httpJSON(http.MethodPost, rbacBase+"/api/v1/auth/register", body, nil, nil)
		if err != nil {
			return err
		}
		if status != 201 {
			return fmt.Errorf("status %d: %v", status, resp["error"])
		}
		tenant, _ := resp["tenant"].(map[string]any)
		tenantID, _ = tenant["tenant_id"].(string)
		subtenantID, _ = tenant["subtenant_id"].(string)
		if tenantID == "" {
			return fmt.Errorf("empty tenant_id")
		}
		if subtenantID == "" {
			subtenantID = "main"
		}
		return nil
	})

	step("Login while tenant active", func() error {
		body := map[string]any{"phone": phone, "password": password}
		resp, status, err := httpJSON(http.MethodPost, rbacBase+"/api/v1/auth/login", body, tenantHeaders(), nil)
		if err != nil {
			return err
		}
		if status != 200 || resp["ok"] != true {
			return fmt.Errorf("status %d: %v", status, resp["error"])
		}
		return nil
	})

	step("Access-state active", func() error {
		url := fmt.Sprintf("%s/internal/v1/tenants/%s/subtenants/%s/access-state",
			tlBase, esc(tenantID), esc(subtenantID))
		resp, status, err := httpJSON(http.MethodGet, url, nil, nil, bearer(tlInternal))
		if err != nil {
			return err
		}
		if status != 200 {
			return fmt.Errorf("status %d", status)
		}
		if resp["license_active"] != true {
			return fmt.Errorf("license_active=false: %+v", resp)
		}
		return nil
	})

	step("Suspend subtenant via TL API", func() error {
		url := fmt.Sprintf("%s/api/v1/tenants/%s/subtenants/%s", tlBase, esc(tenantID), esc(subtenantID))
		body := map[string]any{"name": "Main", "status": "suspended"}
		resp, status, err := httpJSON(http.MethodPatch, url, body, nil, bearer(tlAdmin))
		if err != nil {
			return err
		}
		if status != 200 || resp["ok"] != true {
			return fmt.Errorf("status %d: %v", status, resp["error"])
		}
		return nil
	})

	step("Login denied when subtenant suspended", func() error {
		body := map[string]any{"phone": phone, "password": password}
		_, status, err := httpJSON(http.MethodPost, rbacBase+"/api/v1/auth/login", body, tenantHeaders(), nil)
		if err != nil {
			return err
		}
		if status != 403 {
			return fmt.Errorf("expected 403, got %d", status)
		}
		return nil
	})

	step("Access-state subtenant inactive", func() error {
		url := fmt.Sprintf("%s/internal/v1/tenants/%s/subtenants/%s/access-state",
			tlBase, esc(tenantID), esc(subtenantID))
		resp, status, err := httpJSON(http.MethodGet, url, nil, nil, bearer(tlInternal))
		if err != nil {
			return err
		}
		if status != 200 || resp["ok"] != true {
			return fmt.Errorf("status %d", status)
		}
		if resp["subtenant_active"] != false {
			return fmt.Errorf("subtenant_active=%v", resp["subtenant_active"])
		}
		return nil
	})

	step("Reactivate subtenant", func() error {
		url := fmt.Sprintf("%s/api/v1/tenants/%s/subtenants/%s", tlBase, esc(tenantID), esc(subtenantID))
		body := map[string]any{"name": "Main", "status": "active"}
		resp, status, err := httpJSON(http.MethodPatch, url, body, nil, bearer(tlAdmin))
		if err != nil {
			return err
		}
		if status != 200 || resp["ok"] != true {
			return fmt.Errorf("status %d", status)
		}
		return nil
	})

	step("Suspend tenant via TL API", func() error {
		url := fmt.Sprintf("%s/api/v1/tenants/%s", tlBase, esc(tenantID))
		body := map[string]any{"name": "Platform Ops Org " + suffix, "status": "suspended"}
		resp, status, err := httpJSON(http.MethodPatch, url, body, nil, bearer(tlAdmin))
		if err != nil {
			return err
		}
		if status != 200 || resp["ok"] != true {
			return fmt.Errorf("status %d", status)
		}
		return nil
	})

	step("Login denied when tenant suspended", func() error {
		body := map[string]any{"phone": phone, "password": password}
		_, status, err := httpJSON(http.MethodPost, rbacBase+"/api/v1/auth/login", body, tenantHeaders(), nil)
		if err != nil {
			return err
		}
		if status != 403 {
			return fmt.Errorf("expected 403, got %d", status)
		}
		return nil
	})

	step("List TL lifecycle events", func() error {
		url := fmt.Sprintf("%s/api/v1/events?tenant_code=%s&limit=20", tlBase, esc(tenantID))
		resp, status, err := httpJSON(http.MethodGet, url, nil, nil, bearer(tlAdmin))
		if err != nil {
			return err
		}
		if status != 200 {
			return fmt.Errorf("status %d", status)
		}
		items, _ := resp["items"].([]any)
		if len(items) < 1 {
			return fmt.Errorf("no events recorded")
		}
		return nil
	})

	step("POST internal tenant-events", func() error {
		body := map[string]any{
			"event_type":     "tenant.suspended",
			"tenant_code":    tenantID,
			"subtenant_code": subtenantID,
			"payload":        map[string]any{"source": "platform_ops_journey"},
		}
		resp, status, err := httpJSON(http.MethodPost, rbacBase+"/internal/v1/tenant-events", body, nil, bearer(rbacInternal))
		if err != nil {
			return err
		}
		if status != 200 || resp["ok"] != true {
			return fmt.Errorf("status %d: %v", status, resp["error"])
		}
		return nil
	})

	step("Reactivate tenant for cleanup", func() error {
		url := fmt.Sprintf("%s/api/v1/tenants/%s", tlBase, esc(tenantID))
		body := map[string]any{"name": "Platform Ops Org " + suffix, "status": "active"}
		resp, status, err := httpJSON(http.MethodPatch, url, body, nil, bearer(tlAdmin))
		if err != nil {
			return err
		}
		if status != 200 || resp["ok"] != true {
			return fmt.Errorf("status %d", status)
		}
		return nil
	})

	step("Login works after tenant reactivated", func() error {
		body := map[string]any{"phone": phone, "password": password}
		resp, status, err := httpJSON(http.MethodPost, rbacBase+"/api/v1/auth/login", body, tenantHeaders(), nil)
		if err != nil {
			return err
		}
		if status != 200 || resp["ok"] != true {
			return fmt.Errorf("status %d: %v", status, resp["error"])
		}
		return nil
	})

	fmt.Printf("\nPlatform ops journey: failed=%d\n", failed)
	if tenantID != "" {
		fmt.Printf("Test tenant: %s\n", tenantID)
	}
	if failed > 0 {
		os.Exit(1)
	}
}

func env(k, def string) string {
	if v := strings.TrimSpace(os.Getenv(k)); v != "" {
		return strings.TrimRight(v, "/")
	}
	return strings.TrimRight(def, "/")
}

func bearer(token string) map[string]string {
	return map[string]string{"Authorization": "Bearer " + token}
}

func esc(s string) string {
	return strings.ReplaceAll(s, " ", "%20")
}

func randomSuffix(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func httpJSON(method, url string, body any, headers, auth map[string]string) (map[string]any, int, error) {
	var r io.Reader
	if body != nil {
		raw, _ := json.Marshal(body)
		r = bytes.NewReader(raw)
	}
	req, err := http.NewRequest(method, url, r)
	if err != nil {
		return nil, 0, err
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	for k, v := range auth {
		req.Header.Set(k, v)
	}
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer res.Body.Close()
	raw, _ := io.ReadAll(res.Body)
	var out map[string]any
	if len(raw) > 0 {
		_ = json.Unmarshal(raw, &out)
	}
	if out == nil {
		out = map[string]any{}
	}
	return out, res.StatusCode, nil
}
