// Package middleware — RBAC-специфичные Fiber middleware.
//
// Файл: tenant.go
// Назначение: TenantResolver (заголовки/дефолты), SessionAuth (Bearer + сессия).
// Зависимости: service.SessionService, platform/auth, platform/code.
// См. также: internal/rbac/app.go, service/session.go
package middleware

import (
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/auth"
	"maniforge/internal/platform/code"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/service"
)

const (
	ctxTenantKey = "maniforge_tenant_context"
)

func TenantResolver(cfg config.Config) fiber.Handler {
	return func(c *fiber.Ctx) error {
		path := normalizePath(c.Path())
		if !requiresTenantAtEdge(path) {
			c.Locals(ctxTenantKey, service.TenantContext{Mode: cfg.TenancyMode})
			return c.Next()
		}

		mode := strings.ToLower(cfg.TenancyMode)
		if hinted := tenantFromHeadersOrBody(c); hinted.TenantID != "" && hinted.SubtenantID != "" {
			hinted.Mode = mode
			c.Locals(ctxTenantKey, hinted)
			return c.Next()
		}

		switch mode {
		case "disabled", "single":
			c.Locals(ctxTenantKey, service.TenantContext{
				Mode:        mode,
				TenantID:    cfg.DefaultTenantID,
				SubtenantID: cfg.DefaultSubtenantID,
			})
			return c.Next()
		}

		if path == "/api/v1/auth/login" && hasPhoneCredential(c) {
			c.Locals(ctxTenantKey, service.TenantContext{Mode: mode})
			return c.Next()
		}

		hinted := tenantFromHeadersOrBody(c)
		tenantID, subtenantID := hinted.TenantID, hinted.SubtenantID
		if tenantID == "" || subtenantID == "" {
			return httpx.JSON(c, fiber.StatusBadRequest, fiber.Map{
				"ok":    false,
				"error": "Для login в multi режиме укажите X-Tenant-ID и X-Subtenant-ID (или tenant_id/subtenant_id в теле)",
				"code":  "tenant_context_required",
			})
		}

		c.Locals(ctxTenantKey, service.TenantContext{
			Mode:        mode,
			TenantID:    tenantID,
			SubtenantID: subtenantID,
		})
		return c.Next()
	}
}

func TenantFromCtx(c *fiber.Ctx) service.TenantContext {
	if v, ok := c.Locals(ctxTenantKey).(service.TenantContext); ok {
		return v
	}
	return service.TenantContext{}
}

func SessionAuth(sessions *service.SessionService) fiber.Handler {
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

func normalizePath(path string) string {
	path = "/" + strings.TrimPrefix(path, "/")
	for _, base := range []string{"/rbac"} {
		if path == base || path == base+"/" {
			return "/"
		}
		if strings.HasPrefix(path, base+"/") {
			return "/" + strings.TrimPrefix(path[len(base):], "/")
		}
	}
	return path
}

func requiresTenantAtEdge(path string) bool {
	switch path {
	case "/api/v1/auth/login", "/api/v1/auth/register":
		return true
	default:
		return false
	}
}

func tenantFromHeadersOrBody(c *fiber.Ctx) service.TenantContext {
	tenantID := code.Normalize(c.Get("X-Tenant-ID"))
	subtenantID := code.Normalize(c.Get("X-Subtenant-ID"))
	if tenantID == "" || subtenantID == "" {
		var body map[string]string
		_ = c.BodyParser(&body)
		if tenantID == "" {
			tenantID = code.Normalize(body["tenant_id"])
		}
		if subtenantID == "" {
			subtenantID = code.Normalize(body["subtenant_id"])
		}
	}
	return service.TenantContext{TenantID: tenantID, SubtenantID: subtenantID}
}

func hasPhoneCredential(c *fiber.Ctx) bool {
	var body struct {
		Phone string `json:"phone"`
	}
	_ = c.BodyParser(&body)
	return strings.TrimSpace(body.Phone) != ""
}
