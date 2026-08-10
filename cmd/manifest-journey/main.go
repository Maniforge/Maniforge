// Файл: main.go (cmd/manifest-journey)
// Назначение: HTTP journey Manifest Engine (register → manifest → data CRUD).
// См. также: docs/MANIFORGE_MANIFEST_ENGINE_PLAN.md
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/versioning"
)

func main() {
	rbacBase := env("MANIFEST_JOURNEY_RBAC_URL", "http://127.0.0.1:8093/rbac")
	meBase := env("MANIFEST_JOURNEY_ME_URL", "http://127.0.0.1:8095")
	phone := env("MANIFEST_JOURNEY_PHONE", fmt.Sprintf("+7907%07d", time.Now().Unix()%10000000))
	password := env("MANIFEST_JOURNEY_PASSWORD", "ManifestJourney!123")

	var failed int
	step := func(name string, fn func() error) {
		if err := fn(); err != nil {
			failed++
			fmt.Printf("[FAIL] %s: %v\n", name, err)
		} else {
			fmt.Printf("[OK] %s\n", name)
		}
	}

	var token string
	var recordID int64
	var tenantID, subtenantID string

	step("register", func() error {
		body := map[string]any{
			"phone": phone, "password": password,
			"organization_name": "Manifest Journey Org",
			"platform_dpa_accepted": true,
			"consents": []map[string]string{
				{"purpose_code": "account", "policy_version": "1.0"},
			},
		}
		resp, err := postJSON(rbacBase+"/api/v1/auth/register", body, "")
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		tenant, _ := resp["tenant"].(map[string]any)
		tenantID, _ = tenant["tenant_id"].(string)
		subtenantID, _ = tenant["subtenant_id"].(string)
		return nil
	})

	step("login", func() error {
		body := map[string]any{"phone": phone, "password": password}
		if tenantID != "" {
			body["tenant_id"] = tenantID
		}
		if subtenantID != "" {
			body["subtenant_id"] = subtenantID
		}
		resp, err := postJSON(rbacBase+"/api/v1/auth/login", body, "")
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		sess, _ := resp["session"].(map[string]any)
		token, _ = sess["access_token"].(string)
		if token == "" {
			return fmt.Errorf("нет access_token")
		}
		return nil
	})

	step("list presets", func() error {
		resp, err := getJSON(meBase+"/api/v1/manifests/presets", token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		items, _ := resp["presets"].([]any)
		if len(items) < 2 {
			return fmt.Errorf("ожидалось >=2 presets")
		}
		return nil
	})

	step("install product preset", func() error {
		resp, err := postJSON(meBase+"/api/v1/manifests/presets/product", map[string]any{}, token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		return nil
	})

	step("install stock preset", func() error {
		resp, err := postJSON(meBase+"/api/v1/manifests/presets/stock", map[string]any{}, token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		return nil
	})

	step("create product record", func() error {
		body := map[string]any{
			"code": "sku-journey-001", "name": "Journey Product", "unit": "pcs",
			"barcode_ean13": "4601234567890",
		}
		resp, err := postJSON(meBase+"/api/data/product", body, token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		return nil
	})

	step("create manifest", func() error {
		body := map[string]any{
			"code": "journey_note", "name": "Journey Note",
			"fields": []map[string]any{
				{"name": "title", "type": "string", "required": true, "max_length": 200},
				{"name": "body", "type": "string"},
			},
		}
		resp, err := postJSON(meBase+"/api/v1/manifests", body, token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		return nil
	})

	step("create record", func() error {
		body := map[string]any{"title": "hello manifest journey", "body": "phase 1"}
		resp, err := postJSON(meBase+"/api/data/journey_note", body, token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		rec, _ := resp["record"].(map[string]any)
		recordID = int64(rec["id"].(float64))
		return nil
	})

	step("patch record", func() error {
		body := map[string]any{"body": "updated"}
		resp, err := patchJSON(fmt.Sprintf("%s/api/data/journey_note/%d", meBase, recordID), body, token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		return nil
	})

	step("filter records", func() error {
		q := url.Values{}
		q.Set("filter", `{"title":"hello%"}`)
		resp, err := getJSON(meBase+"/api/data/journey_note?"+q.Encode(), token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		recs, _ := resp["records"].([]any)
		if len(recs) == 0 {
			return fmt.Errorf("filter не нашёл записей")
		}
		meta, _ := resp["meta"].(map[string]any)
		if meta == nil || int(meta["total"].(float64)) < 1 {
			return fmt.Errorf("meta.total отсутствует")
		}
		return nil
	})

	step("put field", func() error {
		body := map[string]any{"value": "title via field path"}
		resp, err := putJSON(fmt.Sprintf("%s/api/data/journey_note/%d/title", meBase, recordID), body, token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		return nil
	})

	step("list records", func() error {
		resp, err := getJSON(meBase+"/api/data/journey_note", token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		recs, _ := resp["records"].([]any)
		if len(recs) == 0 {
			return fmt.Errorf("пустой список")
		}
		if _, ok := resp["meta"]; !ok {
			return fmt.Errorf("нет meta pagination")
		}
		return nil
	})

	step("versioning", func() error {
		if tenantID == "" || subtenantID == "" || recordID == 0 {
			return fmt.Errorf("нет контекста для проверки versioning")
		}
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		sqlDB, err := db.OpenOptional(cfg)
		if err != nil {
			return fmt.Errorf("БД недоступна: %w", err)
		}
		defer sqlDB.Close()
		repo := versioning.NewRepository(sqlDB)
		entityID := strconv.FormatInt(recordID, 10)
		n, err := repo.CountByEntity(tenantID, subtenantID, versioning.TableManifestRecords, entityID)
		if err != nil {
			return err
		}
		if n < 3 {
			return fmt.Errorf("ожидалось >=3 ver_changes, получено %d", n)
		}
		return nil
	})

	step("openapi", func() error {
		resp, err := getJSON(meBase+"/api/v1/manifests/journey_note/openapi", token)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		return nil
	})

	step("openapi yaml", func() error {
		req, _ := http.NewRequest(http.MethodGet, meBase+"/api/v1/manifests/journey_note/openapi.yaml", nil)
		req.Header.Set("Authorization", "Bearer "+token)
		res, err := http.DefaultClient.Do(req)
		if err != nil {
			return err
		}
		defer res.Body.Close()
		body, _ := io.ReadAll(res.Body)
		if res.StatusCode != http.StatusOK {
			return fmt.Errorf("status %d", res.StatusCode)
		}
		ct := res.Header.Get("Content-Type")
		if ct == "" || len(body) == 0 || !bytes.Contains(body, []byte("openapi:")) {
			return fmt.Errorf("невалидный yaml ответ")
		}
		return nil
	})

	step("archive manifest", func() error {
		req, _ := http.NewRequest(http.MethodDelete, meBase+"/api/v1/manifests/journey_note", nil)
		req.Header.Set("Authorization", "Bearer "+token)
		res, err := http.DefaultClient.Do(req)
		if err != nil {
			return err
		}
		defer res.Body.Close()
		resp, err := decodeBody(res.Body)
		if err != nil {
			return err
		}
		if !resp["ok"].(bool) {
			return fmt.Errorf("%v", resp["error"])
		}
		return nil
	})

	fmt.Printf("\nManifest journey: failed=%d\n", failed)
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

func postJSON(url string, body any, token string) (map[string]any, error) {
	return doJSON(http.MethodPost, url, body, token)
}

func patchJSON(url string, body any, token string) (map[string]any, error) {
	return doJSON(http.MethodPatch, url, body, token)
}

func putJSON(url string, body any, token string) (map[string]any, error) {
	return doJSON(http.MethodPut, url, body, token)
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
