// Файл: delegated.go
// Назначение: блокировка мутаций для delegated read_only / operator (RBAC admin).
// См. также: service/delegated_access.go, app/Maniforge/Rbac/Http/Middleware/DelegatedMutationMiddleware.php
package middleware

import (
	"database/sql"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
)

// DelegatedMutationGuard блокирует mutating запросы при delegated grant.
func DelegatedMutationGuard(cfg config.Config, db *sql.DB) fiber.Handler {
	if db == nil {
		return func(c *fiber.Ctx) error { return c.Next() }
	}
	policy := service.NewDelegatedAccessService(cfg, db)
	return func(c *fiber.Ctx) error {
		method := strings.ToUpper(c.Method())
		if method == "GET" || method == "HEAD" || method == "OPTIONS" {
			return c.Next()
		}
		session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
		if !ok || session == nil {
			return c.Next()
		}
		decision := policy.AllowsHTTPMutation(session, c.Method(), normalizePath(c.Path()))
		if decision["ok"] == false {
			return httpx.JSON(c, fiber.StatusForbidden, decision)
		}
		return c.Next()
	}
}
