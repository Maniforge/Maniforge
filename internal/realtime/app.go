// Package realtime — WebSocket-сервис для фронта (live notifications).
//
// Файл: app.go
// Назначение: Fiber + WS hub, RBAC auth, internal broadcast.
// См. также: cmd/realtime/main.go, docs/MANIFORGE_REALTIME.md
package realtime

import (
	"database/sql"
	"log"

	"github.com/gofiber/contrib/websocket"
	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	platformauth "maniforge/internal/platform/auth"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/service"
	rtHandler "maniforge/internal/realtime/handler"
	"maniforge/internal/realtime/hub"
	rtService "maniforge/internal/realtime/service"
)

// NewApp собирает Fiber-приложение Realtime.
func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{
		AppName:      "maniforge-realtime",
		ServerHeader: "maniforge-realtime",
	})

	app.Use(recover.New())
	app.Use(logger.New())
	app.Use(middleware.SecurityHeaders(cfg))
	if cfg.AppEnv == "local" || cfg.AppEnv == "testing" || cfg.AppEnv == "test" {
		app.Use(localCORS())
	}

	h := hub.New()
	svc := rtService.New(h)
	subSvc := rtService.NewSubscriptionService(sqlDB)
	sessions := service.NewSessionService(cfg, sqlDB)
	wsH := rtHandler.NewWS(sessions, subSvc, h)
	subsH := rtHandler.NewSubscriptions(subSvc)
	internalH := rtHandler.NewInternal(svc)
	internalGuard := platformauth.GuardServiceToken(cfg, cfg.TenantLicensingInternalToken)
	sessionAuth := func(c *fiber.Ctx) error {
		token := platformauth.BearerToken(c)
		sess, err := sessions.Authenticate(token)
		if err != nil {
			return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
		}
		if sess == nil {
			return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
		}
		c.Locals("maniforge_session", sess)
		return c.Next()
	}

	register := func(router fiber.Router) {
		router.Get("/health", func(c *fiber.Ctx) error {
			return httpx.OK(c, fiber.Map{
				"ok": true, "service": "realtime", "runtime": "go",
				"websocket": true,
			})
		})

		router.Get("/ws", wsH.UpgradeAuth, websocket.New(wsH.Serve))
		router.Post("/internal/v1/broadcast", internalGuard, internalH.Publish)

		delegated := rbacmw.DelegatedMutationGuard(cfg, sqlDB)
		api := router.Group("/api/v1", sessionAuth, delegated)
		api.Get("/ws/channels", subsH.SuggestChannels)
		api.Post("/subscriptions", subsH.Create)
		api.Get("/subscriptions", subsH.List)
		api.Get("/subscriptions/:id", subsH.Get)
		api.Patch("/subscriptions/:id", subsH.Update)
		api.Delete("/subscriptions/:id", subsH.Delete)
	}

	if sqlDB == nil {
		app.Use(func(c *fiber.Ctx) error {
			return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{"ok": false, "error": "database unavailable"})
		})
		return app
	}

	register(app)
	register(app.Group("/realtime"))

	app.Use(func(c *fiber.Ctx) error {
		return httpx.Fail(c, fiber.StatusNotFound, "not_found")
	})
	return app
}

func localCORS() fiber.Handler {
	return func(c *fiber.Ctx) error {
		c.Set("Access-Control-Allow-Origin", "*")
		c.Set("Access-Control-Allow-Headers", "Authorization, Content-Type, Sec-WebSocket-Protocol")
		c.Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
		if c.Method() == fiber.MethodOptions {
			return c.SendStatus(fiber.StatusNoContent)
		}
		return c.Next()
	}
}

// Listen запускает HTTP/WebSocket на cfg.RealtimeAddr.
func Listen(cfg config.Config, app *fiber.App) error {
	addr := cfg.RealtimeAddr
	log.Printf("maniforge-realtime listening on %s (env=%s)", addr, cfg.AppEnv)
	return app.Listen(addr)
}
