// Файл: handler.go
// Назначение: HTTP handlers Inventory API.
package handler

import (
	"database/sql"
	"strconv"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	invsvc "maniforge/internal/inventory/service"
	"maniforge/internal/platform/httpx"
	rbacrepo "maniforge/internal/rbac/repository"
	rbacsvc "maniforge/internal/rbac/service"
)

type Handler struct {
	inv  *invsvc.InventoryService
	rbac *rbacsvc.RbacService
}

func New(cfg config.Config, db *sql.DB) *Handler {
	return &Handler{
		inv:  invsvc.NewInventoryService(cfg, db),
		rbac: rbacsvc.NewRbacService(rbacrepo.NewRoleRepository(db)),
	}
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{
		"ok": true, "service": "maniforge-inventory", "runtime": "go", "module": "inventory",
	})
}

func (h *Handler) ListBalances(c *fiber.Ctx) error {
	if st := h.guard(c, "inventory.read"); st != 0 {
		return nil
	}
	q := map[string]string{}
	for _, k := range []string{"product_id", "stock_id"} {
		if v := c.Query(k); v != "" {
			q[k] = v
		}
	}
	payload, status := h.inv.ListBalances(h.session(c), q)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) ListMovements(c *fiber.Ctx) error {
	if st := h.guard(c, "inventory.read"); st != 0 {
		return nil
	}
	q := map[string]string{"limit": c.Query("limit")}
	payload, status := h.inv.ListMovements(h.session(c), q)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) CreateMovement(c *fiber.Ctx) error {
	if st := h.guard(c, "inventory.write"); st != 0 {
		return nil
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.inv.PostMovement(h.session(c), input)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) PostDraft(c *fiber.Ctx) error {
	if st := h.guard(c, "inventory.write"); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.inv.PostDraft(h.session(c), id)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) RegisterLot(c *fiber.Ctx) error {
	if st := h.guard(c, "inventory.write"); st != 0 {
		return nil
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.inv.RegisterLot(h.session(c), input)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) CreateOrder(c *fiber.Ctx) error {
	if st := h.guard(c, "inventory.write"); st != 0 {
		return nil
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.inv.CreateOrder(h.session(c), input)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) ConfirmOrder(c *fiber.Ctx) error {
	if st := h.guard(c, "inventory.write"); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.inv.ConfirmOrder(h.session(c), id)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) FulfillOrder(c *fiber.Ctx) error {
	if st := h.guard(c, "inventory.write"); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.inv.FulfillOrder(h.session(c), id)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) guard(c *fiber.Ctx, perm string) int {
	session := h.session(c)
	if session == nil {
		_ = httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
		return fiber.StatusUnauthorized
	}
	ok, err := h.rbac.HasPermission(session.UserID, session.TenantID, session.SubtenantID, perm)
	if err != nil {
		_ = httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
		return fiber.StatusInternalServerError
	}
	if !ok {
		_ = httpx.JSON(c, fiber.StatusForbidden, fiber.Map{"ok": false, "error": "Недостаточно прав"})
		return fiber.StatusForbidden
	}
	return 0
}

func (h *Handler) session(c *fiber.Ctx) *rbacrepo.SessionRecord {
	s, _ := c.Locals("maniforge_session").(*rbacrepo.SessionRecord)
	return s
}
