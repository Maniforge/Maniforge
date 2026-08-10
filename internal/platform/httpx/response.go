// Package httpx — единый формат JSON-ответов Fiber-сервисов.
//
// Файл: response.go
// Назначение: OK, Fail, JSON; поле ok/error как в PHP API.
// См. также: internal/rbac/handler/*, docs/MANIFORGE_GO_CODEMAP.md
package httpx

import "github.com/gofiber/fiber/v2"

// JSON отправляет произвольный JSON с заданным HTTP-статусом.
func JSON(c *fiber.Ctx, status int, payload any) error {
	return c.Status(status).JSON(payload)
}

// OK — ответ 200 с телом payload.
func OK(c *fiber.Ctx, payload any) error {
	return JSON(c, fiber.StatusOK, payload)
}

// Fail — ответ с ok:false и полем error (как PHP JsonResponse).
func Fail(c *fiber.Ctx, status int, message string) error {
	body := fiber.Map{"ok": false, "error": message}
	if status == fiber.StatusTooManyRequests {
		body["error"] = "Слишком много запросов"
	}
	return JSON(c, status, body)
}
