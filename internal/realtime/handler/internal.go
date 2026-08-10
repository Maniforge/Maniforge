// Файл: internal.go
// Назначение: internal HTTP — publish событий в WebSocket hub.
package handler

import (
	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/realtime/service"
)

type InternalHandler struct {
	svc *service.Service
}

func NewInternal(svc *service.Service) *InternalHandler {
	return &InternalHandler{svc: svc}
}

func (h *InternalHandler) Publish(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.svc.Publish(input)
	return httpx.JSON(c, status, payload)
}
