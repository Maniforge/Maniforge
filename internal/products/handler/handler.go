// Файл: handler.go
// Назначение: HTTP handlers Products API.
package handler

import (
	"database/sql"
	"strconv"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	rbacrepo "maniforge/internal/rbac/repository"
	rbacsvc "maniforge/internal/rbac/service"
	"maniforge/internal/products/service"
)

type Handler struct {
	products *service.ProductService
	rbac     *rbacsvc.RbacService
}

func New(cfg config.Config, db *sql.DB) *Handler {
	return &Handler{
		products: service.NewProductService(cfg, db),
		rbac:     rbacsvc.NewRbacService(rbacrepo.NewRoleRepository(db)),
	}
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{
		"ok": true, "service": "maniforge-products", "runtime": "go", "module": "products",
	})
}

func (h *Handler) CreateProduct(c *fiber.Ctx) error {
	if st := h.guard(c, "products.write", true); st != 0 {
		return nil
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.products.CreateProduct(h.session(c), input)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) GetProduct(c *fiber.Ctx) error {
	if st := h.guard(c, "products.read", false); st != 0 {
		return nil
	}
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	query := map[string]string{"include": c.Query("include")}
	payload, status := h.products.GetProduct(h.session(c), id, query)
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
