// Файл: main.go (cmd/enterprise-journey)
// Назначение: journey enterprise security — lockout, TOTP MFA, policy require_mfa.
// См. также: cmd/mfa-journey, docs/MANIFORGE_ENTERPRISE_HARDENING.md
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"

	"github.com/pquerna/otp/totp"
)

func main() {
	rbac := env("JOURNEY_RBAC_URL", "http://127.0.0.1:8093/rbac")
	password := env("ENT_JOURNEY_PASSWORD", "EntJourney!12345")
	maxFails := int(envInt("RBAC_LOGIN_MAX_FAILS", 5))
	failed := 0
	ok := func(name string, cond bool) {
		if cond {
			fmt.Printf("[OK] %s\n", name)
		} else {
			failed++
			fmt.Printf("[FAIL] %s\n", name)
		}
	}

	suffix := fmt.Sprintf("%x", time.Now().UnixNano()%0xffffff)
	phone := env("ENT_JOURNEY_PHONE", fmt.Sprintf("+7930%07d", time.Now().Unix()%10000000))

	reg, err := postJSON(rbac+"/api/v1/auth/register", map[string]any{
		"password": password, "phone": phone,
		"organization_name": "Enterprise Journey " + suffix,
		"consents":          []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, "")
	if err != nil {
		panic(err)
	}
	ok("Register tenant", reg["ok"] == true)
	t, _ := reg["tenant"].(map[string]any)
	tenantID, _ := t["tenant_id"].(string)
	subtenantID, _ := t["subtenant_id"].(string)

	// Lockout: неверный пароль maxFails раз → 429
	for i := 0; i < maxFails; i++ {
		bad, status, _ := postJSONStatus(rbac+"/api/v1/auth/login", map[string]any{
			"phone": phone, "password": "wrong-password", "tenant_id": tenantID, "subtenant_id": subtenantID,
		}, "")
		_ = bad
		if i < maxFails-1 {
			ok(fmt.Sprintf("Failed login attempt %d → 401", i+1), status == 401)
		}
	}
	locked, status, _ := postJSONStatus(rbac+"/api/v1/auth/login", map[string]any{
		"phone": phone, "password": "wrong-password", "tenant_id": tenantID, "subtenant_id": subtenantID,
	}, "")
	ok("Login lockout after max fails → 429", status == 429)
	_, hasLock := locked["locked_until"]
	ok("Lockout payload has locked_until", hasLock)

	// Успешный login после lockout с правильным паролем — всё ещё 429 пока lock активен
	_, status2, _ := postJSONStatus(rbac+"/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password, "tenant_id": tenantID, "subtenant_id": subtenantID,
	}, "")
	ok("Correct password still blocked during lockout", status2 == 429)

	// Второй tenant для MFA (без lockout)
	phone2 := fmt.Sprintf("+7931%07d", time.Now().Unix()%10000000)
	reg2, _ := postJSON(rbac+"/api/v1/auth/register", map[string]any{
		"password": password, "phone": phone2,
		"organization_name": "MFA Journey " + suffix,
		"consents":          []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, "")
	t2, _ := reg2["tenant"].(map[string]any)
	tenant2, _ := t2["tenant_id"].(string)
	sub2, _ := t2["subtenant_id"].(string)

	login, _ := postJSON(rbac+"/api/v1/auth/login", map[string]any{
		"phone": phone2, "password": password, "tenant_id": tenant2, "subtenant_id": sub2,
	}, "")
	ok("Login MFA tenant", login["ok"] == true)
	sess := sessionFrom(login)
	token, _ := sess["access_token"].(string)
	csrf, _ := login["csrf_token"].(string)
	authH := map[string]string{"Authorization": "Bearer " + token, "X-CSRF-Token": csrf}

	reauth, _ := postJSONHeaders(rbac+"/api/v1/auth/reauth", map[string]any{"password": password}, authH)
	actionToken := actionTokenFrom(reauth)
	if actionToken != "" {
		authH["X-Action-Token"] = actionToken
	}

	// Включить require_mfa_enrollment
	pol, statusPol, _ := postJSONStatusHeaders(rbac+"/api/v1/admin/policies", map[string]any{
		"reason": "enterprise journey", "require_mfa_enrollment": true,
		"allowed_ips": []string{}, "allowed_hour_start_utc": 0, "allowed_hour_end_utc": 23,
	}, authH)
	ok("Set require_mfa_enrollment policy", pol["ok"] == true && statusPol == 200)

	login2, _ := postJSON(rbac+"/api/v1/auth/login", map[string]any{
		"phone": phone2, "password": password, "tenant_id": tenant2, "subtenant_id": sub2,
	}, "")
	ok("Login hints mfa_enrollment_required", login2["mfa_enrollment_required"] == true)

	_, statusAdmin, _ := postJSONStatusHeaders(rbac+"/api/v1/admin/users", map[string]any{
		"login": "blocked-" + suffix, "password": password,
		"phone": fmt.Sprintf("+7941%07d", time.Now().UnixNano()%10000000),
	}, authH)
	ok("Admin create blocked without TOTP enroll", statusAdmin == 403)

	_, statusProject, _ := postJSONStatusHeaders(rbac+"/api/v1/projects", map[string]any{
		"name": "blocked-" + suffix,
	}, authH)
	ok("Project create blocked without TOTP enroll", statusProject == 403)

	enroll, statusEnroll, _ := postJSONStatusHeaders(rbac+"/api/v1/me/mfa/enroll", map[string]any{
		"label": "Journey",
	}, authH)
	if statusEnroll == 503 {
		fmt.Println("[WARN] MFA enroll skipped: RBAC_PII_ENCRYPTION_KEY not configured on server")
	} else {
		ok("MFA enroll", enroll["ok"] == true && enroll["secret"] != nil)
		secret, _ := enroll["secret"].(string)
		code, _ := totp.GenerateCode(secret, time.Now())
		verify, _ := postJSONHeaders(rbac+"/api/v1/me/mfa/verify", map[string]any{"code": code}, authH)
		ok("MFA verify enroll", verify["ok"] == true)
		codes, _ := verify["recovery_codes"].([]any)
		ok("Recovery codes issued", len(codes) == 10)

		totpCode, _ := totp.GenerateCode(secret, time.Now())
		reauthTotp, _ := postJSONHeaders(rbac+"/api/v1/auth/reauth", map[string]any{"totp_code": totpCode}, authH)
		ok("Reauth with TOTP", reauthTotp["ok"] == true)
		if at := actionTokenFrom(reauthTotp); at != "" {
			authH["X-Action-Token"] = at
		}
		_, statusAdmin2, _ := postJSONStatusHeaders(rbac+"/api/v1/admin/users", map[string]any{
			"login": "ok-" + suffix, "password": password,
			"phone": fmt.Sprintf("+7951%07d", time.Now().UnixNano()%10000000),
			"reason": "enterprise journey",
		}, authH)
		ok("Admin create allowed after TOTP enroll", statusAdmin2 == 201 || statusAdmin2 == 200)
		_, statusProject2, _ := postJSONStatusHeaders(rbac+"/api/v1/projects", map[string]any{
			"code": "proj-" + suffix, "name": "ok-" + suffix,
		}, authH)
		ok("Project create allowed after TOTP enroll", statusProject2 == 201 || statusProject2 == 200)
	}

	fmt.Printf("\nSummary: failed=%d\n", failed)
	if failed > 0 {
		os.Exit(1)
	}
}

func env(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func envInt(k string, def int) int {
	if v := os.Getenv(k); v != "" {
		var n int
		fmt.Sscanf(v, "%d", &n)
		if n > 0 {
			return n
		}
	}
	return def
}

func sessionFrom(login map[string]any) map[string]any {
	if s, ok := login["session"].(map[string]any); ok {
		return s
	}
	if creds, ok := login["credentials"].(map[string]any); ok {
		if s, ok := creds["session"].(map[string]any); ok {
			return s
		}
	}
	return map[string]any{}
}

func actionTokenFrom(reauth map[string]any) string {
	if creds, ok := reauth["credentials"].(map[string]any); ok {
		if action, ok := creds["action"].(map[string]any); ok {
			t, _ := action["action_token"].(string)
			return t
		}
	}
	return ""
}

func postJSON(url string, body any, auth string) (map[string]any, error) {
	m, _, err := postJSONStatus(url, body, auth)
	return m, err
}

func postJSONHeaders(url string, body any, headers map[string]string) (map[string]any, error) {
	m, _, err := postJSONStatusHeaders(url, body, headers)
	return m, err
}

func postJSONStatus(url string, body any, auth string) (map[string]any, int, error) {
	h := map[string]string{}
	if auth != "" {
		h["Authorization"] = auth
	}
	return postJSONStatusHeaders(url, body, h)
}

func postJSONStatusHeaders(url string, body any, headers map[string]string) (map[string]any, int, error) {
	b, _ := json.Marshal(body)
	req, _ := http.NewRequest(http.MethodPost, url, bytes.NewReader(b))
	req.Header.Set("Content-Type", "application/json")
	for k, v := range headers {
		if v != "" {
			req.Header.Set(k, v)
		}
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	var out map[string]any
	_ = json.Unmarshal(raw, &out)
	return out, resp.StatusCode, nil
}
