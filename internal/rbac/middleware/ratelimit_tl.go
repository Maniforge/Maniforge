// Файл: ratelimit_tl.go
// Назначение: rate limit для Tenant Licensing admin API + security event при превышении.
// См. также: ratelimit.go, docs/MANIFORGE_ENTERPRISE_HARDENING.md
package middleware

import (
	"database/sql"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
)

// TenantLicensingRateLimitGuard ограничивает admin API Tenant Licensing.
func TenantLicensingRateLimitGuard(cfg config.Config, db *sql.DB) fiber.Handler {
	repo := repository.NewRateLimitRepository(db)
	security := repository.NewSecurityEventRepository(db, cfg)
	return func(c *fiber.Ctx) error {
		if db == nil {
			return c.Next()
		}
		path := normalizePath(c.Path())
		ip := c.IP()
		if ip == "" {
			ip = "unknown"
		}
		bucket := rateBucketKey("tl-admin|"+ip, c.Method(), path)
		state, err := repo.Increment(bucket, cfg.TLRateLimitWindowSec)
		if err != nil {
			return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
		}
		if state.Count > cfg.TLRateLimitMax {
			_ = security.Write("tl.rate_limit.exceeded", nil, "", "", "warning", map[string]any{
				"ip": ip, "path": path, "method": strings.ToUpper(c.Method()), "count": state.Count,
			})
			return httpx.JSON(c, fiber.StatusTooManyRequests, fiber.Map{
				"ok": false, "error": "Слишком много запросов",
			})
		}
		return c.Next()
	}
}
