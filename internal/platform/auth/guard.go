// Файл: guard.go
// Назначение: middleware GuardServiceToken для internal/admin API.
// Зависимости: constant-time compare, config.AppEnv (local без токена).
// См. также: bearer.go, internal/tenantlicensing/app.go
package auth

import (
	"crypto/subtle"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
)

// GuardServiceToken защищает internal/admin маршруты ожидаемым service token.
func GuardServiceToken(cfg config.Config, expected string) fiber.Handler {
	return func(c *fiber.Ctx) error {
		if expected == "" {
			env := strings.ToLower(cfg.AppEnv)
			switch env {
			case "local", "testing", "test":
				return c.Next()
			default:
				return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{
					"ok":    false,
					"error": "Service token не настроен",
				})
			}
		}

		provided := BearerToken(c)
		if subtle.ConstantTimeCompare([]byte(expected), []byte(provided)) == 1 {
			return c.Next()
		}

		return httpx.JSON(c, fiber.StatusUnauthorized, fiber.Map{
			"ok":    false,
			"error": "Неверный service token",
		})
	}
}
