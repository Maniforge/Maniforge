// Package inventory — Fiber HTTP API остатков, проведения и сторно (порт PHP Inventory).
package inventory

import (
	"database/sql"
	"log"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	"maniforge/internal/licensingclient"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
	"maniforge/internal/supplychain"
)

type Handler struct {
	db   *sql.DB
	cfg  config.Config
	rbac *service.RbacService
	lic  *licensingclient.Client
	eng  *Engine
}

func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{AppName: "maniforge-inventory", ServerHeader: "maniforge-inventory"})
	app.Use(recover.New(), logger.New(), middleware.SecurityHeaders(cfg))
	if cfg.AppEnv == "local" || cfg.AppEnv == "testing" || cfg.AppEnv == "test" {
		app.Use(supplychain.LocalCORS())
	}
	h := &Handler{db: sqlDB, cfg: cfg}
	if sqlDB == nil {
		app.Get("/health", h.Health)
		app.Get("/inventory/health", h.Health)
		app.Use(func(c *fiber.Ctx) error {
			return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{"ok": false, "error": "database unavailable"})
		})
		return app
	}
	h.rbac = service.NewRbacService(repository.NewRoleRepository(sqlDB))
	h.lic = licensingclient.New(cfg, sqlDB)
	h.eng = NewEngine(sqlDB)
	sessions := service.NewSessionService(cfg, sqlDB)
	auth := rbacmw.SessionAuth(sessions)
	delegated := rbacmw.DelegatedMutationGuard(cfg, sqlDB)
	register := func(router fiber.Router) {
		router.Get("/health", h.Health)
		api := router.Group("/api/v1", auth, delegated)
		api.Get("/delegation/grant-peers", h.GrantPeers)
		api.Get("/balances", h.ListBalances)
		api.Get("/balances/summary", h.BalancesSummary)
		api.Get("/reports/overview", h.Overview)
		api.Get("/reserves", h.ListReserves)
		api.Post("/reserves", h.CreateReserve)
		api.Post("/reserves/:id/release", h.ReleaseReserve)
		api.Get("/movements", h.ListMovements)
		api.Post("/movements", h.CreateMovement)
		api.Get("/movements/:id", h.GetMovement)
		api.Post("/movements/:id/reverse", h.ReverseMovement)
		api.Post("/movements/:id/post", h.PostDraft)
		api.Delete("/movements/:id", h.CancelDraft)
		api.Get("/lots", h.ListLots)
		api.Post("/lots", h.CreateLot)
		api.Get("/lots/:id", h.GetLot)
		api.Get("/orders", h.ListOrders)
		api.Post("/orders", h.CreateOrder)
		api.Get("/orders/:id", h.GetOrder)
		api.Post("/orders/:id/confirm", h.ConfirmOrder)
		api.Post("/orders/:id/fulfill", h.FulfillOrder)
		api.Post("/orders/:id/cancel", h.CancelOrder)
	}
	register(app)
	register(app.Group("/inventory"))
	app.Use(func(c *fiber.Ctx) error { return httpx.Fail(c, fiber.StatusNotFound, "not_found") })
	return app
}

func Listen(cfg config.Config, app *fiber.App) error {
	log.Printf("maniforge-inventory listening on %s (env=%s)", cfg.InventoryAddr, cfg.AppEnv)
	return app.Listen(cfg.InventoryAddr)
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{"ok": true, "service": "inventory"})
}

func (h *Handler) guard(c *fiber.Ctx, perm string) (*repository.SessionRecord, error) {
	return supplychain.Guard(c, h.rbac, h.lic, perm)
}

func (h *Handler) GrantPeers(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	if !supplychain.HasAdminRole(h.rbac, sess) {
		return httpx.Fail(c, fiber.StatusForbidden, "Требуется tenant_admin")
	}
	items, err := supplychain.ListGrantPeers(h.db, sess.TenantID)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) ListBalances(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	items, err := h.eng.listBalances(sess, c.Query("product_id"), c.Query("stock_id"))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) BalancesSummary(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	items, err := h.eng.balancesSummary(sess)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) Overview(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	out, err := h.eng.overview(sess)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, out)
}

func (h *Handler) ListReserves(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	items, err := h.eng.listReserves(sess, c.Query("product_id"), c.Query("stock_id"), c.Query("ref_code"), c.Query("status"))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) CreateReserve(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	payload, status := h.eng.createReserve(sess, supplychain.Body(c))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) ReleaseReserve(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	payload, status := h.eng.releaseReserve(sess, int64(id))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) ListMovements(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	items, err := h.eng.listMovements(sess)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) CreateMovement(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	payload, status := h.eng.Post(sess, supplychain.Body(c), false)
	return supplychain.Result(c, payload, status)
}

func (h *Handler) GetMovement(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	m, err := h.eng.findMovement(sess, int64(id))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	if m == nil {
		return httpx.Fail(c, 404, "Движение не найдено")
	}
	return httpx.OK(c, fiber.Map{"ok": true, "movement": m})
}

func (h *Handler) ReverseMovement(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	payload, status := h.eng.Reverse(sess, int64(id), supplychain.Body(c))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) PostDraft(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	payload, status := h.eng.PostDraft(sess, int64(id))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) CancelDraft(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	payload, status := h.eng.CancelDraft(sess, int64(id))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) ListLots(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	items, err := h.eng.listLots(sess, c.Query("product_id"))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) CreateLot(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	payload, status := h.eng.createLot(sess, supplychain.Body(c))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) GetLot(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	lot, err := h.eng.getLot(sess, int64(id))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	if lot == nil {
		return httpx.Fail(c, 404, "Партия не найдена")
	}
	return httpx.OK(c, fiber.Map{"ok": true, "lot": lot})
}

func (h *Handler) ListOrders(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	items, err := h.eng.listOrders(sess)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) CreateOrder(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	payload, status := h.eng.createOrder(sess, supplychain.Body(c))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) GetOrder(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	o, err := h.eng.getOrder(sess, int64(id))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	if o == nil {
		return httpx.Fail(c, 404, "Заказ не найден")
	}
	return httpx.OK(c, fiber.Map{"ok": true, "order": o})
}

func (h *Handler) ConfirmOrder(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	payload, status := h.eng.confirmOrder(sess, int64(id))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) FulfillOrder(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	payload, status := h.eng.fulfillOrder(sess, int64(id))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) CancelOrder(c *fiber.Ctx) error {
	sess, err := h.guard(c, "inventory.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	payload, status := h.eng.cancelOrder(sess, int64(id))
	return supplychain.Result(c, payload, status)
}
