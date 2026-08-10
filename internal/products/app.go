// Package products — Fiber-приложение модуля номенклатуры.
package products

import (
	"database/sql"
	"log"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/service"
	"maniforge/internal/products/handler"
)

func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{AppName: "maniforge-products", ServerHeader: "maniforge-products"})
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
		api.Post("/products", h.CreateProduct)
		api.Get("/products/:id", h.GetProduct)
	}

	register(app)
	register(app.Group("/products"))
	app.Use(func(c *fiber.Ctx) error { return httpx.Fail(c, fiber.StatusNotFound, "not_found") })
	return app
}

func Listen(cfg config.Config, app *fiber.App) error {
	log.Printf("maniforge-products listening on %s (env=%s)", cfg.ProductsAddr, cfg.AppEnv)
	return app.Listen(cfg.ProductsAddr)
}
