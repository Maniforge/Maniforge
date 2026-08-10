// Файл: main.go (cmd/manifest-client-test-seed)
// Назначение: тестовый seed custom manifest клиента через Manifest Engine HTTP API.
// Зависимости: RBAC :8093, Manifest Engine :8095; сессия клиента после register/login.
// См. также: docs/MANIFORGE_MANIFEST_ENGINE.md, docs/adr/0009-control-data-plane-manifest-origin.md
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

const (
	manifestCode       = "invoice"
	defaultOrgName     = "Manifest Client Test"
	credentialsRelPath = "var/manifest-client-test.json"
)

type demoEntity struct {
	Code     string
	Name     string
	Type     string
	Section  string
	Fields   []map[string]any
	Samples  []map[string]any
}

var demoEntities = []demoEntity{
	{
		Code:    manifestCode,
		Name:    "Счёт (client test)",
		Type:    "finance",
		Section: "sales",
		Fields: []map[string]any{
			{"name": "number", "type": "string", "required": true, "max_length": 32},
			{"name": "amount", "type": "number", "required": true, "min": 0},
			{"name": "currency", "type": "string", "max_length": 3},
			{"name": "status", "type": "string", "max_length": 20},
			{"name": "paid", "type": "boolean"},
			{"name": "due_date", "type": "string", "max_length": 10},
			{"name": "notes", "type": "string", "max_length": 500},
		},
		Samples: []map[string]any{
			{
				"number": "INV-TEST-001", "amount": 15000, "currency": "RUB",
				"status": "draft", "paid": false, "due_date": "2026-07-01",
				"notes": "Тестовый custom manifest (клиент → Manifest Engine)",
			},
			{
				"number": "INV-TEST-002", "amount": 4200.5, "currency": "RUB",
				"status": "sent", "paid": true, "due_date": "2026-06-15",
				"notes": "Realtime: data.invoice, entity.custom",
			},
		},
	},
	{
		Code:    "contact",
		Name:    "Контакт (client test)",
		Type:    "crm",
		Section: "customers",
		Fields: []map[string]any{
			{"name": "full_name", "type": "string", "required": true, "max_length": 120},
			{"name": "email", "type": "string", "max_length": 120},
			{"name": "phone", "type": "string", "max_length": 20},
			{"name": "company", "type": "string", "max_length": 120},
			{"name": "active", "type": "boolean"},
			{"name": "notes", "type": "string", "max_length": 500},
		},
		Samples: []map[string]any{
			{
				"full_name": "Иван Петров",
				"email":     "ivan@example.com",
				"phone":     "+79001234567",
				"company":   "ООО Ромашка",
				"active":    true,
				"notes":     "Визуальный тест: тип crm",
			},
		},
	},
}

type clientCredentials struct {
	Phone         string `json:"phone"`
	Password      string `json:"password"`
	TenantID      string `json:"tenant_id"`
	SubtenantID   string `json:"subtenant_id"`
	Organization  string `json:"organization_name"`
	ManifestCode  string `json:"manifest_code"`
	ManifestOrigin string `json:"manifest_origin,omitempty"`
}

func main() {
	repoRoot := env("MANIFORGE_REPO_ROOT", ".")
	credPath := filepath.Join(repoRoot, credentialsRelPath)

	rbacBase := strings.TrimRight(env("MANIFEST_CLIENT_TEST_RBAC_URL", "http://127.0.0.1:8093/rbac"), "/")
	meBase := strings.TrimRight(env("MANIFEST_CLIENT_TEST_ME_URL", "http://127.0.0.1:8095"), "/")

	creds := loadOrDefaultCredentials(credPath)
	token, err := clientSession(rbacBase, &creds)
	if err != nil {
		log.Fatalf("client session: %v (нужны make run-rbac run-manifest)", err)
	}
	if err := saveCredentials(credPath, creds); err != nil {
		log.Fatalf("save credentials: %v", err)
	}

	totalRecords := 0
	for _, entity := range demoEntities {
		origin, err := ensureDemoManifest(meBase, token, entity)
		if err != nil {
			log.Fatalf("manifest %s: %v", entity.Code, err)
		}
		if entity.Code == manifestCode {
			creds.ManifestCode = manifestCode
			creds.ManifestOrigin = origin
			_ = saveCredentials(credPath, creds)
		}
		n, err := ensureSampleRecords(meBase, token, entity)
		if err != nil {
			log.Fatalf("records %s: %v", entity.Code, err)
		}
		totalRecords += n
	}

	if err := verifyCustomFilter(meBase, token); err != nil {
		log.Fatalf("verify: %v", err)
	}

	fmt.Printf("manifest client test seed ok\n")
	fmt.Printf("  entities=%d tenant=%s/%s records_created=%d\n",
		len(demoEntities), creds.TenantID, creds.SubtenantID, totalRecords)
	fmt.Printf("  credentials_file=%s\n", credPath)
	fmt.Printf("  phone=%s password=%s\n", creds.Phone, creds.Password)
	fmt.Printf("  login: POST %s/api/v1/auth/login\n", rbacBase)
	fmt.Printf("  custom API: POST %s/api/v1/manifests (клиент, origin=custom)\n", meBase)
	fmt.Printf("  data API: GET %s/api/data/%s\n", meBase, manifestCode)
	fmt.Printf("  docs: /api → Персональные (вкладки по section, make manifest-openapi-export-live)\n")
	fmt.Printf("  live: /api#personal-live-openapi\n")
}

func loadOrDefaultCredentials(path string) clientCredentials {
	def := clientCredentials{
		Phone:        env("MANIFEST_CLIENT_TEST_PHONE", "+79831073007"),
		Password:     env("MANIFEST_CLIENT_TEST_PASSWORD", "ManifestClientTest!12345"),
		TenantID:     env("MANIFEST_CLIENT_TEST_TENANT", ""),
		SubtenantID:  env("MANIFEST_CLIENT_TEST_SUBTENANT", "main"),
		Organization: env("MANIFEST_CLIENT_TEST_ORG", defaultOrgName),
		ManifestCode: manifestCode,
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return def
	}
	var saved clientCredentials
	if err := json.Unmarshal(raw, &saved); err != nil {
		log.Printf("ignore credentials file: %v", err)
		return def
	}
	if saved.Phone != "" {
		def.Phone = saved.Phone
	}
	if saved.Password != "" {
		def.Password = saved.Password
	}
	if saved.TenantID != "" {
		def.TenantID = saved.TenantID
	}
	if saved.SubtenantID != "" {
		def.SubtenantID = saved.SubtenantID
	}
	if saved.Organization != "" {
		def.Organization = saved.Organization
	}
	return def
}

func saveCredentials(path string, creds clientCredentials) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	raw, err := json.MarshalIndent(creds, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, raw, 0o600)
}

func clientSession(rbacBase string, creds *clientCredentials) (string, error) {
	if creds.TenantID != "" && creds.SubtenantID != "" {
		token, err := login(rbacBase, creds.Phone, creds.Password, creds.TenantID, creds.SubtenantID)
		if err == nil {
			log.Printf("client login ok: %s/%s", creds.TenantID, creds.SubtenantID)
			return token, nil
		}
		log.Printf("login %s/%s: %v", creds.TenantID, creds.SubtenantID, err)
	}

	reg, err := postJSON(rbacBase+"/api/v1/auth/register", map[string]any{
		"phone":                 creds.Phone,
		"password":              creds.Password,
		"organization_name":     creds.Organization,
		"platform_dpa_accepted": true,
		"consents": []map[string]string{
			{"purpose_code": "account", "policy_version": "1.0"},
		},
	}, "")
	if err != nil {
		return "", err
	}
	if ok, _ := reg["ok"].(bool); !ok {
		// Уже зарегистрирован — login без tenant (auto-scope) или с сохранённым tenant.
		token, err := login(rbacBase, creds.Phone, creds.Password, creds.TenantID, creds.SubtenantID)
		if err != nil {
			return "", fmt.Errorf("register: %v; login: %w", reg["error"], err)
		}
		scope := sessionScope(token, rbacBase, creds)
		if scope != "" {
			log.Printf("client login (existing phone): %s", scope)
		}
		return token, nil
	}

	t, _ := reg["tenant"].(map[string]any)
	creds.TenantID, _ = t["tenant_id"].(string)
	creds.SubtenantID, _ = t["subtenant_id"].(string)
	if creds.SubtenantID == "" {
		creds.SubtenantID = "main"
	}

	token, err := login(rbacBase, creds.Phone, creds.Password, creds.TenantID, creds.SubtenantID)
	if err != nil {
		return "", err
	}
	log.Printf("client registered: %s/%s (tenant_admin → POST /manifests)", creds.TenantID, creds.SubtenantID)
	return token, nil
}

func sessionScope(token, rbacBase string, creds *clientCredentials) string {
	me, err := getJSON(rbacBase+"/api/v1/me", token)
	if err != nil {
		return ""
	}
	if ok, _ := me["ok"].(bool); !ok {
		return ""
	}
	sess, _ := me["session"].(map[string]any)
	if tid, _ := sess["tenant_id"].(string); tid != "" && creds.TenantID == "" {
		creds.TenantID = tid
	}
	if sid, _ := sess["subtenant_id"].(string); sid != "" && creds.SubtenantID == "" {
		creds.SubtenantID = sid
	}
	return fmt.Sprintf("%s/%s", creds.TenantID, creds.SubtenantID)
}

func login(rbacBase, phone, password, tenantID, subtenantID string) (string, error) {
	body := map[string]any{"phone": phone, "password": password}
	if tenantID != "" {
		body["tenant_id"] = tenantID
	}
	if subtenantID != "" {
		body["subtenant_id"] = subtenantID
	}
	resp, err := postJSON(rbacBase+"/api/v1/auth/login", body, "")
	if err != nil {
		return "", err
	}
	if ok, _ := resp["ok"].(bool); !ok {
		return "", fmt.Errorf("%v", resp["error"])
	}
	return extractAccessToken(resp), nil
}

func extractAccessToken(resp map[string]any) string {
	if sess, _ := resp["session"].(map[string]any); sess != nil {
		if t, _ := sess["access_token"].(string); t != "" {
			return t
		}
	}
	if cred, _ := resp["credentials"].(map[string]any); cred != nil {
		if sess, _ := cred["session"].(map[string]any); sess != nil {
			if t, _ := sess["access_token"].(string); t != "" {
				return t
			}
		}
	}
	return ""
}

func ensureDemoManifest(meBase, token string, entity demoEntity) (string, error) {
	wantType := entity.Type
	if entity.Code == manifestCode {
		if override := env("MANIFEST_CLIENT_TEST_TYPE", ""); override != "" {
			wantType = override
		}
	}

	existing, err := getJSON(meBase+"/api/v1/manifests/"+entity.Code, token)
	if err != nil {
		return "", err
	}
	if ok, _ := existing["ok"].(bool); ok {
		origin, _ := manifestField(existing, "origin").(string)
		if origin != "custom" {
			return "", fmt.Errorf("manifest %s уже есть с origin=%s (ожидался custom)", entity.Code, origin)
		}
		wantSection := entity.Section
		curType, _ := manifestField(existing, "type").(string)
		curSection, _ := manifestField(existing, "section").(string)
		if (wantType != "" && curType != wantType) || (wantSection != "" && curSection != wantSection) {
			patchBody := map[string]any{
				"name":    manifestField(existing, "name"),
				"fields":  manifestField(existing, "fields"),
				"type":    wantType,
				"section": wantSection,
			}
			if _, err := patchJSON(meBase+"/api/v1/manifests/"+entity.Code, patchBody, token); err != nil {
				return "", fmt.Errorf("PATCH catalog: %w", err)
			}
			log.Printf("updated manifest %s type=%s section=%s", entity.Code, wantType, wantSection)
		}
		log.Printf("skip POST /manifests — %s уже есть (origin=custom, type=%s, section=%s)", entity.Code, wantType, wantSection)
		return origin, nil
	}

	body := map[string]any{
		"code":    entity.Code,
		"name":    entity.Name,
		"type":    wantType,
		"section": entity.Section,
		"fields":  entity.Fields,
	}
	resp, err := postJSON(meBase+"/api/v1/manifests", body, token)
	if err != nil {
		return "", err
	}
	if ok, _ := resp["ok"].(bool); !ok {
		return "", fmt.Errorf("POST /manifests: %v", resp["error"])
	}
	origin, _ := manifestField(resp, "origin").(string)
	if origin != "custom" {
		return "", fmt.Errorf("ожидался origin=custom, получен %q", origin)
	}
	log.Printf("created custom manifest %s type=%s section=%s", entity.Code, wantType, entity.Section)
	return origin, nil
}

func ensureSampleRecords(meBase, token string, entity demoEntity) (int, error) {
	list, err := getJSON(meBase+"/api/data/"+entity.Code+"?limit=10", token)
	if err != nil {
		return 0, err
	}
	if ok, _ := list["ok"].(bool); ok {
		if recs, _ := list["records"].([]any); len(recs) > 0 {
			log.Printf("skip sample records (%d already in %s)", len(recs), entity.Code)
			return 0, nil
		}
	}

	created := 0
	for _, sample := range entity.Samples {
		resp, err := postJSON(meBase+"/api/data/"+entity.Code, sample, token)
		if err != nil {
			return created, err
		}
		if ok, _ := resp["ok"].(bool); !ok {
			return created, fmt.Errorf("POST /api/data/%s: %v", entity.Code, resp["error"])
		}
		created++
	}
	log.Printf("created %d records via /api/data/%s", created, entity.Code)
	return created, nil
}

func verifyCustomFilter(meBase, token string) error {
	resp, err := getJSON(meBase+"/api/v1/manifests?origin=custom", token)
	if err != nil {
		return err
	}
	if ok, _ := resp["ok"].(bool); !ok {
		return fmt.Errorf("GET ?origin=custom: %v", resp["error"])
	}
	items, _ := resp["manifests"].([]any)
	for _, entity := range demoEntities {
		found := false
		for _, item := range items {
			m, _ := item.(map[string]any)
			if m["code"] == entity.Code && m["origin"] == "custom" {
				found = true
				break
			}
		}
		if !found {
			return fmt.Errorf("%s не найден в GET /manifests?origin=custom", entity.Code)
		}
		log.Printf("verified custom manifest %s (type=%s, section=%s)", entity.Code, entity.Type, entity.Section)
	}
	return nil
}

func manifestField(resp map[string]any, key string) any {
	m, _ := resp["manifest"].(map[string]any)
	if m == nil {
		return nil
	}
	return m[key]
}

func env(k, def string) string {
	if v := strings.TrimSpace(os.Getenv(k)); v != "" {
		return v
	}
	return def
}

func postJSON(url string, body any, token string) (map[string]any, error) {
	return doJSON(http.MethodPost, url, body, token)
}

func patchJSON(url string, body any, token string) (map[string]any, error) {
	return doJSON(http.MethodPatch, url, body, token)
}

func getJSON(url, token string) (map[string]any, error) {
	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	return decodeBody(res.Body)
}

func doJSON(method, url string, body any, token string) (map[string]any, error) {
	raw, _ := json.Marshal(body)
	req, err := http.NewRequest(method, url, bytes.NewReader(raw))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	return decodeBody(res.Body)
}

func decodeBody(r io.Reader) (map[string]any, error) {
	var out map[string]any
	if err := json.NewDecoder(r).Decode(&out); err != nil {
		return nil, err
	}
	return out, nil
}
