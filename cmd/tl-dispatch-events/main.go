// Файл: main.go (cmd/tl-dispatch-events)
// Назначение: доставка pending TL events в RBAC POST /internal/v1/tenant-events.
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/tenantlicensing/repository"
)

func main() {
	limit := flag.Int("limit", 50, "max events per run")
	flag.Parse()

	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}
	rbacURL := cfg.RBACInternalHTTPURL()
	if rbacURL == "" {
		fmt.Fprintln(os.Stderr, "RBAC internal URL unavailable (set RBAC_INTERNAL_URL or MANIFORGE_RBAC_ADDR)")
		os.Exit(2)
	}
	token := cfg.RBACInternalTokenEffective()
	if token == "" {
		fmt.Fprintln(os.Stderr, "RBAC_INTERNAL_TOKEN or TENANT_LICENSING_INTERNAL_TOKEN required")
		os.Exit(2)
	}

	sqlDB, err := db.Open(cfg)
	if err != nil {
		log.Fatalf("db: %v", err)
	}
	defer sqlDB.Close()

	repo := repository.New(sqlDB)
	events, err := repo.PendingEventsRows(*limit)
	if err != nil {
		log.Fatalf("pending: %v", err)
	}

	timeout := time.Duration(max(1, cfg.TenantLicensingTimeoutSec)) * time.Second
	client := &http.Client{Timeout: timeout}
	endpoint := rbacURL + "/internal/v1/tenant-events"

	sent, failed := 0, 0
	for _, ev := range events {
		body := map[string]any{
			"event_type":     ev.EventType,
			"tenant_code":    ev.TenantCode,
			"subtenant_code": ev.SubtenantCode,
			"payload":        ev.DispatchPayload(),
		}
		raw, _ := json.Marshal(body)
		req, err := http.NewRequest(http.MethodPost, endpoint, bytes.NewReader(raw))
		if err != nil {
			failed++
			continue
		}
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Accept", "application/json")
		req.Header.Set("Authorization", "Bearer "+token)

		res, err := client.Do(req)
		if err != nil {
			failed++
			log.Printf("dispatch event %d: %v", ev.ID, err)
			continue
		}
		respBody, _ := io.ReadAll(res.Body)
		res.Body.Close()

		var decoded map[string]any
		_ = json.Unmarshal(respBody, &decoded)
		if res.StatusCode >= 200 && res.StatusCode < 300 && decoded["ok"] == true {
			if ok, _ := repo.AckEvent(ev.ID); ok {
				sent++
				continue
			}
		}
		failed++
		log.Printf("dispatch event %d failed: status=%d", ev.ID, res.StatusCode)
	}

	fmt.Fprintf(os.Stdout, "tl-dispatch-events: sent=%d failed=%d batch=%d\n", sent, failed, len(events))
	if failed > 0 {
		os.Exit(1)
	}
}

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}
