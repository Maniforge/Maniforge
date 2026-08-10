// Файл: main.go (cmd/warehouses-journey)
// Назначение: HTTP journey Warehouses (порт PHP warehouses_journey_check.php).
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"
)

func main() {
	rbac := env("JOURNEY_RBAC_URL", "http://127.0.0.1:8093/rbac")
	wh := env("JOURNEY_WH_URL", "http://127.0.0.1:8098")
	password := env("WH_JOURNEY_PASSWORD", "WhJourney!12345")
	phone := env("WH_JOURNEY_PHONE", fmt.Sprintf("+7920%07d", time.Now().Unix()%10000000))

	failed := 0
	ok := func(name string, cond bool) {
		if cond {
			fmt.Printf("[OK] %s\n", name)
		} else {
			failed++
			fmt.Printf("[FAIL] %s\n", name)
		}
	}

	var token, tenantID, subtenantID string
	suffix := fmt.Sprintf("%x", time.Now().UnixNano()%0xffffff)

	reg, err := postJSON(rbac+"/api/v1/auth/register", map[string]any{
		"password": password, "phone": phone,
		"organization_name": "WH Journey " + suffix,
		"consents": []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, "")
	if err != nil {
		panic(err)
	}
	ok("Register tenant", reg["ok"] == true)
	t, _ := reg["tenant"].(map[string]any)
	tenantID, _ = t["tenant_id"].(string)
	subtenantID, _ = t["subtenant_id"].(string)

	login, err := postJSON(rbac+"/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password, "tenant_id": tenantID, "subtenant_id": subtenantID,
	}, "")
	if err != nil {
		panic(err)
	}
	ok("Login tenant admin", login["ok"] == true)
	sess, _ := login["session"].(map[string]any)
	if sess == nil {
		if creds, ok := login["credentials"].(map[string]any); ok {
			sess, _ = creds["session"].(map[string]any)
		}
	}
	token, _ = sess["access_token"].(string)
	csrf, _ := login["csrf_token"].(string)
	if csrf == "" {
		csrf, _ = sess["csrf_token"].(string)
	}
	ok("Session has project_id", int64(floatVal(sess["project_id"])) > 0)

	auth := "Bearer " + token
	authHeaders := map[string]string{
		"Authorization": auth,
		"X-CSRF-Token":  csrf,
	}
	reauth, _ := postJSONHeaders(rbac+"/api/v1/auth/reauth", map[string]any{"password": password}, authHeaders)
	actionToken := ""
	if creds, ok := reauth["credentials"].(map[string]any); ok {
		if action, ok := creds["action"].(map[string]any); ok {
			actionToken, _ = action["action_token"].(string)
		}
	}
	if actionToken != "" {
		authHeaders["X-Action-Token"] = actionToken
	}
	types, _ := getJSON(wh+"/api/v1/stock-types", auth)
	items, _ := types["items"].([]any)
	ok("Stock types catalog seeded", len(items) >= 5)

	whCode := "wh-main-" + suffix
	created, _ := postJSON(wh+"/api/v1/stocks", map[string]any{
		"code": whCode, "name": "Main Warehouse", "type": "warehouse",
		"data": map[string]any{"city": "Moscow", "address": "Test st. 1"},
	}, auth)
	ok("Create root warehouse", created["ok"] == true)
	stock, _ := created["stock"].(map[string]any)
	whID := int64(floatVal(stock["id"]))

	zone, _ := postJSON(wh+"/api/v1/stocks", map[string]any{
		"code": "zone-a-" + suffix, "name": "Zone A", "type": "zone", "parent_id": whID,
	}, auth)
	ok("Create zone under warehouse", zone["ok"] == true)
	zoneStock, _ := zone["stock"].(map[string]any)
	zoneID := int64(floatVal(zoneStock["id"]))

	proj, status, _ := postJSONStatusHeaders(rbac+"/api/v1/projects", map[string]any{
		"code": "wh-proj-" + suffix, "name": "Project with warehouse", "warehouse_id": whID,
	}, authHeaders)
	ok("Create project with warehouse_id", proj["ok"] == true && status == 201)
	projRow, _ := proj["project"].(map[string]any)
	ok("Project stores warehouse_id", int64(floatVal(projRow["warehouse_id"])) == whID)
	whEmbed, _ := projRow["warehouse"].(map[string]any)
	ok("Project embeds warehouse summary", whEmbed["code"] == whCode)

	badProj, _ := postJSONHeaders(rbac+"/api/v1/projects", map[string]any{
		"code": "wh-proj-bad-" + suffix, "name": "Bad warehouse bind", "warehouse_id": zoneID,
	}, authHeaders)
	ok("Reject project warehouse_id on non-warehouse node", badProj["ok"] != true)

	badCell, _ := postJSON(wh+"/api/v1/stocks", map[string]any{
		"name": "Bad cell", "type": "cell", "parent_id": whID,
	}, auth)
	ok("Reject cell directly under warehouse", badCell["ok"] != true)

	tree, _ := getJSON(wh+"/api/v1/stocks/tree", auth)
	treeNodes, _ := tree["tree"].([]any)
	ok("Tree has root nodes", len(treeNodes) >= 1)
	ok("Tree flat count includes nodes", int(floatVal(tree["flat_count"])) >= 2)

	moveBad, _ := patchJSON(wh+fmt.Sprintf("/api/v1/stocks/%d", whID), map[string]any{"parent_id": zoneID}, auth)
	ok("Reject cycle parent move", moveBad["ok"] != true)

	archiveWhBlocked, _ := deleteJSON(wh+fmt.Sprintf("/api/v1/stocks/%d", whID), auth)
	ok("Cannot archive warehouse with active children", archiveWhBlocked["ok"] != true)
	ok("Archive blocked code has_active_children", archiveWhBlocked["code"] == "has_active_children")

	archiveZone, _ := deleteJSON(wh+fmt.Sprintf("/api/v1/stocks/%d", zoneID), auth)
	ok("Archive leaf zone", archiveZone["ok"] == true)

	archiveWh, _ := deleteJSON(wh+fmt.Sprintf("/api/v1/stocks/%d", whID), auth)
	ok("Archive warehouse after children removed", archiveWh["ok"] == true)

	ext, _ := postJSON(wh+fmt.Sprintf("/api/v1/stocks/%d/external-meta", whID), map[string]any{
		"type": "wildberries_fbo", "external_id": "WB-WH-" + suffix,
	}, auth)
	ok("Bind external meta on stock", ext["ok"] == true)

	fresh, _ := postJSON(wh+"/api/v1/stocks", map[string]any{
		"code": "wh-audit-" + suffix, "name": "Audit probe", "type": "warehouse",
	}, auth)
	ok("Create stock for audit probe", fresh["ok"] == true)
	freshStock, _ := fresh["stock"].(map[string]any)
	auditID := int64(floatVal(freshStock["id"]))
	cbu, _ := freshStock["created_by_user"].(map[string]any)
	ok("Stock response includes created_by_user", cbu != nil)
	ok("created_by_user matches session actor", int64(floatVal(cbu["id"])) == int64(floatVal(sess["user_id"])))

	audit, _ := getJSON(wh+fmt.Sprintf("/api/v1/stocks/%d/audit?limit=10", auditID), auth)
	auditItems, _ := audit["items"].([]any)
	ok("Stock audit trail readable", audit["ok"] == true)
	ok("Audit trail has create event", len(auditItems) >= 1)
	if len(auditItems) > 0 {
		first, _ := auditItems[0].(map[string]any)
		ok("First audit event is stock.created", first["event_type"] == "warehouses.stock.created")
		_, hasActor := first["actor_user"].(map[string]any)
		ok("Audit item includes actor_user", hasActor)
	}

	fmt.Printf("\nWarehouses journey: ok=%d, fail=%d\n", 22-failed, failed)
	if failed > 0 {
		os.Exit(1)
	}
}

func env(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
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
	headers := map[string]string{}
	if auth != "" {
		headers["Authorization"] = auth
	}
	return postJSONStatusHeaders(url, body, headers)
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

func patchJSON(url string, body any, auth string) (map[string]any, error) {
	b, _ := json.Marshal(body)
	req, _ := http.NewRequest(http.MethodPatch, url, bytes.NewReader(b))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", auth)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	var out map[string]any
	_ = json.Unmarshal(raw, &out)
	return out, nil
}

func deleteJSON(url, auth string) (map[string]any, error) {
	req, _ := http.NewRequest(http.MethodDelete, url, nil)
	req.Header.Set("Authorization", auth)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	var out map[string]any
	_ = json.Unmarshal(raw, &out)
	return out, nil
}

func getJSON(url, auth string) (map[string]any, error) {
	req, _ := http.NewRequest(http.MethodGet, url, nil)
	req.Header.Set("Authorization", auth)
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	var out map[string]any
	_ = json.Unmarshal(raw, &out)
	return out, nil
}

func floatVal(v any) float64 {
	if v == nil {
		return 0
	}
	if f, ok := v.(float64); ok {
		return f
	}
	return 0
}
