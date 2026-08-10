// Package manifestengine — Fiber-приложение Manifest Engine (/api/data).
//
// Файл: app.go
// Назначение: маршруты manifests CRUD и dynamic data API.
// См. также: cmd/manifest-engine/main.go, docs/MANIFORGE_MANIFEST_ENGINE.md
package manifestengine

import (
	"database/sql"
	"log"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	"maniforge/internal/dataplane"
	"maniforge/internal/manifestengine/handler"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/service"
)

// NewApp собирает Fiber-приложение Manifest Engine.
func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{
		AppName:      "maniforge-manifest-engine",
		ServerHeader: "maniforge-manifest-engine",
	})

	app.Use(recover.New())
	app.Use(logger.New())
	app.Use(middleware.SecurityHeaders(cfg))
	if cfg.AppEnv == "local" || cfg.AppEnv == "testing" || cfg.AppEnv == "test" {
		app.Use(localCORS())
	}

	dp := dataplane.NewResolver(cfg, sqlDB)
	h := handler.New(cfg, sqlDB, dp)

	if sqlDB == nil {
		app.Get("/health", h.Health)
		app.Get("/manifest-engine/health", h.Health)
		app.Use(func(c *fiber.Ctx) error {
			return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{"ok": false, "error": "database unavailable"})
		})
		return app
	}

	sessions := service.NewSessionService(cfg, sqlDB)
	auth := rbacmw.SessionAuth(sessions)
	delegated := rbacmw.DelegatedMutationGuard(cfg, sqlDB)

	register := func(router fiber.Router) {
		router.Get("/health", h.Health)

		api := router.Group("/api/v1", auth, delegated)
		api.Get("/catalog/field-types", h.ListFieldTypes)
		api.Get("/manifests/presets", h.ListPresets)
		api.Post("/manifests/presets/:code", h.InstallPreset)
		api.Post("/manifests", h.CreateManifest)
		api.Get("/manifests", h.ListManifests)
		api.Get("/manifests/:code", h.GetManifest)
		api.Patch("/manifests/:code", h.UpdateManifest)
		api.Delete("/manifests/:code", h.DeleteManifest)
		api.Get("/manifests/:code/openapi.yaml", h.OpenAPIYAML)
		api.Get("/manifests/:code/openapi", h.OpenAPI)

		data := router.Group("/api/data", auth, delegated)
		data.Get("/:entity", h.ListRecords)
		data.Post("/:entity", h.CreateRecord)
		data.Get("/:entity/:id", h.GetRecord)
		data.Patch("/:entity/:id", h.PatchRecord)
		data.Delete("/:entity/:id", h.DeleteRecord)
		data.Put("/:entity/:id/*", h.PutField)
		data.Delete("/:entity/:id/*", h.DeleteField)
	}

	register(app)
	register(app.Group("/manifest-engine"))

	app.Use(func(c *fiber.Ctx) error {
		return httpx.Fail(c, fiber.StatusNotFound, "not_found")
	})

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

// Listen запускает HTTP на cfg.ManifestEngineAddr.
func Listen(cfg config.Config, app *fiber.App) error {
	addr := cfg.ManifestEngineAddr
	log.Printf("maniforge-manifest-engine listening on %s (env=%s)", addr, cfg.AppEnv)
	return app.Listen(addr)
}
