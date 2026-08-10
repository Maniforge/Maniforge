// Файл: ws.go
// Назначение: WebSocket upgrade, ping/subscribe, pump read/write.
package handler

import (
	"encoding/json"
	"strconv"
	"time"

	"github.com/gofiber/contrib/websocket"
	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/auth"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
	"maniforge/internal/realtime/channel"
	"maniforge/internal/realtime/hub"
	"maniforge/internal/realtime/model"
	rtService "maniforge/internal/realtime/service"
)

type WSHandler struct {
	sessions *service.SessionService
	subs     *rtService.SubscriptionService
	hub      *hub.Hub
}

func NewWS(sessions *service.SessionService, subs *rtService.SubscriptionService, h *hub.Hub) *WSHandler {
	return &WSHandler{sessions: sessions, subs: subs, hub: h}
}

// UpgradeAuth проверяет RBAC access_token до WebSocket handshake.
func (h *WSHandler) UpgradeAuth(c *fiber.Ctx) error {
	if !websocket.IsWebSocketUpgrade(c) {
		return fiber.ErrUpgradeRequired
	}
	token := auth.BearerToken(c)
	session, err := h.sessions.Authenticate(token)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	if session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	c.Locals("maniforge_session", session)
	if q := c.Query("subscription_id"); q != "" {
		c.Locals("ws_subscription_id", q)
	}
	return c.Next()
}

func (h *WSHandler) Serve(conn *websocket.Conn) {
	session, _ := conn.Locals("maniforge_session").(*repository.SessionRecord)
	if session == nil {
		_ = conn.Close()
		return
	}

	client := hub.NewClient(session.TenantID, session.SubtenantID, session.UserID, conn)
	h.hub.Register(client)
	defer func() {
		h.hub.Unregister(client)
		_ = conn.Close()
	}()

	client.Send(model.Outbound{
		Type:     model.TypeConnected,
		OK:       true,
		TenantID: client.TenantID,
		UserID:   client.UserID,
		Channels: client.ChannelList(),
		Payload: map[string]any{
			"subtenant_id": client.SubtenantID,
			"hint":         "POST /api/v1/subscriptions или subscribe с subscription_id / channels",
		},
	})

	if raw, ok := conn.Locals("ws_subscription_id").(string); ok && raw != "" {
		if id, err := strconv.ParseInt(raw, 10, 64); err == nil && id > 0 {
			h.applySubscription(client, session, id)
		}
	}

	go writePump(client)
	readPump(h, client, session)
}

func readPump(h *WSHandler, client *hub.Client, session *repository.SessionRecord) {
	conn := client.Conn
	_ = conn.SetReadDeadline(time.Now().Add(90 * time.Second))
	conn.SetPongHandler(func(string) error {
		_ = conn.SetReadDeadline(time.Now().Add(90 * time.Second))
		return nil
	})

	for {
		_, raw, err := conn.ReadMessage()
		if err != nil {
			return
		}
		var in model.Inbound
		if err := json.Unmarshal(raw, &in); err != nil {
			client.Send(model.Outbound{Type: model.TypeError, OK: false, Error: "invalid json"})
			continue
		}
		switch in.Type {
		case model.TypePing:
			client.Send(model.Outbound{Type: model.TypePong, OK: true})
		case model.TypeSubscribe:
			if in.SubscriptionID > 0 {
				h.applySubscription(client, session, in.SubscriptionID)
				continue
			}
			if len(in.Channels) == 0 {
				client.Send(model.Outbound{Type: model.TypeError, OK: false, Error: "channels или subscription_id обязателен"})
				continue
			}
			valid := true
			for _, ch := range in.Channels {
				if !channel.ValidateClientChannel(ch) {
					client.Send(model.Outbound{Type: model.TypeError, OK: false, Error: channel.InvalidChannelError(ch)})
					valid = false
					break
				}
			}
			if !valid {
				continue
			}
			client.Subscribe(in.Channels)
			client.Send(model.Outbound{
				Type: model.TypeSubscribed, OK: true, Channels: client.ChannelList(),
			})
		default:
			client.Send(model.Outbound{Type: model.TypeError, OK: false, Error: "unknown type"})
		}
	}
}

func (h *WSHandler) applySubscription(client *hub.Client, session *repository.SessionRecord, id int64) {
	scope, err := rtService.ScopeFromSession(session)
	if err != nil {
		client.Send(model.Outbound{Type: model.TypeError, OK: false, Error: err.Error()})
		return
	}
	channels, err := h.subs.ChannelsForSubscription(scope, id)
	if err != nil {
		client.Send(model.Outbound{Type: model.TypeError, OK: false, Error: err.Error()})
		return
	}
	if len(channels) == 0 {
		client.Send(model.Outbound{Type: model.TypeError, OK: false, Error: "подписка не найдена"})
		return
	}
	client.Subscribe(channels)
	client.Send(model.Outbound{
		Type: model.TypeSubscribed, OK: true,
		SubscriptionID: id, Channels: client.ChannelList(),
	})
}

func writePump(client *hub.Client) {
	ticker := time.NewTicker(45 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case raw, ok := <-client.Outbound():
			if !ok {
				return
			}
			_ = client.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if err := client.Conn.WriteMessage(websocket.TextMessage, raw); err != nil {
				return
			}
		case <-ticker.C:
			_ = client.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if err := client.Conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}
