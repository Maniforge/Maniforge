// Файл: main.go (cmd/manifest-test)
// Назначение: максимальное интеграционное тестирование Manifest Engine.
package main

import (
	"bytes"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/manifestengine/model"
	"maniforge/internal/manifestengine/presets"
	"maniforge/internal/manifestengine/refine"
	mrepo "maniforge/internal/manifestengine/repository"
	"maniforge/internal/manifestengine/testutil"
	"maniforge/internal/versioning"
)

func main() {
	var failed, passed int
	step := func(name string, fn func() error) {
		if err := fn(); err != nil {
			failed++
			fmt.Printf("[FAIL] %s: %v\n", name, err)
		} else {
			passed++
			fmt.Printf("[OK] %s\n", name)
		}
	}

	rbacBase := env("MANIFEST_TEST_RBAC_URL", env("MANIFEST_JOURNEY_RBAC_URL", "http://127.0.0.1:8093/rbac"))
	meBase := strings.TrimRight(env("MANIFEST_TEST_ME_URL", env("MANIFEST_JOURNEY_ME_URL", "http://127.0.0.1:8095")), "/")
	phone := env("MANIFEST_TEST_PHONE", fmt.Sprintf("+7908%07d", time.Now().Unix()%10000000))
	password := env("MANIFEST_TEST_PASSWORD", "ManifestTest!12345")

	c := &testutil.Client{RBACBase: rbacBase, MEBase: meBase}

	step("services health", func() error {
		for _, u := range []string{rbacBase + "/health", meBase + "/health"} {
			res, err := http.Get(u)
			if err != nil {
				return fmt.Errorf("%s: %w (запустите: make run-tl run-rbac run-manifest)", u, err)
			}
			res.Body.Close()
			if res.StatusCode != 200 {
				return fmt.Errorf("%s: status %d", u, res.StatusCode)
			}
		}
		return nil
	})

	var tenantID, subtenantID string
	var projectID int64

	step("register + login", func() error {
		reg, st, err := c.RBACPost("/api/v1/auth/register", map[string]any{
			"phone": phone, "password": password,
			"organization_name": "Manifest Test Org",
			"platform_dpa_accepted": true,
			"consents": []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
		})
		if err != nil {
			return err
		}
		if st != 201 || !reg["ok"].(bool) {
			return fmt.Errorf("register %d: %v", st, reg["error"])
		}
		t, _ := reg["tenant"].(map[string]any)
		tenantID, _ = t["tenant_id"].(string)
		subtenantID, _ = t["subtenant_id"].(string)

		login, st, err := c.RBACPost("/api/v1/auth/login", map[string]any{
			"phone": phone, "password": password,
			"tenant_id": tenantID, "subtenant_id": subtenantID,
		})
		if err != nil {
			return err
		}
		if st != 200 || !login["ok"].(bool) {
			return fmt.Errorf("login: %v", login["error"])
		}
		sess, _ := login["session"].(map[string]any)
		c.Token, _ = sess["access_token"].(string)
		if c.Token == "" {
			return fmt.Errorf("нет access_token")
		}
		cfg, _ := config.Load()
		sqlDB, err := db.OpenOptional(cfg)
		if err != nil {
			return fmt.Errorf("db: %w", err)
		}
		defer sqlDB.Close()
		projectID, err = mrepo.ProjectIDByCode(sqlDB, tenantID, subtenantID, "main")
		return err
	})

	step("auth: no token → 401", func() error {
		_, st, err := c.DoJSON(http.MethodGet, meBase+"/api/v1/manifests", nil, "")
		return testutil.ExpectFail(nil, st, err, 401)
	})

	step("auth: invalid token → 401", func() error {
		_, st, err := c.DoJSON(http.MethodGet, meBase+"/api/v1/manifests", nil, "invalid-token")
		return testutil.ExpectFail(nil, st, err, 401)
	})

	step("field type catalog", func() error {
		resp, st, err := c.Get("/api/v1/catalog/field-types")
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		types, _ := resp["field_types"].([]any)
		if len(types) < 8 {
			return fmt.Errorf("field_types < 8")
		}
		return nil
	})

	step("presets list", func() error {
		resp, st, err := c.Get("/api/v1/manifests/presets")
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		items, _ := resp["presets"].([]any)
		if len(items) < 2 {
			return fmt.Errorf("presets < 2")
		}
		return nil
	})

	step("presets install + idempotent", func() error {
		for _, code := range []string{"product", "stock"} {
			resp, st, err := c.Post("/api/v1/manifests/presets/"+code, map[string]any{})
			if err != nil {
				return err
			}
			if st != 201 && st != 200 {
				return fmt.Errorf("%s: status %d %v", code, st, resp["error"])
			}
		}
		resp, st, err := c.Post("/api/v1/manifests/presets/product", map[string]any{})
		if err != nil || st != 200 || !resp["ok"].(bool) {
			return fmt.Errorf("idempotent: %d %v", st, resp)
		}
		return nil
	})

	step("preset unknown → 422", func() error {
		resp, st, err := c.Post("/api/v1/manifests/presets/unknown_xyz", map[string]any{})
		return testutil.ExpectFail(resp, st, err, 422)
	})

	const entity = "test_suite_doc"
	var recordID int64

	step("client cannot create platform manifest", func() error {
		resp, st, err := c.Post("/api/v1/manifests", map[string]any{
			"code": "product", "name": "Fake Product",
			"fields": []map[string]any{{"name": "code", "type": "string"}},
		})
		return testutil.ExpectFail(resp, st, err, 422)
	})

	step("client cannot mutate platform manifest", func() error {
		resp, st, err := c.Patch("/api/v1/manifests/product", map[string]any{
			"name": "Hacked", "fields": []map[string]any{{"name": "code", "type": "string"}},
		})
		return testutil.ExpectFail(resp, st, err, 403)
	})

	step("manifest origin platform vs custom", func() error {
		pResp, st, err := c.Get("/api/v1/manifests?origin=platform")
		if err := testutil.ExpectOK(pResp, st, err); err != nil {
			return err
		}
		platform, _ := pResp["manifests"].([]any)
		if len(platform) < 2 {
			return fmt.Errorf("platform manifests < 2")
		}
		for _, item := range platform {
			m, _ := item.(map[string]any)
			if m["origin"] != "platform" {
				return fmt.Errorf("ожидался origin=platform")
			}
		}
		return nil
	})

	step("manifest create (field RBAC schema)", func() error {
		resp, st, err := c.Post("/api/v1/manifests", map[string]any{
			"code": entity, "name": "Test Suite Doc", "type": "docs",
			"fields": []map[string]any{
				{"name": "title", "type": "string", "required": true, "max_length": 100},
				{"name": "body", "type": "string"},
				{"name": "secret", "type": "string", "read_roles": []string{"manager"}, "write_roles": []string{"manager"}},
				{"name": "price", "type": "number", "min": 0},
				{"name": "active", "type": "boolean"},
				{"name": "tags", "type": "array", "items": map[string]any{"type": "string"}},
				{"name": "variants", "type": "array", "items": map[string]any{"type": "object"}},
			},
		})
		return testutil.ExpectOK(resp, st, err)
	})

	step("manifest get + list", func() error {
		resp, st, err := c.Get("/api/v1/manifests/" + entity)
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		m, _ := resp["manifest"].(map[string]any)
		if m["origin"] != "custom" {
			return fmt.Errorf("custom manifest origin")
		}
		if m["type"] != "docs" {
			return fmt.Errorf("type docs, got %v", m["type"])
		}
		list, st, err := c.Get("/api/v1/manifests")
		if err := testutil.ExpectOK(list, st, err); err != nil {
			return err
		}
		if len(list["manifests"].([]any)) < 3 {
			return fmt.Errorf("мало manifests в списке")
		}
		return nil
	})

	step("manifest duplicate → 409", func() error {
		resp, st, err := c.Post("/api/v1/manifests", map[string]any{
			"code": entity, "name": "Dup",
			"fields": []map[string]any{{"name": "title", "type": "string"}},
		})
		return testutil.ExpectFail(resp, st, err, 409)
	})

	step("validation: required + unknown field", func() error {
		resp, st, err := c.Post("/api/data/"+entity, map[string]any{"body": "no title"})
		if err != nil || st != 422 {
			return fmt.Errorf("required: status %d", st)
		}
		resp, st, err = c.Post("/api/data/"+entity, map[string]any{"title": "ok", "unknown": 1})
		if err != nil || st != 422 {
			return fmt.Errorf("unknown: status %d %v", st, resp["error"])
		}
		return nil
	})

	step("record CRUD + nested field-path", func() error {
		resp, st, err := c.Post("/api/data/"+entity, map[string]any{
			"title": "Test Suite", "body": "v1", "secret": "hidden", "price": 10.5,
			"active": true, "tags": []any{"a", "b"},
			"variants": []any{map[string]any{"sku": "v1", "price": 99}},
		})
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		rec, _ := resp["record"].(map[string]any)
		recordID = int64(rec["id"].(float64))

		resp, st, err = c.Get(fmt.Sprintf("/api/data/%s/%d", entity, recordID))
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		data, _ := resp["record"].(map[string]any)["data"].(map[string]any)
		if data["secret"] != "hidden" {
			return fmt.Errorf("admin должен видеть secret")
		}

		resp, st, err = c.Patch(fmt.Sprintf("/api/data/%s/%d", entity, recordID), map[string]any{"body": "v2"})
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}

		resp, st, err = c.Put(fmt.Sprintf("/api/data/%s/%d/variants/0/price", entity, recordID), map[string]any{"value": 199})
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		updated, _ := resp["record"].(map[string]any)["data"].(map[string]any)
		variants, _ := updated["variants"].([]any)
		v0, _ := variants[0].(map[string]any)
		if v0["price"] != float64(199) {
			return fmt.Errorf("nested price: %#v", v0["price"])
		}
		return nil
	})

	step("field DELETE null + required guard", func() error {
		resp, st, err := c.Delete(fmt.Sprintf("/api/data/%s/%d/body", entity, recordID))
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		if resp["value"] != nil {
			return fmt.Errorf("value в ответе должен быть null")
		}
		data, _ := resp["record"].(map[string]any)["data"].(map[string]any)
		if _, ok := data["body"]; !ok || data["body"] != nil {
			return fmt.Errorf("body должен быть null, got %#v", data["body"])
		}

		resp, st, err = c.Delete(fmt.Sprintf("/api/data/%s/%d/title", entity, recordID))
		if err != nil || st != 422 {
			return fmt.Errorf("required title delete: status %d %v", st, resp["error"])
		}

		resp, st, err = c.Put(fmt.Sprintf("/api/data/%s/%d/body", entity, recordID), map[string]any{"value": "restored"})
		return testutil.ExpectOK(resp, st, err)
	})

	step("filter exact + ilike + pagination", func() error {
		q := url.Values{}
		q.Set("filter", `{"title":"Test Suite"}`)
		resp, st, err := c.Get("/api/data/" + entity + "?" + q.Encode())
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		if len(resp["records"].([]any)) < 1 {
			return fmt.Errorf("exact filter empty")
		}
		q.Set("filter", `{"title":"Test%"}`)
		q.Set("limit", "1")
		resp, st, err = c.Get("/api/data/" + entity + "?" + q.Encode())
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		meta, _ := resp["meta"].(map[string]any)
		if meta["total"] == nil {
			return fmt.Errorf("нет meta.total")
		}
		return nil
	})

	step("bad filter → 422", func() error {
		resp, st, err := c.Get("/api/data/" + entity + "?filter=not-json")
		return testutil.ExpectFail(resp, st, err, 422)
	})

	step("manifest patch (version++)", func() error {
		resp, st, err := c.Patch("/api/v1/manifests/"+entity, map[string]any{
			"name": "Test Suite Doc v2", "type": "general",
			"fields": []map[string]any{
				{"name": "title", "type": "string", "required": true},
				{"name": "body", "type": "string"},
				{"name": "secret", "type": "string", "read_roles": []string{"manager"}},
				{"name": "price", "type": "number"},
				{"name": "active", "type": "boolean"},
				{"name": "tags", "type": "array"},
				{"name": "variants", "type": "array"},
				{"name": "note", "type": "string"},
			},
		})
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		if int(resp["manifest"].(map[string]any)["version"].(float64)) < 2 {
			return fmt.Errorf("version не увеличился")
		}
		if resp["manifest"].(map[string]any)["type"] != "general" {
			return fmt.Errorf("type patch failed")
		}
		return nil
	})

	step("field DELETE optional note", func() error {
		resp, st, err := c.Patch(fmt.Sprintf("/api/data/%s/%d", entity, recordID), map[string]any{"note": "temp"})
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		resp, st, err = c.Delete(fmt.Sprintf("/api/data/%s/%d/note", entity, recordID))
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		data, _ := resp["record"].(map[string]any)["data"].(map[string]any)
		if data["note"] != nil {
			return fmt.Errorf("note: %#v", data["note"])
		}
		return nil
	})

	step("openapi json + yaml", func() error {
		resp, st, err := c.Get("/api/v1/manifests/" + entity + "/openapi")
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		if resp["openapi"] == nil {
			return fmt.Errorf("нет openapi")
		}
		body, st, err := c.GetRaw("/api/v1/manifests/" + entity + "/openapi.yaml")
		if err != nil || st != 200 || !bytes.Contains(body, []byte("openapi:")) {
			return fmt.Errorf("yaml invalid: %d", st)
		}
		return nil
	})

	step("record delete", func() error {
		resp, st, err := c.Delete(fmt.Sprintf("/api/data/%s/%d", entity, recordID))
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		resp, st, err = c.Get(fmt.Sprintf("/api/data/%s/%d", entity, recordID))
		return testutil.ExpectFail(resp, st, err, 404)
	})

	step("versioning + audit in DB", func() error {
		cfg, err := config.Load()
		if err != nil {
			return err
		}
		sqlDB, err := db.OpenOptional(cfg)
		if err != nil {
			return err
		}
		defer sqlDB.Close()
		ver := versioning.NewRepository(sqlDB)
		n, err := ver.CountByEntity(tenantID, subtenantID, versioning.TableManifestRecords, strconv.FormatInt(recordID, 10))
		if err != nil {
			return err
		}
		if n < 4 {
			return fmt.Errorf("ver_changes: got %d, want >=4", n)
		}
		repo := mrepo.New(sqlDB)
		auditN, err := repo.CountAuditByManifest(tenantID, projectID, entity)
		if err != nil {
			return err
		}
		if auditN < 5 {
			return fmt.Errorf("audit: got %d, want >=5", auditN)
		}
		return nil
	})

	step("refine scaffold generate", func() error {
		def := presets.Product()
		m := &model.Manifest{Code: def.Code, Name: def.Name, Fields: def.Fields}
		sc, err := refine.GenerateFromManifest(m, "http://127.0.0.1:8095/api/data")
		if err != nil {
			return err
		}
		if sc.Files["src/App.tsx"] == "" || sc.Files["package.json"] == "" {
			return fmt.Errorf("incomplete scaffold")
		}
		return nil
	})

	step("manifest archive + post-archive 404", func() error {
		resp, st, err := c.Delete("/api/v1/manifests/" + entity)
		if err := testutil.ExpectOK(resp, st, err); err != nil {
			return err
		}
		resp, st, err = c.Get("/api/v1/manifests/" + entity)
		if err := testutil.ExpectFail(resp, st, err, 404); err != nil {
			return err
		}
		resp, st, err = c.Post("/api/data/"+entity, map[string]any{"title": "x"})
		return testutil.ExpectFail(resp, st, err, 404)
	})

	step("product preset data record", func() error {
		resp, st, err := c.Post("/api/data/product", map[string]any{
			"code": "sku-test-max", "name": "Max Test SKU", "unit": "pcs",
		})
		return testutil.ExpectOK(resp, st, err)
	})

	step("prefix /manifest-engine/health", func() error {
		res, err := http.Get(meBase + "/manifest-engine/health")
		if err != nil {
			return err
		}
		defer res.Body.Close()
		if res.StatusCode != 200 {
			return fmt.Errorf("status %d", res.StatusCode)
		}
		return nil
	})

	fmt.Printf("\nManifest test: passed=%d failed=%d\n", passed, failed)
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
