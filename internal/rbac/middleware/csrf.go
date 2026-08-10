// Файл: csrf.go
// Назначение: CSRF-проверка для mutating запросов с Bearer-сессией.
// См. также: repository/session.go, app/Maniforge/Rbac/Http/Middleware/CsrfMiddleware.php
package middleware

import (
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
)

func CsrfGuard(sessions *repository.SessionRepository) fiber.Handler {
	return func(c *fiber.Ctx) error {
		method := strings.ToUpper(c.Method())
		if method == "GET" || method == "HEAD" || method == "OPTIONS" {
			return c.Next()
		}
		path := normalizePath(c.Path())
		if csrfExempt(path) {
			return c.Next()
		}
		session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
		if !ok || session == nil {
			return c.Next()
		}
		token := strings.TrimSpace(c.Get("X-CSRF-Token"))
		if token == "" {
			var body map[string]string
			_ = c.BodyParser(&body)
			token = strings.TrimSpace(body["csrf_token"])
		}
		if !sessions.ValidateCsrfToken(session.ID, token) {
			return httpx.JSON(c, fiber.StatusForbidden, fiber.Map{
				"ok": false, "error": "Неверный CSRF-токен",
			})
		}
		return c.Next()
	}
}

func csrfExempt(path string) bool {
	switch path {
	case "/api/v1/auth/login", "/api/v1/auth/register", "/api/v1/auth/refresh", "/internal/v1/tenant-events":
		return true
	default:
		return false
	}
}
