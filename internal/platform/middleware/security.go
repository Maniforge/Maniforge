// Package middleware — общие HTTP-middleware для Maniforge.
//
// Файл: security.go
// Назначение: SecurityHeaders (CSP, nosniff, DENY frame, HSTS в production).
// См. также: internal/rbac/app.go, internal/tenantlicensing/app.go
package middleware

import (
	"fmt"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
)

// SecurityHeaders добавляет базовые заголовки безопасности ко всем ответам.
func SecurityHeaders(cfg config.Config) fiber.Handler {
	return func(c *fiber.Ctx) error {
		c.Set("X-Content-Type-Options", "nosniff")
		c.Set("X-Frame-Options", "DENY")
		c.Set("Referrer-Policy", "no-referrer")
		c.Set("Content-Security-Policy", "default-src 'none'; frame-ancestors 'none'; base-uri 'none';")
		if cfg.HSTSEnabled() {
			maxAge := cfg.RBACHSTSMaxAgeSec
			if maxAge < 1 {
				maxAge = 31536000
			}
			c.Set("Strict-Transport-Security", fmt.Sprintf("max-age=%d; includeSubDomains", maxAge))
		}
		return c.Next()
	}
}
