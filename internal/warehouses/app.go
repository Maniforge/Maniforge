// Package warehouses — Fiber-приложение модуля WMS (складские узлы).
package warehouses

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
	"maniforge/internal/warehouses/handler"
)

func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{AppName: "maniforge-warehouses", ServerHeader: "maniforge-warehouses"})
	app.Use(recover.New(), logger.New(), middleware.SecurityHeaders(cfg))
	if cfg.AppEnv == "local" || cfg.AppEnv == "testing" || cfg.AppEnv == "test" {
		app.Use(localCORS())
	}

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
		api.Get("/stock-types", h.ListTypes)
		api.Get("/stocks", h.ListStocks)
		api.Get("/stocks/tree", h.Tree)
		api.Get("/delegation/grant-peers", h.GrantPeers)
		api.Post("/stocks", h.CreateStock)
		api.Get("/stocks/:id", h.GetStock)
		api.Patch("/stocks/:id", h.PatchStock)
		api.Put("/stocks/:id", h.PatchStock)
		api.Delete("/stocks/:id", h.DeleteStock)
		api.Post("/stocks/:id/external-meta", h.BindExternal)
		api.Get("/stocks/:id/audit", h.Audit)
	}

	register(app)
	register(app.Group("/warehouses"))
	app.Use(func(c *fiber.Ctx) error { return httpx.Fail(c, fiber.StatusNotFound, "not_found") })
	return app
}

func localCORS() fiber.Handler {
	return func(c *fiber.Ctx) error {
		c.Set("Access-Control-Allow-Origin", "*")
		c.Set("Access-Control-Allow-Headers", "Authorization, Content-Type, Accept")
		c.Set("Access-Control-Allow-Methods", "GET, POST, PATCH, PUT, DELETE, OPTIONS")
		if c.Method() == fiber.MethodOptions {
			return c.SendStatus(fiber.StatusNoContent)
		}
		return c.Next()
	}
}

func Listen(cfg config.Config, app *fiber.App) error {
	log.Printf("maniforge-warehouses listening on %s (env=%s)", cfg.WarehousesAddr, cfg.AppEnv)
	return app.Listen(cfg.WarehousesAddr)
}
