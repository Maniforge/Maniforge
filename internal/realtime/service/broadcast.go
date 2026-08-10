// Package service — бизнес-логика Realtime (broadcast, валидация).
//
// Файл: broadcast.go
// Назначение: internal publish API для других микросервисов.
// См. также: hub/hub.go, handler/internal.go
package service

import (
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/code"
	"maniforge/internal/realtime/hub"
)

type Service struct {
	hub *hub.Hub
}

func New(h *hub.Hub) *Service {
	return &Service{hub: h}
}

func (s *Service) Publish(input map[string]any) (map[string]any, int) {
	tenantID := code.Normalize(stringVal(input["tenant_id"]))
	if tenantID == "" {
		tenantID = code.Normalize(stringVal(input["tenant_code"]))
	}
	subtenantID := code.Normalize(stringVal(input["subtenant_id"]))
	if subtenantID == "" {
		subtenantID = code.Normalize(stringVal(input["subtenant_code"]))
	}
	channel := strings.TrimSpace(stringVal(input["channel"]))
	if channel == "" {
		channel = "notifications"
	}
	payload, _ := input["payload"].(map[string]any)
	if payload == nil {
		payload = map[string]any{}
	}
	if tenantID == "" {
		return fail("tenant_id обязателен", fiber.StatusUnprocessableEntity)
	}
	if subtenantID == "" {
		subtenantID = "main"
	}

	delivered := s.hub.Broadcast(tenantID, subtenantID, channel, payload)
	return ok(map[string]any{
		"delivered": delivered,
		"tenant_id": tenantID, "subtenant_id": subtenantID, "channel": channel,
	}, fiber.StatusOK)
}

func stringVal(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}

func fail(msg string, status int) (map[string]any, int) {
	return map[string]any{"ok": false, "error": msg, "status": status}, status
}

func ok(payload map[string]any, status int) (map[string]any, int) {
	payload["ok"] = true
	payload["status"] = status
	return payload, status
}
