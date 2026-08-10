// Файл: health.go
// Назначение: GET /health — статус сервиса и PostgreSQL.
// Зависимости: internal/db.Ping, config.TenancyMode.
// См. также: internal/rbac/app.go
package handler

import (
	"context"
	"database/sql"
	"time"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/platform/httpx"
)

type Health struct {
	cfg config.Config
	sql *sql.DB
}

func NewHealth(cfg config.Config, sqlDB *sql.DB) *Health {
	return &Health{cfg: cfg, sql: sqlDB}
}

func (h *Health) Handle(c *fiber.Ctx) error {
	ctx, cancel := context.WithTimeout(c.Context(), 2*time.Second)
	defer cancel()

	dbStatus := "unconfigured"
	if h.sql != nil {
		dbStatus = "up"
		if err := db.Ping(ctx, h.sql); err != nil {
			dbStatus = "down"
		}
	}

	return httpx.OK(c, fiber.Map{
		"ok":           true,
		"service":      "maniforge-rbac",
		"runtime":      "go",
		"status":       "up",
		"db":           dbStatus,
		"db_engine":    "postgresql",
		"tenancy_mode": h.cfg.TenancyMode,
		"timestamp":    time.Now().UTC().Format(time.RFC3339),
	})
}
