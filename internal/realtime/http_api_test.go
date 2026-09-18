package realtime

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/fasthttp/websocket"
	"maniforge/internal/platform/apitest"
	"maniforge/internal/rbac"
)

var realtimeLiveRoutes = []string{
	"GET /health",
	"GET /ws",
	"POST /internal/v1/broadcast",
	"GET /api/v1/ws/channels",
	"POST /api/v1/subscriptions",
	"GET /api/v1/subscriptions",
	"GET /api/v1/subscriptions/:id",
	"PATCH /api/v1/subscriptions/:id",
	"DELETE /api/v1/subscriptions/:id",
}

func TestRealtimeFiberRegistersAllLiveRoutes(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	apitest.AssertLiveRoutesExact(t, NewApp(cfg, sqlDB), realtimeLiveRoutes)
}

func TestRealtimeProtectedRoutesRequireAuth(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	c := &apitest.Client{T: t, App: NewApp(cfg, sqlDB)}
	status, out := c.JSON("GET", "/realtime/api/v1/subscriptions", nil)
	if status != http.StatusUnauthorized {
		t.Fatalf("GET subscriptions без токена: %d %v", status, out)
	}
}

func TestRealtimeHTTPAllLiveMethods(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	rbacApp := rbac.NewApp(cfg, sqlDB)
	rtApp := NewApp(cfg, sqlDB)

	password := "HttpApiCover!123"
	phone := apitest.UniquePhone("+7907")
	suffix := fmt.Sprintf("%d", time.Now().UnixNano()%1_000_000_000)
	auth := &apitest.Client{T: t, App: rbacApp}
	reg := auth.MustStatus("POST", "/rbac/api/v1/auth/register", map[string]any{
		"phone": phone, "password": password,
		"email":                 "rt_cover_" + suffix + "@example.test",
		"organization_name":     "RT Cover " + suffix,
		"platform_dpa_accepted": true,
		"consents":              []map[string]string{{"purpose_code": "account", "policy_version": "1.0"}},
	}, http.StatusCreated)
	tenant := apitest.Map(reg["tenant"])
	auth.Session.TenantID = fmt.Sprint(tenant["tenant_id"])
	auth.Session.SubtenantID = fmt.Sprint(tenant["subtenant_id"])
	auth.Tenant = true
	login := auth.MustOK("POST", "/rbac/api/v1/auth/login", map[string]any{
		"phone": phone, "password": password,
		"tenant_id": auth.Session.TenantID, "subtenant_id": auth.Session.SubtenantID,
	})
	sess := apitest.Map(login["session"])
	token := fmt.Sprint(sess["access_token"])
	if token == "" || token == "<nil>" {
		t.Fatalf("нет access_token: %v", login)
	}

	c := &apitest.Client{T: t, App: rtApp, Auth: true, Session: apitest.Session{
		Token: token, TenantID: auth.Session.TenantID, SubtenantID: auth.Session.SubtenantID,
	}}
	internal := &apitest.Client{T: t, App: rtApp, Auth: true, Session: apitest.Session{Token: apitest.InternalToken}}
	hit := map[string]struct{}{}
	mark := func(key string) { hit[key] = struct{}{} }

	c.MustOK("GET", "/realtime/health", nil)
	mark("GET /health")

	wsStatus, _, _ := c.Do("GET", "/realtime/ws", nil)
	if wsStatus != http.StatusUpgradeRequired && wsStatus != http.StatusBadRequest && wsStatus != http.StatusUnauthorized {
		t.Fatalf("GET /ws без upgrade: %d", wsStatus)
	}
	mark("GET /ws")

	c.MustOK("GET", "/realtime/api/v1/ws/channels", nil)
	mark("GET /api/v1/ws/channels")
	created := c.MustStatus("POST", "/realtime/api/v1/subscriptions", map[string]any{
		"name": "http-cover", "channels": []string{"notifications"},
	}, http.StatusCreated)
	mark("POST /api/v1/subscriptions")
	subID := apitest.AsInt64(apitest.Nested(created, "subscription", "id"))
	if subID == 0 {
		t.Fatalf("нет subscription.id: %v", created)
	}
	c.MustOK("GET", "/realtime/api/v1/subscriptions", nil)
	mark("GET /api/v1/subscriptions")
	c.MustOK("GET", fmt.Sprintf("/realtime/api/v1/subscriptions/%d", subID), nil)
	mark("GET /api/v1/subscriptions/:id")
	c.MustOK("PATCH", fmt.Sprintf("/realtime/api/v1/subscriptions/%d", subID), map[string]any{
		"name": "http-cover-2", "channels": []string{"notifications", "tenant"},
	})
	mark("PATCH /api/v1/subscriptions/:id")
	c.MustOK("DELETE", fmt.Sprintf("/realtime/api/v1/subscriptions/%d", subID), nil)
	mark("DELETE /api/v1/subscriptions/:id")

	internal.MustOK("POST", "/realtime/internal/v1/broadcast", map[string]any{
		"tenant_id": auth.Session.TenantID, "subtenant_id": auth.Session.SubtenantID,
		"channel": "notifications", "payload": map[string]any{"source": "http_api_cover"},
	})
	mark("POST /internal/v1/broadcast")

	for _, want := range realtimeLiveRoutes {
		if _, ok := hit[want]; !ok {
			t.Errorf("метод не вызван HTTP-тестом: %s", want)
		}
	}
}

func TestRealtimeWebSocketSessionBroadcast(t *testing.T) {
	sqlDB, cfg := apitest.OpenDB(t)
	auth := apitest.RegisterTenantAdmin(t, rbac.NewApp(cfg, sqlDB), "+7907", "RT WS")
	rtApp := NewApp(cfg, sqlDB)

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	serveErr := make(chan error, 1)
	go func() { serveErr <- rtApp.Listener(ln) }()
	t.Cleanup(func() {
		_ = rtApp.Shutdown()
		_ = ln.Close()
		select {
		case <-serveErr:
		case <-time.After(2 * time.Second):
		}
	})

	httpBase := "http://" + ln.Addr().String()
	wsURL := "ws://" + ln.Addr().String() + "/realtime/ws"
	waitRealtimeReady(t, httpBase+"/realtime/health")

	dialer := websocket.Dialer{HandshakeTimeout: 2 * time.Second}
	anonConn, anonResp, anonErr := dialer.Dial(wsURL, nil)
	if anonErr == nil {
		_ = anonConn.Close()
		t.Fatal("dial без токена должен быть отклонён")
	}
	if anonResp != nil {
		_, _ = io.Copy(io.Discard, anonResp.Body)
		_ = anonResp.Body.Close()
		if anonResp.StatusCode != http.StatusUnauthorized {
			t.Fatalf("dial без токена: want 401 got %d", anonResp.StatusCode)
		}
	}

	header := http.Header{}
	header.Set("Authorization", "Bearer "+auth.Session.Token)
	conn, resp, err := dialer.Dial(wsURL, header)
	if err != nil {
		t.Fatalf("ws dial: %v", err)
	}
	t.Cleanup(func() { _ = conn.Close() })
	if resp == nil || resp.StatusCode != http.StatusSwitchingProtocols {
		got := 0
		if resp != nil {
			got = resp.StatusCode
		}
		t.Fatalf("handshake: want 101 got %d", got)
	}

	if err := conn.WriteJSON(map[string]any{
		"type": "subscribe", "channels": []string{"notifications"},
	}); err != nil {
		t.Fatalf("subscribe: %v", err)
	}
	if err := waitWSType(conn, "subscribed", 2*time.Second); err != nil {
		t.Fatalf("ожидали subscribed: %v", err)
	}

	marker := fmt.Sprintf("ws-live-%d", time.Now().UnixNano())
	body, err := json.Marshal(map[string]any{
		"tenant_id": auth.Session.TenantID, "subtenant_id": auth.Session.SubtenantID,
		"channel": "notifications", "payload": map[string]any{"source": marker},
	})
	if err != nil {
		t.Fatal(err)
	}
	req, err := http.NewRequest(http.MethodPost, httpBase+"/realtime/internal/v1/broadcast", bytes.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Authorization", "Bearer "+apitest.InternalToken)
	req.Header.Set("Content-Type", "application/json")
	bcast, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatalf("broadcast http: %v", err)
	}
	rawBody, _ := io.ReadAll(bcast.Body)
	_ = bcast.Body.Close()
	if bcast.StatusCode != http.StatusOK {
		t.Fatalf("broadcast: %d %s", bcast.StatusCode, rawBody)
	}

	if err := waitWSPayload(conn, marker, 2*time.Second); err != nil {
		t.Fatalf("кадр с payload за 2s: %v", err)
	}
}

func waitRealtimeReady(t *testing.T, healthURL string) {
	t.Helper()
	client := &http.Client{Timeout: 200 * time.Millisecond}
	var last error
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		resp, err := client.Get(healthURL)
		if err == nil {
			_, _ = io.Copy(io.Discard, resp.Body)
			_ = resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				return
			}
			last = fmt.Errorf("health %d", resp.StatusCode)
		} else {
			last = err
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatalf("realtime listen не поднялся: %v", last)
}

func waitWSType(conn *websocket.Conn, wantType string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	_ = conn.SetReadDeadline(deadline)
	for time.Now().Before(deadline) {
		_, raw, err := conn.ReadMessage()
		if err != nil {
			return err
		}
		var msg map[string]any
		if err := json.Unmarshal(raw, &msg); err != nil {
			continue
		}
		if fmt.Sprint(msg["type"]) == wantType {
			return nil
		}
		if fmt.Sprint(msg["type"]) == "error" {
			return fmt.Errorf("ws error: %s", raw)
		}
	}
	return fmt.Errorf("нет type=%s", wantType)
}

func waitWSPayload(conn *websocket.Conn, marker string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	_ = conn.SetReadDeadline(deadline)
	for time.Now().Before(deadline) {
		_, raw, err := conn.ReadMessage()
		if err != nil {
			return err
		}
		if strings.Contains(string(raw), marker) {
			return nil
		}
	}
	return fmt.Errorf("нет payload %q", marker)
}
