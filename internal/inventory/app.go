// Package inventory — Fiber-приложение модуля складского учёта.
package inventory

import (
	"database/sql"
	"log"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	"maniforge/internal/inventory/handler"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/service"
)

func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{AppName: "maniforge-inventory", ServerHeader: "maniforge-inventory"})
	app.Use(recover.New(), logger.New(), middleware.SecurityHeaders(cfg))

	if sqlDB == nil {
		app.Get("/health", func(c *fiber.Ctx) error {
			return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{"ok": false, "error": "database unavailable"})
		})
		return app
	}

	sessions := service.NewSessionService(cfg, sqlDB)
	auth := rbacmw.SessionAuth(sessions)
	delegated := rbacmw.DelegatedMutationGuard(cfg, sqlDB)
	h := handler.New(cfg, sqlDB)

	register := func(router fiber.Router) {
		router.Get("/health", h.Health)
		api := router.Group("/api/v1", auth, delegated)
		api.Get("/balances", h.ListBalances)
		api.Get("/movements", h.ListMovements)
		api.Post("/movements", h.CreateMovement)
		api.Post("/movements/:id/post", h.PostDraft)
		api.Post("/lots", h.RegisterLot)
		api.Post("/orders", h.CreateOrder)
		api.Post("/orders/:id/confirm", h.ConfirmOrder)
		api.Post("/orders/:id/fulfill", h.FulfillOrder)
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
