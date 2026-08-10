// Файл: internal.go
// Назначение: internal HTTP handlers (tenant lifecycle events).
// См. также: service/tenant_lifecycle.go, platform/auth/guard.go
package handler

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
)

type InternalHandler struct {
	lifecycle *service.TenantLifecycleService
}

func NewInternalHandler(cfg config.Config, db *sql.DB) *InternalHandler {
	sessions := repository.NewSessionRepository(db)
	return &InternalHandler{
		lifecycle: service.NewTenantLifecycleService(
			sessions,
			repository.NewAuditRepository(db),
			repository.NewSecurityEventRepository(db, cfg),
		),
	}
}

func (h *InternalHandler) TenantEvents(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.lifecycle.Receive(input)
	return httpx.JSON(c, status, payload)
}
