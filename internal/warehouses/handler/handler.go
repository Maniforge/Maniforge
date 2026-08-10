// Файл: handler.go
// Назначение: HTTP handlers Warehouses API.
package handler

import (
	"database/sql"
	"strconv"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	rbacrepo "maniforge/internal/rbac/repository"
	rbacsvc "maniforge/internal/rbac/service"
	"maniforge/internal/warehouses/service"
)

type Handler struct {
	stocks *service.StockService
	rbac   *rbacsvc.RbacService
}

func New(cfg config.Config, db *sql.DB) *Handler {
	return &Handler{
		stocks: service.NewStockService(cfg, db),
		rbac:   rbacsvc.NewRbacService(rbacrepo.NewRoleRepository(db)),
	}
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{
		"ok": true, "service": "maniforge-warehouses", "runtime": "go", "module": "warehouses",
	})
}

func (h *Handler) ListTypes(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.types.read", false); st != 0 {
		return nil
	}
	payload := h.stocks.ListTypes()
	return httpx.JSON(c, intVal(payload["status"]), payload)
}

func (h *Handler) ListStocks(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.read", false); st != 0 {
		return nil
	}
	session := h.session(c)
	query := map[string]string{}
	for _, k := range []string{"type", "search", "status", "roots_only", "parent_id"} {
		if v := c.Query(k); v != "" {
			query[k] = v
		}
	}
	payload, status := h.stocks.ListStocks(session, query)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) Tree(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.read", false); st != 0 {
		return nil
	}
	session := h.session(c)
	query := map[string]string{"status": c.Query("status")}
	payload, status := h.stocks.Tree(session, query)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) GrantPeers(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.read", false); st != 0 {
		return nil
	}
	payload, status := h.stocks.ListGrantPeers(h.session(c))
	return httpx.JSON(c, status, payload)
}

func (h *Handler) CreateStock(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.write", true); st != 0 {
		return nil
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.stocks.CreateStock(h.session(c), input)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) GetStock(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.read", false); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.stocks.GetStock(h.session(c), id)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) PatchStock(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.write", true); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.stocks.UpdateStock(h.session(c), id, input)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) DeleteStock(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.delete", true); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.stocks.ArchiveStock(h.session(c), id)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) BindExternal(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.write", true); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.stocks.BindExternal(h.session(c), id, input)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) Audit(c *fiber.Ctx) error {
	if st := h.guard(c, "warehouses.audit.read", false); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	limit, _ := strconv.Atoi(c.Query("limit", "50"))
	payload, status := h.stocks.StockAudit(h.session(c), id, limit)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) guard(c *fiber.Ctx, perm string, _ bool) int {
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

func intVal(v any) int {
	switch t := v.(type) {
	case int:
		return t
	case float64:
		return int(t)
	default:
		return fiber.StatusOK
	}
}
