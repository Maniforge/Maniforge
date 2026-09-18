package inventory

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/platform/apitest"
	"maniforge/internal/products"
	"maniforge/internal/rbac"
	"maniforge/internal/rbac/service"
	"maniforge/internal/warehouses"
)

var inventoryLiveRoutes = []string{
	"GET /health",
	"GET /api/v1/delegation/grant-peers",
	"GET /api/v1/balances",
	"GET /api/v1/balances/summary",
	"GET /api/v1/reports/overview",
	"GET /api/v1/reserves",
	"POST /api/v1/reserves",
	"POST /api/v1/reserves/:id/release",
	"GET /api/v1/movements",
	"POST /api/v1/movements",
	"GET /api/v1/movements/:id",
	"POST /api/v1/movements/:id/reverse",
	"POST /api/v1/movements/:id/post",
	"DELETE /api/v1/movements/:id",
	"GET /api/v1/lots",
	"POST /api/v1/lots",
	"GET /api/v1/lots/:id",
	"GET /api/v1/orders",
	"POST /api/v1/orders",
	"GET /api/v1/orders/:id",
	"POST /api/v1/orders/:id/confirm",
	"POST /api/v1/orders/:id/fulfill",
	"POST /api/v1/orders/:id/cancel",
}

func TestInventoryFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), inventoryLiveRoutes)
}

func TestInventoryProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	status, out := c.JSON("GET", "/api/v1/balances", nil)
	if status != http.StatusUnauthorized {
		t.Fatalf("GET /api/v1/balances: %d %v", status, out)
	}
}

func TestInventoryHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	auth := apitest.RegisterTenantAdmin(t, rbac.NewApp(cfg, sqlDB), "+7907", "INV Cover")
	wh := &apitest.Client{T: t, App: warehouses.NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	pr := &apitest.Client{T: t, App: products.NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	hit := map[string]struct{}{}
	mark := func(key string) { hit[key] = struct{}{} }

	c.MustOK("GET", "/health", nil)
	mark("GET /health")
	c.MustOK("GET", "/api/v1/delegation/grant-peers", nil)
	mark("GET /api/v1/delegation/grant-peers")

	from := apitest.Map(wh.MustStatus("POST", "/api/v1/stocks", map[string]any{"name": "Склад А", "type": "warehouse"}, http.StatusCreated)["stock"])
	to := apitest.Map(wh.MustStatus("POST", "/api/v1/stocks", map[string]any{"name": "Склад Б", "type": "warehouse"}, http.StatusCreated)["stock"])
	fromID := apitest.AsInt64(from["id"])
	toID := apitest.AsInt64(to["id"])
	prod := apitest.Map(pr.MustStatus("POST", "/api/v1/products", map[string]any{"name": "Одеяло HTTP"}, http.StatusCreated)["product"])
	productID := apitest.AsInt64(prod["id"])

	receipt := c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": productID, "stock_id": fromID, "qty": 20,
	}, http.StatusCreated)
	mark("POST /api/v1/movements")
	mid := apitest.AsInt64(apitest.Map(receipt["movement"])["id"])
	c.MustOK("GET", fmt.Sprintf("/api/v1/movements/%d", mid), nil)
	mark("GET /api/v1/movements/:id")
	c.MustOK("GET", "/api/v1/movements", nil)
	mark("GET /api/v1/movements")

	c.MustOK("GET", "/api/v1/balances", nil)
	mark("GET /api/v1/balances")
	c.MustOK("GET", "/api/v1/balances/summary", nil)
	mark("GET /api/v1/balances/summary")
	c.MustOK("GET", "/api/v1/reports/overview", nil)
	mark("GET /api/v1/reports/overview")

	c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": productID, "stock_id": fromID, "qty": 100,
	}, http.StatusConflict)

	rev := c.MustStatus("POST", fmt.Sprintf("/api/v1/movements/%d/reverse", mid), map[string]any{}, http.StatusCreated)
	if apitest.AsInt64(apitest.Map(rev["movement"])["id"]) == mid {
		t.Fatalf("сторно должно создать новое движение: %v", rev)
	}
	mark("POST /api/v1/movements/:id/reverse")
	twice := c.MustStatus("POST", fmt.Sprintf("/api/v1/movements/%d/reverse", mid), map[string]any{}, http.StatusConflict)
	assertConflictCode(t, twice, "already_reversed")

	c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": productID, "stock_id": fromID, "qty": 20,
	}, http.StatusCreated)

	c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "transfer", "product_id": productID,
		"from_stock_id": fromID, "to_stock_id": toID, "qty": 3,
	}, http.StatusCreated)

	c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "adjustment", "product_id": productID, "stock_id": toID, "qty_after": 3,
	}, http.StatusCreated)

	draft := c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": productID, "stock_id": fromID, "qty": 1, "status": "draft",
	}, http.StatusCreated)
	did := apitest.AsInt64(apitest.Map(draft["movement"])["id"])
	c.MustOK("POST", fmt.Sprintf("/api/v1/movements/%d/post", did), nil)
	mark("POST /api/v1/movements/:id/post")

	draft2 := c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": productID, "stock_id": fromID, "qty": 1, "post_immediately": false,
	}, http.StatusCreated)
	d2 := apitest.AsInt64(apitest.Map(draft2["movement"])["id"])
	c.MustOK("DELETE", fmt.Sprintf("/api/v1/movements/%d", d2), nil)
	mark("DELETE /api/v1/movements/:id")

	res := c.MustStatus("POST", "/api/v1/reserves", map[string]any{
		"product_id": productID, "stock_id": fromID, "qty": 2, "ref_code": "ord-http-1",
	}, http.StatusCreated)
	mark("POST /api/v1/reserves")
	rid := apitest.AsInt64(apitest.Map(res["reserve"])["id"])
	c.MustOK("GET", "/api/v1/reserves", nil)
	mark("GET /api/v1/reserves")
	c.MustOK("POST", fmt.Sprintf("/api/v1/reserves/%d/release", rid), nil)
	mark("POST /api/v1/reserves/:id/release")

	lot := c.MustStatus("POST", "/api/v1/lots", map[string]any{
		"product_id": productID, "batch_code": "B1", "lot_code": "L1",
	}, http.StatusCreated)
	mark("POST /api/v1/lots")
	lid := apitest.AsInt64(apitest.Map(lot["lot"])["id"])
	c.MustOK("GET", "/api/v1/lots", nil)
	mark("GET /api/v1/lots")
	c.MustOK("GET", fmt.Sprintf("/api/v1/lots/%d", lid), nil)
	mark("GET /api/v1/lots/:id")

	ord := c.MustStatus("POST", "/api/v1/orders", map[string]any{
		"stock_id": fromID,
		"lines":    []map[string]any{{"product_id": productID, "qty": 2}},
	}, http.StatusCreated)
	mark("POST /api/v1/orders")
	oid := apitest.AsInt64(apitest.Map(ord["order"])["id"])
	c.MustOK("GET", "/api/v1/orders", nil)
	mark("GET /api/v1/orders")
	c.MustOK("GET", fmt.Sprintf("/api/v1/orders/%d", oid), nil)
	mark("GET /api/v1/orders/:id")
	c.MustOK("POST", fmt.Sprintf("/api/v1/orders/%d/confirm", oid), nil)
	mark("POST /api/v1/orders/:id/confirm")
	c.MustOK("POST", fmt.Sprintf("/api/v1/orders/%d/fulfill", oid), nil)
	mark("POST /api/v1/orders/:id/fulfill")

	ord2 := c.MustStatus("POST", "/api/v1/orders", map[string]any{
		"stock_id": fromID,
		"lines":    []map[string]any{{"product_id": productID, "qty": 1}},
	}, http.StatusCreated)
	oid2 := apitest.AsInt64(apitest.Map(ord2["order"])["id"])
	c.MustOK("POST", fmt.Sprintf("/api/v1/orders/%d/cancel", oid2), nil)
	mark("POST /api/v1/orders/:id/cancel")

	for _, want := range inventoryLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван в сценарии: %s", want)
		}
	}
}

type invHTTPFixture struct {
	c            *apitest.Client
	db           *sql.DB
	cfg          config.Config
	fromID, toID int64
	productID    int64
}

func newInvHTTPFixture(t *testing.T, phonePrefix string) invHTTPFixture {
	t.Helper()
	sqlDB, cfg := apitest.OpenDB(t)
	auth := apitest.RegisterTenantAdmin(t, rbac.NewApp(cfg, sqlDB), phonePrefix, "INV Domain")
	wh := &apitest.Client{T: t, App: warehouses.NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	pr := &apitest.Client{T: t, App: products.NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB), Auth: true, Session: auth.Session}
	from := apitest.Map(wh.MustStatus("POST", "/api/v1/stocks", map[string]any{"name": "Склад А", "type": "warehouse"}, http.StatusCreated)["stock"])
	to := apitest.Map(wh.MustStatus("POST", "/api/v1/stocks", map[string]any{"name": "Склад Б", "type": "warehouse"}, http.StatusCreated)["stock"])
	prod := apitest.Map(pr.MustStatus("POST", "/api/v1/products", map[string]any{"name": "Одеяло домен"}, http.StatusCreated)["product"])
	return invHTTPFixture{
		c:         c,
		db:        sqlDB,
		cfg:       cfg,
		fromID:    apitest.AsInt64(from["id"]),
		toID:      apitest.AsInt64(to["id"]),
		productID: apitest.AsInt64(prod["id"]),
	}
}

func asQty(v any) float64 {
	switch x := v.(type) {
	case float64:
		return x
	case int:
		return float64(x)
	case int64:
		return float64(x)
	case json.Number:
		f, _ := x.Float64()
		return f
	case string:
		f, _ := strconv.ParseFloat(x, 64)
		return f
	default:
		var f float64
		fmt.Sscan(fmt.Sprint(v), &f)
		return f
	}
}

func invBalance(t *testing.T, c *apitest.Client, productID, stockID int64) (qty, reserved, available float64, found bool) {
	t.Helper()
	out := c.MustOK("GET", fmt.Sprintf("/api/v1/balances?product_id=%d&stock_id=%d", productID, stockID), nil)
	for _, item := range apitest.Slice(out["items"]) {
		row := apitest.Map(item)
		if apitest.AsInt64(row["product_id"]) == productID && apitest.AsInt64(row["stock_id"]) == stockID {
			return asQty(row["qty"]), asQty(row["qty_reserved"]), asQty(row["qty_available"]), true
		}
	}
	return 0, 0, 0, false
}

func assertQty(t *testing.T, c *apitest.Client, productID, stockID int64, want float64) {
	t.Helper()
	qty, _, _, found := invBalance(t, c, productID, stockID)
	if !found && want == 0 {
		return
	}
	if !found {
		t.Fatalf("остаток product=%d stock=%d не найден, ожидали qty=%v", productID, stockID, want)
	}
	if qty != want {
		t.Fatalf("остаток product=%d stock=%d: qty=%v, ожидали %v", productID, stockID, qty, want)
	}
}

func assertNotNegative(t *testing.T, c *apitest.Client, productID, stockID int64) {
	t.Helper()
	qty, _, _, found := invBalance(t, c, productID, stockID)
	if found && qty < 0 {
		t.Fatalf("остаток ушёл в минус: product=%d stock=%d qty=%v", productID, stockID, qty)
	}
}

func assertInsufficientBody(t *testing.T, out map[string]any) {
	t.Helper()
	blob := fmt.Sprintf("%v", out)
	if !strings.Contains(strings.ToLower(blob), "insufficient") && !strings.Contains(blob, "Недостаточно") {
		t.Fatalf("ожидали insufficient/Недостаточно: %v", out)
	}
}

func assertConflictCode(t *testing.T, out map[string]any, want string) {
	t.Helper()
	if fmt.Sprint(out["code"]) != want {
		t.Fatalf("ожидали code=%s: %v", want, out)
	}
}

func TestInventoryIssueWithoutStockConflict(t *testing.T) {
	f := newInvHTTPFixture(t, "+7910")
	out := f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": f.productID, "stock_id": f.fromID, "qty": 1,
	}, http.StatusConflict)
	assertInsufficientBody(t, out)
	assertNotNegative(t, f.c, f.productID, f.fromID)
}

func TestInventoryReceiptIssueReverseRestoresQty(t *testing.T) {
	f := newInvHTTPFixture(t, "+7911")
	f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": f.productID, "stock_id": f.fromID, "qty": 5,
	}, http.StatusCreated)
	issue := f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": f.productID, "stock_id": f.fromID, "qty": 3,
	}, http.StatusCreated)
	issueID := apitest.AsInt64(apitest.Map(issue["movement"])["id"])
	assertQty(t, f.c, f.productID, f.fromID, 2)

	rev := f.c.MustStatus("POST", fmt.Sprintf("/api/v1/movements/%d/reverse", issueID), map[string]any{}, http.StatusCreated)
	if apitest.AsInt64(apitest.Map(rev["movement"])["id"]) == issueID {
		t.Fatalf("сторно должно создать новое движение: %v", rev)
	}
	assertQty(t, f.c, f.productID, f.fromID, 5)

	again := f.c.MustStatus("POST", fmt.Sprintf("/api/v1/movements/%d/reverse", issueID), map[string]any{}, http.StatusConflict)
	assertConflictCode(t, again, "already_reversed")
	assertQty(t, f.c, f.productID, f.fromID, 5)
}

func TestInventoryConcurrentIssueDoesNotGoNegative(t *testing.T) {
	f := newInvHTTPFixture(t, "+7918")
	f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": f.productID, "stock_id": f.fromID, "qty": 5,
	}, http.StatusCreated)

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	serveErr := make(chan error, 1)
	go func() { serveErr <- f.c.App.Listener(ln) }()
	t.Cleanup(func() {
		_ = f.c.App.Shutdown()
		_ = ln.Close()
		select {
		case <-serveErr:
		case <-time.After(2 * time.Second):
		}
	})
	base := "http://" + ln.Addr().String()
	waitInventoryReady(t, base+"/health")

	const workers = 8
	type hit struct {
		status int
		body   map[string]any
	}
	ch := make(chan hit, workers)
	var wg sync.WaitGroup
	client := &http.Client{Timeout: 15 * time.Second}
	for i := 0; i < workers; i++ {
		i := i
		wg.Add(1)
		go func() {
			defer wg.Done()
			body, _ := json.Marshal(map[string]any{
				"movement_type": "issue", "product_id": f.productID, "stock_id": f.fromID, "qty": 3,
				"doc_number": fmt.Sprintf("http-race-%d-%d", time.Now().UnixNano(), i),
			})
			req, err := http.NewRequest(http.MethodPost, base+"/api/v1/movements", bytes.NewReader(body))
			if err != nil {
				ch <- hit{status: -1, body: map[string]any{"error": err.Error()}}
				return
			}
			req.Header.Set("Content-Type", "application/json")
			req.Header.Set("Authorization", "Bearer "+f.c.Session.Token)
			resp, err := client.Do(req)
			if err != nil {
				ch <- hit{status: -1, body: map[string]any{"error": err.Error()}}
				return
			}
			raw, _ := io.ReadAll(resp.Body)
			_ = resp.Body.Close()
			var out map[string]any
			_ = json.Unmarshal(raw, &out)
			ch <- hit{status: resp.StatusCode, body: out}
		}()
	}
	wg.Wait()
	close(ch)

	created, conflict := 0, 0
	for h := range ch {
		switch h.status {
		case http.StatusCreated:
			created++
		case http.StatusConflict:
			conflict++
			assertConflictCode(t, h.body, "insufficient_qty")
		default:
			t.Errorf("issue: status %d body=%v", h.status, h.body)
		}
	}
	if created != 1 || conflict != workers-1 {
		t.Fatalf("параллельные issue qty=3 при остатке 5: created=%d conflict=%d want 1/%d", created, conflict, workers-1)
	}

	req, err := http.NewRequest(http.MethodGet, fmt.Sprintf("%s/api/v1/balances?product_id=%d&stock_id=%d", base, f.productID, f.fromID), nil)
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Authorization", "Bearer "+f.c.Session.Token)
	resp, err := client.Do(req)
	if err != nil {
		t.Fatalf("GET balances: %v", err)
	}
	raw, _ := io.ReadAll(resp.Body)
	_ = resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("GET balances: %d %s", resp.StatusCode, raw)
	}
	var out map[string]any
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatalf("balances json: %v", err)
	}
	qty := 0.0
	found := false
	for _, item := range apitest.Slice(out["items"]) {
		row := apitest.Map(item)
		if apitest.AsInt64(row["product_id"]) == f.productID && apitest.AsInt64(row["stock_id"]) == f.fromID {
			qty = asQty(row["qty"])
			found = true
			break
		}
	}
	if !found {
		t.Fatal("остаток не найден после параллельных issue")
	}
	if qty != 2 {
		t.Fatalf("остаток после гонок: qty=%v, ожидали 2 (один issue из 3)", qty)
	}
	if qty < 0 {
		t.Fatalf("остаток ушёл в минус: qty=%v", qty)
	}
}

func waitInventoryReady(t *testing.T, healthURL string) {
	t.Helper()
	client := &http.Client{Timeout: 200 * time.Millisecond}
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		resp, err := client.Get(healthURL)
		if err == nil {
			_, _ = io.Copy(io.Discard, resp.Body)
			_ = resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				return
			}
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatalf("не дождались %s", healthURL)
}

func TestInventoryConcurrentIssueEngineDoesNotGoNegative(t *testing.T) {
	f := newInvHTTPFixture(t, "+7917")
	f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": f.productID, "stock_id": f.fromID, "qty": 5,
	}, http.StatusCreated)
	sess, err := service.NewSessionService(f.cfg, f.db).Authenticate(f.c.Session.Token)
	if err != nil || sess == nil {
		t.Fatalf("session: %v %#v", err, sess)
	}
	eng := NewEngine(f.db)
	const workers = 8
	start := make(chan struct{})
	type hit struct {
		status int
		out    map[string]any
	}
	ch := make(chan hit, workers)
	var wg sync.WaitGroup
	for i := 0; i < workers; i++ {
		i := i
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			out, st := eng.Post(sess, map[string]any{
				"movement_type": "issue", "product_id": f.productID, "stock_id": f.fromID, "qty": 3,
				"doc_number": fmt.Sprintf("eng-race-%d-%d", time.Now().UnixNano(), i),
			}, false)
			ch <- hit{status: st, out: out}
		}()
	}
	close(start)
	wg.Wait()
	close(ch)
	created, conflict := 0, 0
	for h := range ch {
		switch h.status {
		case http.StatusCreated:
			created++
		case http.StatusConflict:
			conflict++
			assertConflictCode(t, h.out, "insufficient_qty")
		default:
			t.Errorf("engine issue: status %d body=%v", h.status, h.out)
		}
	}
	if created != 1 || conflict != workers-1 {
		t.Fatalf("engine issue qty=3 при остатке 5: created=%d conflict=%d want 1/%d", created, conflict, workers-1)
	}
	assertQty(t, f.c, f.productID, f.fromID, 2)
	assertNotNegative(t, f.c, f.productID, f.fromID)
}

func TestInventoryTransferReverseRestoresSource(t *testing.T) {
	f := newInvHTTPFixture(t, "+7912")
	f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": f.productID, "stock_id": f.fromID, "qty": 5,
	}, http.StatusCreated)
	xfer := f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "transfer", "product_id": f.productID,
		"from_stock_id": f.fromID, "to_stock_id": f.toID, "qty": 3,
	}, http.StatusCreated)
	xferID := apitest.AsInt64(apitest.Map(xfer["movement"])["id"])
	assertQty(t, f.c, f.productID, f.fromID, 2)
	assertQty(t, f.c, f.productID, f.toID, 3)

	f.c.MustStatus("POST", fmt.Sprintf("/api/v1/movements/%d/reverse", xferID), map[string]any{}, http.StatusCreated)
	assertQty(t, f.c, f.productID, f.fromID, 5)
	assertNotNegative(t, f.c, f.productID, f.toID)
	assertQty(t, f.c, f.productID, f.toID, 0)
}

func TestInventoryDraftDoesNotChangeBalancesUntilPost(t *testing.T) {
	f := newInvHTTPFixture(t, "+7914")
	f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": f.productID, "stock_id": f.fromID, "qty": 5,
	}, http.StatusCreated)
	assertQty(t, f.c, f.productID, f.fromID, 5)

	draft := f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": f.productID, "stock_id": f.fromID, "qty": 4, "post_immediately": false,
	}, http.StatusCreated)
	mov := apitest.Map(draft["movement"])
	if fmt.Sprint(mov["status"]) != "draft" {
		t.Fatalf("ожидали status=draft: %v", draft)
	}
	draftID := apitest.AsInt64(mov["id"])
	assertQty(t, f.c, f.productID, f.fromID, 5)

	f.c.MustOK("POST", fmt.Sprintf("/api/v1/movements/%d/post", draftID), nil)
	assertQty(t, f.c, f.productID, f.fromID, 9)

	other := f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": f.productID, "stock_id": f.fromID, "qty": 1, "post_immediately": false,
	}, http.StatusCreated)
	otherID := apitest.AsInt64(apitest.Map(other["movement"])["id"])
	f.c.MustOK("DELETE", fmt.Sprintf("/api/v1/movements/%d", otherID), nil)
	assertQty(t, f.c, f.productID, f.fromID, 9)

	status, out := f.c.JSON("DELETE", fmt.Sprintf("/api/v1/movements/%d", draftID), nil)
	if status < 400 {
		t.Fatalf("DELETE posted должен быть ошибкой: %d %v", status, out)
	}
	assertQty(t, f.c, f.productID, f.fromID, 9)
}

func TestInventoryReserveBlocksOverAvailableAndIssue(t *testing.T) {
	f := newInvHTTPFixture(t, "+7915")
	f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": f.productID, "stock_id": f.fromID, "qty": 5,
	}, http.StatusCreated)

	over := f.c.MustStatus("POST", "/api/v1/reserves", map[string]any{
		"product_id": f.productID, "stock_id": f.fromID, "qty": 6, "ref_code": "ord-over-1",
	}, http.StatusConflict)
	if fmt.Sprint(over["code"]) != "insufficient_available" {
		t.Fatalf("ожидали code=insufficient_available: %v", over)
	}

	f.c.MustStatus("POST", "/api/v1/reserves", map[string]any{
		"product_id": f.productID, "stock_id": f.fromID, "qty": 2, "ref_code": "ord-hold-1",
	}, http.StatusCreated)
	qty, reserved, available, found := invBalance(t, f.c, f.productID, f.fromID)
	if !found {
		t.Fatal("остаток не найден после резерва")
	}
	if qty != 5 || reserved != 2 || available != 3 {
		t.Fatalf("после резерва qty=%v reserved=%v available=%v", qty, reserved, available)
	}

	out := f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "issue", "product_id": f.productID, "stock_id": f.fromID, "qty": 5,
	}, http.StatusConflict)
	assertInsufficientBody(t, out)
	assertQty(t, f.c, f.productID, f.fromID, 5)
}

func TestInventoryAdjustmentToTargetAndReverse(t *testing.T) {
	f := newInvHTTPFixture(t, "+7916")
	f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": f.productID, "stock_id": f.fromID, "qty": 10,
	}, http.StatusCreated)
	adj := f.c.MustStatus("POST", "/api/v1/movements", map[string]any{
		"movement_type": "adjustment", "product_id": f.productID, "stock_id": f.fromID, "qty_after": 7,
	}, http.StatusCreated)
	adjID := apitest.AsInt64(apitest.Map(adj["movement"])["id"])
	assertQty(t, f.c, f.productID, f.fromID, 7)

	f.c.MustStatus("POST", fmt.Sprintf("/api/v1/movements/%d/reverse", adjID), map[string]any{}, http.StatusCreated)
	assertQty(t, f.c, f.productID, f.fromID, 10)
}

func TestInventoryNegativeHTTP(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	rbacApp := rbac.NewApp(cfg, sqlDB)
	app := NewApp(cfg, sqlDB)
	admin := apitest.RegisterTenantAdmin(t, rbacApp, "+7923", "INV Neg")
	apitest.MustUnprocessable(apitest.DomainClient(t, app, admin.Session), "POST", "/api/v1/movements", map[string]any{})

	member := apitest.RegisterUserWithoutWrite(t, rbacApp, admin)
	apitest.MustForbidden(apitest.DomainClient(t, app, member.Session), "POST", "/api/v1/movements", map[string]any{
		"movement_type": "receipt", "product_id": 1, "stock_id": 1, "qty": 1,
	})
}
