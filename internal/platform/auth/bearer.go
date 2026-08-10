// Package auth — извлечение и проверка токенов в HTTP-запросах.
//
// Файл: bearer.go
// Назначение: BearerToken из Authorization или query ?token=.
// См. также: guard.go, internal/rbac/middleware/tenant.go
package auth

import (
	"strings"

	"github.com/gofiber/fiber/v2"
)

// BearerToken извлекает токен из Authorization: Bearer или ?token=.
func BearerToken(c *fiber.Ctx) string {
	header := strings.TrimSpace(c.Get("Authorization"))
	if header == "" {
		return strings.TrimSpace(c.Query("token"))
	}
	const prefix = "Bearer "
	if strings.HasPrefix(header, prefix) {
		return strings.TrimSpace(header[len(prefix):])
	}
	return header
}
