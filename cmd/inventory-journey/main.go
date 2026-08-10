// Файл: main.go (cmd/inventory-journey)
// Назначение: HTTP journey Inventory (порт PHP inventory_journey_check.php).
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
	prod := env("JOURNEY_PRODUCTS_URL", "http://127.0.0.1:8099")
	inv := env("JOURNEY_INVENTORY_URL", "http://127.0.0.1:8100")
	password := env("INV_JOURNEY_PASSWORD", "InvJourney!12345")
	phone := env("INV_JOURNEY_PHONE", fmt.Sprintf("+7922%07d", time.Now().Unix()%10000000))

	failed, passed := 0, 0
	ok := func(name string, cond bool) {
		if cond {
			passed++
			fmt.Printf("[OK] %s\n", name)
		} else {
			failed++
			fmt.Printf("[FAIL] %s\n", name)
		}
	}

	suffix := fmt.Sprintf("%x", time.Now().UnixNano()%0xffffff)

	reg, err := postJSON(rbac+"/api/v1/auth/register", map[string]any{
		"password": password, "phone": phone,
		"organization_name": "Inventory Journey " + suffix,
		"consents": []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, "")
	if err != nil {
		panic(err)
	}
	ok("Register tenant", reg["ok"] == true)
	t, _ := reg["tenant"].(map[string]any)
	tenantID, _ := t["tenant_id"].(string)
	subtenantID, _ := t["subtenant_id"].(string)

	login, err := postJSON(rbac+"/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password, "tenant_id": tenantID, "subtenant_id": subtenantID,
	}, "")
	if err != nil {
		panic(err)
	}
	ok("Login", login["ok"] == true)
	sess, _ := login["session"].(map[string]any)
	if sess == nil {
		if creds, ok := login["credentials"].(map[string]any); ok {
			sess, _ = creds["session"].(map[string]any)
		}
	}
	token, _ := sess["access_token"].(string)
	auth := "Bearer " + token

	whRes, _ := postJSON(wh+"/api/v1/stocks", map[string]any{
		"code": "wh-inv-" + suffix, "name": "WH Inv", "type": "warehouse",
	}, auth)
	ok("Create warehouse", whRes["ok"] == true)
	whStock, _ := whRes["stock"].(map[string]any)
	whID := int64(floatVal(whStock["id"]))

	zone, _ := postJSON(wh+"/api/v1/stocks", map[string]any{
		"code": "zone-inv-" + suffix, "name": "Zone Inv", "type": "zone", "parent_id": whID,
	}, auth)
	ok("Create zone", zone["ok"] == true)
	zoneStock, _ := zone["stock"].(map[string]any)
	zoneID := int64(floatVal(zoneStock["id"]))

	prodRes, _ := postJSON(prod+"/api/v1/products", map[string]any{
		"code": "sku-inv-" + suffix, "name": "SKU Inv", "unit": "pcs",
	}, auth)
	ok("Create product", prodRes["ok"] == true)
	product, _ := prodRes["product"].(map[string]any)
	productID := int64(floatVal(product["id"]))

	receipt, _ := postJSON(inv+"/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": productID, "stock_id": zoneID,
		"qty": "100", "doc_number": "rcv-" + suffix,
	}, auth)
	ok("Receipt +100", receipt["ok"] == true)
	mov, _ := receipt["movement"].(map[string]any)
	ok("Movement type receipt", mov["movement_type"] == "receipt")

	bal, _ := getJSON(inv+fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, zoneID), auth)
	items, _ := bal["items"].([]any)
	ok("Balance qty 100", qtyEq(firstQty(items), "100"))

	issue, _ := postJSON(inv+"/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": productID, "stock_id": zoneID, "qty": "30",
	}, auth)
	ok("Issue -30", issue["ok"] == true)
	bal2, _ := getJSON(inv+fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, zoneID), auth)
	items2, _ := bal2["items"].([]any)
	ok("Balance qty 70 after issue", qtyEq(firstQty(items2), "70"))

	zone2, _ := postJSON(wh+"/api/v1/stocks", map[string]any{
		"code": "zone2-inv-" + suffix, "name": "Zone B", "type": "zone", "parent_id": whID,
	}, auth)
	ok("Create second zone for transfer", zone2["ok"] == true)
	zone2Stock, _ := zone2["stock"].(map[string]any)
	zone2ID := int64(floatVal(zone2Stock["id"]))

	xfer, _ := postJSON(inv+"/api/v1/movements", map[string]any{
		"movement_type": "transfer", "product_id": productID,
		"from_stock_id": zoneID, "to_stock_id": zone2ID, "qty": "20",
	}, auth)
	ok("Transfer 20 zone→zone", xfer["ok"] == true)
	balZone, _ := getJSON(inv+fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, zoneID), auth)
	ok("Zone A balance 50", qtyEq(firstQty(balZone["items"].([]any)), "50"))
	balZone2, _ := getJSON(inv+fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, zone2ID), auth)
	ok("Zone B balance 20", qtyEq(firstQty(balZone2["items"].([]any)), "20"))

	over, _ := postJSON(inv+"/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": productID, "stock_id": zoneID, "qty": "999",
	}, auth)
	ok("Reject issue over balance", over["ok"] != true)
	ok("insufficient_qty code", over["code"] == "insufficient_qty")

	adj, _ := postJSON(inv+"/api/v1/movements", map[string]any{
		"movement_type": "adjustment", "product_id": productID, "stock_id": zone2ID, "qty_after": "25",
	}, auth)
	ok("Adjustment zone B to 25", adj["ok"] == true)

	movList, _ := getJSON(inv+"/api/v1/movements?limit=10", auth)
	movItems, _ := movList["items"].([]any)
	ok("List movements", len(movItems) >= 4)

	draft, _ := postJSON(inv+"/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": productID, "stock_id": zoneID,
		"qty": "5", "status": "draft", "doc_number": "draft-" + suffix,
	}, auth)
	ok("Create movement draft", draft["ok"] == true)
	draftMov, _ := draft["movement"].(map[string]any)
	ok("Draft status", draftMov["status"] == "draft")
	draftID := int64(floatVal(draftMov["id"]))

	balDraft, _ := getJSON(inv+fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, zoneID), auth)
	ok("Draft does not change balance", qtyEq(firstQty(balDraft["items"].([]any)), "50"))

	postedDraft, _ := postJSON(inv+fmt.Sprintf("/api/v1/movements/%d/post", draftID), map[string]any{}, auth)
	ok("Post draft movement", postedDraft["ok"] == true)
	balAfterDraft, _ := getJSON(inv+fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, zoneID), auth)
	ok("Balance +5 after draft post", qtyEq(firstQty(balAfterDraft["items"].([]any)), "55"))

	lot, _ := postJSON(inv+"/api/v1/lots", map[string]any{
		"product_id": productID, "batch_code": "B-J-" + suffix, "lot_code": "L-J-" + suffix,
	}, auth)
	ok("Register lot", lot["ok"] == true)

	order, _ := postJSON(inv+"/api/v1/orders", map[string]any{
		"order_number": "so-" + suffix, "stock_id": zoneID,
		"lines": []map[string]any{{"product_id": productID, "qty": "10"}},
	}, auth)
	ok("Create warehouse order", order["ok"] == true)
	orderRow, _ := order["order"].(map[string]any)
	orderID := int64(floatVal(orderRow["id"]))

	confirmed, _ := postJSON(inv+fmt.Sprintf("/api/v1/orders/%d/confirm", orderID), map[string]any{}, auth)
	ok("Confirm order reserves", confirmed["ok"] == true)
	balReserved, _ := getJSON(inv+fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, zoneID), auth)
	resItems, _ := balReserved["items"].([]any)
	ok("Available 45 after order reserve", qtyEq(firstAvailable(resItems), "45"))

	fulfilled, _ := postJSON(inv+fmt.Sprintf("/api/v1/orders/%d/fulfill", orderID), map[string]any{}, auth)
	ok("Fulfill order issue", fulfilled["ok"] == true)
	balFulfilled, _ := getJSON(inv+fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, zoneID), auth)
	fulItems, _ := balFulfilled["items"].([]any)
	ok("On hand 45 after fulfill", qtyEq(firstQty(fulItems), "45"))

	prodDetail, _ := getJSON(prod+fmt.Sprintf("/api/v1/products/%d?include=balances", productID), auth)
	pd, _ := prodDetail["product"].(map[string]any)
	_, hasBalances := pd["balances"]
	ok("Product include=balances", hasBalances)

	fmt.Printf("\nInventory journey: ok=%d, fail=%d\n", passed, failed)
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

func postJSON(url string, body any, auth string) (map[string]any, error) {
	b, _ := json.Marshal(body)
	req, _ := http.NewRequest(http.MethodPost, url, bytes.NewReader(b))
	req.Header.Set("Content-Type", "application/json")
	if auth != "" {
		req.Header.Set("Authorization", auth)
	}
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
	if f, ok := v.(float64); ok {
		return f
	}
	return 0
}

func firstQty(items []any) string {
	if len(items) == 0 {
		return "0"
	}
	if m, ok := items[0].(map[string]any); ok {
		if s, ok := m["qty"].(string); ok {
			return s
		}
	}
	return "0"
}

func firstAvailable(items []any) string {
	if len(items) == 0 {
		return "0"
	}
	if m, ok := items[0].(map[string]any); ok {
		if s, ok := m["qty_available"].(string); ok {
			return s
		}
	}
	return "0"
}

func qtyEq(a, b string) bool {
	var fa, fb float64
	fmt.Sscan(a, &fa)
	fmt.Sscan(b, &fb)
	diff := fa - fb
	if diff < 0 {
		diff = -diff
	}
	return diff < 1e-4
}
