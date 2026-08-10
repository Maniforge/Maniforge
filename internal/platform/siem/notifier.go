// Package siem — доставка security events во внешний SIEM (webhook).
//
// Файл: notifier.go
// Назначение: HTTP POST с HMAC-подписью, используется siem-forward и async dispatch.
// См. также: internal/rbac/repository/security_event.go, cmd/siem-forward/main.go
package siem

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"strings"
	"time"

	"maniforge/internal/config"
)

type Notifier struct {
	enabled bool
	url     string
	secret  []byte
	client  *http.Client
}

func NewNotifier(cfg config.Config) *Notifier {
	url := strings.TrimSpace(cfg.RBACSIEMWebhookURL)
	enabled := cfg.RBACSIEMWebhookEnabled && url != ""
	n := &Notifier{
		enabled: enabled,
		url:     url,
		client:  &http.Client{Timeout: time.Duration(max(1, cfg.TenantLicensingTimeoutSec)) * time.Second},
	}
	if s := strings.TrimSpace(cfg.RBACSIEMWebhookSecret); s != "" {
		n.secret = []byte(s)
	}
	return n
}

func (n *Notifier) Enabled() bool {
	return n.enabled
}

type Event struct {
	ID            int64          `json:"id,omitempty"`
	EventType     string         `json:"event_type"`
	TenantID      string         `json:"tenant_id"`
	SubtenantID   string         `json:"subtenant_id"`
	Severity      string         `json:"severity"`
	Payload       map[string]any `json:"payload"`
	IntegrityHash string         `json:"integrity_hash,omitempty"`
	CreatedAt     string         `json:"created_at,omitempty"`
}

func (n *Notifier) Deliver(ev Event) error {
	if !n.enabled {
		return nil
	}
	body, err := json.Marshal(ev)
	if err != nil {
		return err
	}
	req, err := http.NewRequest(http.MethodPost, n.url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "maniforge-siem/1.0")
	if len(n.secret) > 0 {
		mac := hmac.New(sha256.New, n.secret)
		_, _ = mac.Write(body)
		req.Header.Set("X-Maniforge-Signature", "sha256="+hex.EncodeToString(mac.Sum(nil)))
	}
	resp, err := n.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return &HTTPError{Status: resp.StatusCode}
	}
	return nil
}

type HTTPError struct {
	Status int
}

func (e *HTTPError) Error() string {
	return "siem webhook returned status " + http.StatusText(e.Status)
}

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}
