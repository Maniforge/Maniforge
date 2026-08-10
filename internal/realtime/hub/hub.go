// Package hub — in-memory pub/sub соединений WebSocket по tenant/workspace.
//
// Файл: hub.go
// Назначение: регистрация клиентов, подписки на каналы, broadcast.
// См. также: handler/ws.go, service/broadcast.go
package hub

import (
	"encoding/json"
	"sync"

	"github.com/gofiber/contrib/websocket"
	"maniforge/internal/realtime/channel"
	"maniforge/internal/realtime/model"
)

// Client — одно WS-подключение в контуре сессии.
type Client struct {
	TenantID    string
	SubtenantID string
	UserID      int64
	Conn        *websocket.Conn
	channels    map[string]struct{}
	outbound    chan []byte
}

// NewClient создаёт клиента с буфером исходящих сообщений.
func NewClient(tenantID, subtenantID string, userID int64, conn *websocket.Conn) *Client {
	return &Client{
		TenantID: tenantID, SubtenantID: subtenantID, UserID: userID,
		Conn: conn, outbound: make(chan []byte, 64),
	}
}

// Hub маршрутизирует события внутри процесса realtime.
type Hub struct {
	mu      sync.RWMutex
	clients map[*Client]struct{}
}

func New() *Hub {
	return &Hub{clients: make(map[*Client]struct{})}
}

func (h *Hub) Register(c *Client) {
	h.mu.Lock()
	h.clients[c] = struct{}{}
	h.mu.Unlock()
}

func (h *Hub) Unregister(c *Client) {
	h.mu.Lock()
	if _, ok := h.clients[c]; ok {
		delete(h.clients, c)
		close(c.outbound)
	}
	h.mu.Unlock()
}

func (c *Client) Subscribe(channels []string) {
	c.channels = make(map[string]struct{}, len(channels))
	for _, ch := range channels {
		if ch != "" {
			c.channels[ch] = struct{}{}
		}
	}
}

func (c *Client) ChannelList() []string {
	out := make([]string, 0, len(c.channels))
	for ch := range c.channels {
		out = append(out, ch)
	}
	return out
}

func (c *Client) Wants(eventChannel string, origin string) bool {
	if eventChannel == "" {
		return false
	}
	for sub := range c.channels {
		if channel.MatchesEvent(sub, eventChannel, origin) {
			return true
		}
	}
	return false
}

// Broadcast доставляет событие подписчикам tenant/subtenant на channel.
func (h *Hub) Broadcast(tenantID, subtenantID, channelName string, payload map[string]any) int {
	origin, _ := payload["origin"].(string)
	msg, err := json.Marshal(model.Outbound{
		Type:    model.TypeEvent,
		Channel: channelName,
		Payload: payload,
	})
	if err != nil {
		return 0
	}

	h.mu.RLock()
	defer h.mu.RUnlock()

	delivered := 0
	for client := range h.clients {
		if client.TenantID != tenantID || client.SubtenantID != subtenantID {
			continue
		}
		if !client.Wants(channelName, origin) {
			continue
		}
		select {
		case client.outbound <- msg:
			delivered++
		default:
		}
	}
	return delivered
}

// Outbound возвращает канал исходящих сообщений (для write pump).
func (c *Client) Outbound() <-chan []byte {
	return c.outbound
}

func (c *Client) SendRaw(raw []byte) {
	select {
	case c.outbound <- raw:
	default:
	}
}

func (c *Client) Send(out model.Outbound) {
	raw, err := json.Marshal(out)
	if err != nil {
		return
	}
	c.SendRaw(raw)
}
