// Package tenantlicensing — Fiber-приложение сервиса лицензий и tenant lifecycle.
//
// Файл: app.go
// Назначение: internal access-state, admin read API, events pending/ack.
// Зависимости: handler, platform/auth.GuardServiceToken.
// См. также: cmd/tenant-licensing/main.go, docs/MANIFORGE_GO_CODEMAP.md
package tenantlicensing

import (
	"database/sql"
	"log"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	"maniforge/internal/platform/auth"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/tenantlicensing/handler"
)

// NewApp собирает Fiber-приложение Tenant Licensing.
func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{
		AppName:      "maniforge-tenant-licensing",
		ServerHeader: "maniforge-tenant-licensing",
	})

	app.Use(recover.New())
	app.Use(logger.New())
	app.Use(middleware.SecurityHeaders(cfg))

	if sqlDB == nil {
		app.Use(func(c *fiber.Ctx) error {
			if c.Path() == "/health" || c.Path() == "/tenant-licensing/health" {
				return c.Next()
			}
			return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{
				"ok":    false,
				"error": "database unavailable",
			})
		})
	}

	health := func(c *fiber.Ctx) error {
		return httpx.OK(c, fiber.Map{
			"ok":      true,
			"service": "tenant-licensing",
			"runtime": "go",
			"db":      sqlDB != nil,
		})
	}

	register := func(router fiber.Router) {
		router.Get("/health", health)
	}

	if sqlDB != nil {
		h := handler.New(sqlDB)
		internalGuard := auth.GuardServiceToken(cfg, cfg.TenantLicensingInternalToken)
		adminGuard := auth.GuardServiceToken(cfg, cfg.TenantLicensingAdminToken)
		tlRateLimit := rbacmw.TenantLicensingRateLimitGuard(cfg, sqlDB)

		register = func(router fiber.Router) {
			router.Get("/health", h.Health)

			api := router.Group("/api/v1", tlRateLimit, adminGuard)
			api.Get("/tenants", h.Tenants)
			api.Patch("/tenants/:tenantCode", h.UpdateTenant)
			api.Patch("/tenants/:tenantCode/subtenants/:subtenantCode", h.UpdateSubtenant)
			api.Get("/plans", h.Plans)
			api.Get("/tenants/:tenantCode/entitlements", h.Entitlements)
			api.Get("/events", h.Events)

			internal := router.Group("/internal/v1", internalGuard)
			internal.Get("/tenants/:tenantCode/projects/:projectCode/access-state", h.AccessStateProject)
			internal.Get("/tenants/:tenantCode/subtenants/:subtenantCode/access-state", h.AccessState)
			internal.Get("/events/pending", h.PendingEvents)
			internal.Post("/events/:id/ack", h.AckEvent)
		}
	}

	register(app.Group("/tenant-licensing"))
	register(app)

	app.Use(func(c *fiber.Ctx) error {
		return httpx.Fail(c, fiber.StatusNotFound, "not_found")
	})

	return app
}

// Listen запускает HTTP-сервер на cfg.TLAddr (Tenant Licensing).
func Listen(cfg config.Config, app *fiber.App) error {
	addr := cfg.TLAddr
	log.Printf("maniforge-tenant-licensing listening on %s (env=%s)", addr, cfg.AppEnv)
	return app.Listen(addr)
}
