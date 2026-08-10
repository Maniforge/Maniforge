// Файл: message.go
// Назначение: JSON-протокол WebSocket (клиент ↔ realtime).
package model

// Inbound — сообщение от клиента.
type Inbound struct {
	Type             string   `json:"type"`
	Channels         []string `json:"channels"`
	SubscriptionID   int64    `json:"subscription_id"`
}

// Outbound — сообщение клиенту.
type Outbound struct {
	Type     string         `json:"type"`
	OK       bool           `json:"ok,omitempty"`
	Error    string         `json:"error,omitempty"`
	Channel  string         `json:"channel,omitempty"`
	Channels         []string `json:"channels,omitempty"`
	SubscriptionID   int64    `json:"subscription_id,omitempty"`
	TenantID         string   `json:"tenant_id,omitempty"`
	UserID   int64          `json:"user_id,omitempty"`
	Payload  map[string]any `json:"payload,omitempty"`
}

const (
	TypeConnected  = "connected"
	TypePing       = "ping"
	TypePong       = "pong"
	TypeSubscribe  = "subscribe"
	TypeSubscribed = "subscribed"
	TypeEvent      = "event"
	TypeError      = "error"
)
