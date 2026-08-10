// Package publisher — HTTP-клиент broadcast в Realtime (из Manifest Engine и др.).
//
// Файл: publisher.go
// Назначение: POST /internal/v1/broadcast.
package publisher

import (
	"bytes"
	"encoding/json"
	"net/http"
	"strings"
	"time"

	"maniforge/internal/config"
)

type Client struct {
	baseURL string
	token   string
	http    *http.Client
}

func New(cfg config.Config) *Client {
	base := strings.TrimRight(cfg.RealtimeInternalURL, "/")
	if base == "" {
		base = "http://127.0.0.1:8097"
	}
	return &Client{
		baseURL: base,
		token:   cfg.TenantLicensingInternalToken,
		http:    &http.Client{Timeout: 2 * time.Second},
	}
}

func (c *Client) Enabled() bool {
	return c != nil && c.baseURL != ""
}

// Broadcast fire-and-forget; ошибки игнорируются (live optional).
func (c *Client) Broadcast(tenantID, subtenantID, channel string, payload map[string]any) {
	if c == nil || !c.Enabled() {
		return
	}
	body, _ := json.Marshal(map[string]any{
		"tenant_id": tenantID, "subtenant_id": subtenantID,
		"channel": channel, "payload": payload,
	})
	req, err := http.NewRequest(http.MethodPost, c.baseURL+"/internal/v1/broadcast", bytes.NewReader(body))
	if err != nil {
		return
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	if c.token != "" {
		req.Header.Set("Authorization", "Bearer "+c.token)
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return
	}
	_ = resp.Body.Close()
}
