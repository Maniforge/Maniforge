// Package versioninghttp — HTTP API сервиса versioning.
package versioninghttp

import (
	"database/sql"
	"log"
	"strings"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	"maniforge/internal/platform/auth"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
	"maniforge/internal/versioning"
)

func NewApp(cfg config.Config, db *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{AppName: "maniforge-versioning", ServerHeader: "maniforge-versioning"})
	app.Use(recover.New(), logger.New(), middleware.SecurityHeaders(cfg))

	sessions := service.NewSessionService(cfg, db)
	roles := repository.NewRoleRepository(db)
	rbac := service.NewRbacService(roles)
	verRepo := versioning.NewRepository(db)

	register := func(router fiber.Router) {
		router.Get("/health", func(c *fiber.Ctx) error {
			return httpx.OK(c, fiber.Map{"ok": true, "service": "maniforge-versioning", "recording": cfg.VersioningEnabled})
		})

		api := router.Group("/api/v1")
		api.Use(sessionAuth(sessions))
		api.Get("/changes", func(c *fiber.Ctx) error {
			session, err := requirePermission(c, rbac, "versioning.read")
			if err != nil {
				return err
			}
			f := versioning.ChangeFilters{
				EntityTable: strings.TrimSpace(c.Query("entity_table")),
				EntityID:    strings.TrimSpace(c.Query("entity_id")),
				Operation:   strings.ToLower(strings.TrimSpace(c.Query("operation"))),
				Limit:       c.QueryInt("limit", 50),
				Offset:      c.QueryInt("offset", 0),
			}
			items, err := verRepo.ListInScope(session.TenantID, session.SubtenantID, f)
			if err != nil {
				return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
			}
			total, _ := verRepo.CountInScope(session.TenantID, session.SubtenantID, f)
			return httpx.OK(c, fiber.Map{"ok": true, "items": items, "total": total, "limit": f.Limit, "offset": f.Offset})
		})
		api.Get("/registry", func(c *fiber.Ctx) error {
			_, err := requirePermission(c, rbac, "versioning.registry.read")
			if err != nil {
				return err
			}
			items, err := verRepo.ListRegistry(true)
			if err != nil {
				return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
			}
			return httpx.OK(c, fiber.Map{"ok": true, "items": items})
		})
	}

	register(app.Group("/versioning"))
	register(app)

	app.Use(func(c *fiber.Ctx) error { return httpx.Fail(c, fiber.StatusNotFound, "not_found") })
	return app
}

func Listen(cfg config.Config, app *fiber.App) error {
	log.Printf("maniforge-versioning listening on %s", cfg.VersioningAddr)
	return app.Listen(cfg.VersioningAddr)
}

func sessionAuth(sessions *service.SessionService) fiber.Handler {
	return func(c *fiber.Ctx) error {
		token := auth.BearerToken(c)
		session, err := sessions.Authenticate(token)
		if err != nil {
			return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
		}
		if session == nil {
			return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
		}
		c.Locals("maniforge_session", session)
		return c.Next()
	}
}

func requirePermission(c *fiber.Ctx, rbac *service.RbacService, perm string) (*repository.SessionRecord, error) {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return nil, httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	has, err := rbac.HasPermission(session.UserID, session.TenantID, session.SubtenantID, perm)
	if err != nil {
		return nil, httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	if !has {
		return nil, httpx.Fail(c, fiber.StatusForbidden, "Недостаточно permissions")
	}
	return session, nil
}
