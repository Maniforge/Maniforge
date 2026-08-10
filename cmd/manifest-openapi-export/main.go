// Файл: main.go (cmd/manifest-openapi-export)
// Назначение: экспорт OpenAPI custom manifest для страницы /api (generated/*.openapi.json).
// Зависимости: model.OpenAPISpec; опционально HTTP Manifest Engine + RBAC.
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

	"maniforge/internal/manifestengine/model"
)

const defaultCode = "invoice"

func main() {
	repoRoot := env("MANIFORGE_REPO_ROOT", ".")
	outDir := filepath.Join(repoRoot, "templates/data/api-docs/generated")
	live := env("MANIFEST_OPENAPI_EXPORT_LIVE", "") == "1"
	exportAll := env("MANIFEST_OPENAPI_EXPORT_ALL", "") == "1"

	if err := os.MkdirAll(outDir, 0o755); err != nil {
		log.Fatalf("mkdir: %v", err)
	}

	if live && exportAll {
		codes, err := listLiveCustomManifests()
		if err != nil {
			log.Fatalf("list manifests: %v", err)
		}
		if len(codes) == 0 {
			log.Fatal("нет custom manifest для экспорта")
		}
		for _, code := range codes {
			if err := exportOne(outDir, code, true); err != nil {
				log.Fatalf("export %s: %v", code, err)
			}
		}
		fmt.Printf("manifest openapi export ok: %d file(s) in %s\n", len(codes), outDir)
		return
	}

	code := env("MANIFEST_OPENAPI_EXPORT_CODE", defaultCode)
	if err := exportOne(outDir, code, live); err != nil {
		log.Fatalf("export: %v", err)
	}
}

func exportOne(outDir, code string, live bool) error {
	var spec map[string]any
	var manifestFields []any
	manifestType := env("MANIFEST_OPENAPI_EXPORT_TYPE", "finance")
	manifestSection := "general"
	manifestName := code
	var err error
	if live {
		spec, manifestName, manifestType, manifestSection, manifestFields, err = fetchLiveOpenAPI(code)
		if err != nil {
			return err
		}
		log.Printf("live openapi fetched for %s name=%q type=%s section=%s fields=%d", code, manifestName, manifestType, manifestSection, len(manifestFields))
	} else {
		demo := demoManifest(code)
		spec = model.OpenAPISpec(demo, "http://127.0.0.1:8095/api/data")
		manifestFields = fieldDefsToExport(demo.Fields)
		switch code {
		case "contact":
			manifestType = "crm"
			manifestSection = "customers"
			manifestName = "Контакт (client test)"
		case defaultCode:
			manifestType = "finance"
			manifestSection = "sales"
			manifestName = "Счёт (client test)"
		}
		log.Printf("demo openapi generated for %s type=%s section=%s fields=%d", code, manifestType, manifestSection, len(manifestFields))
	}

	outPath := filepath.Join(outDir, code+".openapi.json")
	payload := map[string]any{
		"manifest_code":    code,
		"manifest_name":    manifestName,
		"manifest_type":    manifestType,
		"manifest_section": manifestSection,
		"manifest_fields":  manifestFields,
		"openapi":          spec,
	}
	raw, err := json.MarshalIndent(payload, "", "  ")
	if err != nil {
		return err
	}
	if err := os.WriteFile(outPath, raw, 0o644); err != nil {
		return err
	}
	fmt.Printf("manifest openapi export ok: %s\n", outPath)
	return nil
}

func listLiveCustomManifests() ([]string, error) {
	rbacBase, meBase, token, err := liveSession()
	if err != nil {
		return nil, err
	}
	_ = rbacBase
	req, err := http.NewRequest(http.MethodGet, meBase+"/api/v1/manifests?origin=custom", nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	body, err := io.ReadAll(res.Body)
	if err != nil {
		return nil, err
	}
	var payload map[string]any
	if err := json.Unmarshal(body, &payload); err != nil {
		return nil, err
	}
	items, _ := payload["manifests"].([]any)
	codes := make([]string, 0, len(items))
	for _, item := range items {
		m, _ := item.(map[string]any)
		if c, _ := m["code"].(string); c != "" {
			codes = append(codes, c)
		}
	}
	return codes, nil
}

func liveSession() (rbacBase, meBase, token string, err error) {
	rbacBase = strings.TrimRight(env("MANIFEST_OPENAPI_EXPORT_RBAC_URL", "http://127.0.0.1:8093/rbac"), "/")
	meBase = strings.TrimRight(env("MANIFEST_OPENAPI_EXPORT_ME_URL", "http://127.0.0.1:8095"), "/")
	phone := env("MANIFEST_OPENAPI_EXPORT_PHONE", "")
	password := env("MANIFEST_OPENAPI_EXPORT_PASSWORD", "")
	tenantID := env("MANIFEST_OPENAPI_EXPORT_TENANT", "")
	subtenantID := env("MANIFEST_OPENAPI_EXPORT_SUBTENANT", "main")
	if phone == "" || password == "" {
		credPath := filepath.Join(env("MANIFORGE_REPO_ROOT", "."), "var/manifest-client-test.json")
		if raw, readErr := os.ReadFile(credPath); readErr == nil {
			var creds struct {
				Phone       string `json:"phone"`
				Password    string `json:"password"`
				TenantID    string `json:"tenant_id"`
				SubtenantID string `json:"subtenant_id"`
			}
			if json.Unmarshal(raw, &creds) == nil {
				if phone == "" {
					phone = creds.Phone
				}
				if password == "" {
					password = creds.Password
				}
				if tenantID == "" {
					tenantID = creds.TenantID
				}
				if creds.SubtenantID != "" {
					subtenantID = creds.SubtenantID
				}
			}
		}
	}
	if phone == "" || password == "" {
		return "", "", "", fmt.Errorf("нужны credentials")
	}
	token, err = login(rbacBase, phone, password, tenantID, subtenantID)
	return rbacBase, meBase, token, err
}

func demoManifest(code string) *model.Manifest {
	if code == "contact" {
		return &model.Manifest{
			Code:    code,
			Name:    "Контакт (client test)",
			Version: 1,
			Fields: []model.FieldDef{
				{Name: "full_name", Type: model.FieldString, Required: true, MaxLength: intPtr(120)},
				{Name: "email", Type: model.FieldString, MaxLength: intPtr(120)},
				{Name: "phone", Type: model.FieldString, MaxLength: intPtr(20)},
				{Name: "company", Type: model.FieldString, MaxLength: intPtr(120)},
				{Name: "active", Type: model.FieldBoolean},
				{Name: "notes", Type: model.FieldString, MaxLength: intPtr(500)},
			},
		}
	}
	if code != defaultCode {
		return &model.Manifest{Code: code, Name: code, Version: 1, Fields: []model.FieldDef{
			{Name: "title", Type: model.FieldString, Required: true},
		}}
	}
	return &model.Manifest{
		Code:    code,
		Name:    "Счёт (client test)",
		Version: 1,
		Fields: []model.FieldDef{
			{Name: "number", Type: model.FieldString, Required: true, MaxLength: intPtr(32)},
			{Name: "amount", Type: model.FieldNumber, Required: true},
			{Name: "currency", Type: model.FieldString, MaxLength: intPtr(3)},
			{Name: "status", Type: model.FieldString, MaxLength: intPtr(20)},
			{Name: "paid", Type: model.FieldBoolean},
			{Name: "due_date", Type: model.FieldString, MaxLength: intPtr(10)},
			{Name: "notes", Type: model.FieldString, MaxLength: intPtr(500)},
		},
	}
}

func fieldDefsToExport(fields []model.FieldDef) []any {
	if len(fields) == 0 {
		return nil
	}
	raw, err := json.Marshal(fields)
	if err != nil {
		return nil
	}
	var out []any
	if json.Unmarshal(raw, &out) != nil {
		return nil
	}
	return out
}

func fetchLiveOpenAPI(code string) (map[string]any, string, string, string, []any, error) {
	rbacBase := strings.TrimRight(env("MANIFEST_OPENAPI_EXPORT_RBAC_URL", "http://127.0.0.1:8093/rbac"), "/")
	meBase := strings.TrimRight(env("MANIFEST_OPENAPI_EXPORT_ME_URL", "http://127.0.0.1:8095"), "/")
	phone := env("MANIFEST_OPENAPI_EXPORT_PHONE", "")
	password := env("MANIFEST_OPENAPI_EXPORT_PASSWORD", "")
	if phone == "" || password == "" {
		credPath := filepath.Join(env("MANIFORGE_REPO_ROOT", "."), "var/manifest-client-test.json")
		if raw, err := os.ReadFile(credPath); err == nil {
			var creds struct {
				Phone    string `json:"phone"`
				Password string `json:"password"`
				TenantID string `json:"tenant_id"`
				Subtenant string `json:"subtenant_id"`
			}
			if json.Unmarshal(raw, &creds) == nil {
				if phone == "" {
					phone = creds.Phone
				}
				if password == "" {
					password = creds.Password
				}
			}
		}
	}
	if phone == "" || password == "" {
		return nil, "", "", "", nil, fmt.Errorf("нужны MANIFEST_OPENAPI_EXPORT_PHONE/PASSWORD или var/manifest-client-test.json")
	}

	tenantID := env("MANIFEST_OPENAPI_EXPORT_TENANT", "")
	subtenantID := env("MANIFEST_OPENAPI_EXPORT_SUBTENANT", "main")
	if tenantID == "" {
		credPath := filepath.Join(env("MANIFORGE_REPO_ROOT", "."), "var/manifest-client-test.json")
		if raw, err := os.ReadFile(credPath); err == nil {
			var creds struct {
				TenantID    string `json:"tenant_id"`
				SubtenantID string `json:"subtenant_id"`
			}
			if json.Unmarshal(raw, &creds) == nil {
				tenantID = creds.TenantID
				if creds.SubtenantID != "" {
					subtenantID = creds.SubtenantID
				}
			}
		}
	}
	token, err := login(rbacBase, phone, password, tenantID, subtenantID)
	if err != nil {
		return nil, "", "", "", nil, err
	}

	manifestType := ""
	manifestSection := ""
	manifestName := code
	var manifestFields []any
	if manReq, err := http.NewRequest(http.MethodGet, meBase+"/api/v1/manifests/"+code, nil); err == nil {
		manReq.Header.Set("Authorization", "Bearer "+token)
		if manRes, err := http.DefaultClient.Do(manReq); err == nil {
			defer manRes.Body.Close()
			var manPayload map[string]any
			if raw, err := io.ReadAll(manRes.Body); err == nil && json.Unmarshal(raw, &manPayload) == nil {
				if man, _ := manPayload["manifest"].(map[string]any); man != nil {
					if t, _ := man["type"].(string); t != "" {
						manifestType = t
					}
					if s, _ := man["section"].(string); s != "" {
						manifestSection = s
					}
					if n, _ := man["name"].(string); n != "" {
						manifestName = n
					}
					if fields, ok := man["fields"].([]any); ok {
						manifestFields = fields
					}
				}
			}
		}
	}

	req, err := http.NewRequest(http.MethodGet, meBase+"/api/v1/manifests/"+code+"/openapi", nil)
	if err != nil {
		return nil, "", "", "", nil, err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, "", "", "", nil, err
	}
	defer res.Body.Close()
	body, err := io.ReadAll(res.Body)
	if err != nil {
		return nil, "", "", "", nil, err
	}
	if res.StatusCode != 200 {
		return nil, "", "", "", nil, fmt.Errorf("GET openapi: %d %s", res.StatusCode, string(body))
	}
	var payload map[string]any
	if err := json.Unmarshal(body, &payload); err != nil {
		return nil, "", "", "", nil, err
	}
	spec, _ := payload["openapi"].(map[string]any)
	if spec == nil {
		return nil, "", "", "", nil, fmt.Errorf("ответ без openapi")
	}
	return spec, manifestName, manifestType, manifestSection, manifestFields, nil
}

func login(rbacBase, phone, password, tenantID, subtenantID string) (string, error) {
	payload := map[string]any{"phone": phone, "password": password}
	if tenantID != "" {
		payload["tenant_id"] = tenantID
	}
	if subtenantID != "" {
		payload["subtenant_id"] = subtenantID
	}
	body, _ := json.Marshal(payload)
	res, err := http.Post(rbacBase+"/api/v1/auth/login", "application/json", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	defer res.Body.Close()
	raw, err := io.ReadAll(res.Body)
	if err != nil {
		return "", err
	}
	var resp map[string]any
	if err := json.Unmarshal(raw, &resp); err != nil {
		return "", err
	}
	if ok, _ := resp["ok"].(bool); !ok {
		return "", fmt.Errorf("login: %v", resp["error"])
	}
	if sess, _ := resp["session"].(map[string]any); sess != nil {
		if t, _ := sess["access_token"].(string); t != "" {
			return t, nil
		}
	}
	return "", fmt.Errorf("login: нет access_token")
}

func intPtr(v int) *int { return &v }

func env(key, def string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return def
}
