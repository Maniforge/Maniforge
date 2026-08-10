// Package handler — HTTP-обработчики Tenant Licensing.
//
// Файл: handler.go
// Назначение: access-state (tenant+project), tenants/plans/entitlements, events.
// См. также: repository/repository.go, internal/tenantlicensing/app.go
package handler

import (
	"database/sql"
	"strconv"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/tenantlicensing/repository"
)

type Handler struct {
	repo *repository.Repository
}

func New(db *sql.DB) *Handler {
	return &Handler{repo: repository.New(db)}
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{
		"ok":      true,
		"service": "tenant-licensing",
		"runtime": "go",
	})
}

// AccessStateProject — основной контракт: tenant + project (контур работ).
// Query ?workspace= — workspace (бывш. subtenant_id), если code проекта не уникален.
func (h *Handler) AccessStateProject(c *fiber.Ctx) error {
	workspace := c.Query("workspace")
	state := h.repo.AccessStateForProject(
		c.Params("tenantCode"),
		c.Params("projectCode"),
		workspace,
	)
	return httpx.OK(c, state)
}

// AccessState — legacy subtenant path (→ project main в workspace). Deprecated.
func (h *Handler) AccessState(c *fiber.Ctx) error {
	state := h.repo.AccessState(c.Params("tenantCode"), c.Params("subtenantCode"))
	return httpx.OK(c, state)
}

func (h *Handler) Tenants(c *fiber.Ctx) error {
	items, err := h.repo.ListTenants(100)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) Plans(c *fiber.Ctx) error {
	items, err := h.repo.ListPlans()
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) Entitlements(c *fiber.Ctx) error {
	tenantCode := c.Params("tenantCode")
	ent := h.repo.Entitlements(tenantCode)
	return httpx.OK(c, fiber.Map{
		"ok":           true,
		"tenant_code":  tenantCode,
		"entitlements": ent,
	})
}

func (h *Handler) UpdateTenant(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	result := h.repo.UpdateTenant(
		c.Params("tenantCode"),
		toString(input["name"]),
		toString(input["status"]),
		"tl_admin_api",
	)
	if !result.OK {
		return httpx.Fail(c, result.Status, result.Error)
	}
	return httpx.OK(c, fiber.Map{"ok": true})
}

func (h *Handler) UpdateSubtenant(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	result := h.repo.UpdateSubtenant(
		c.Params("tenantCode"),
		c.Params("subtenantCode"),
		toString(input["name"]),
		toString(input["status"]),
		"tl_admin_api",
	)
	if !result.OK {
		return httpx.Fail(c, result.Status, result.Error)
	}
	return httpx.OK(c, fiber.Map{"ok": true})
}

func (h *Handler) Events(c *fiber.Ctx) error {
	limit, _ := strconv.Atoi(c.Query("limit", "50"))
	items, err := h.repo.ListEvents(c.Query("tenant_code"), limit)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) PendingEvents(c *fiber.Ctx) error {
	items, err := h.repo.PendingEvents(50)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) AckEvent(c *fiber.Ctx) error {
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid event id")
	}
	ok, err := h.repo.AckEvent(id)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": ok})
}

func toString(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}
