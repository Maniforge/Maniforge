// Файл: pd_self.go
// Назначение: HTTP self-service ПДн (/me/personal-data*).
// См. также: service/pd_self.go, handler/pd_admin.go
package handler

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
)

type PDSelfHandler struct {
	pd    *service.PDSelfService
	guard *service.RequestGuard
}

func NewPDSelfHandler(cfg config.Config, db *sql.DB) *PDSelfHandler {
	roles := repository.NewRoleRepository(db)
	rbac := service.NewRbacService(roles)
	sessions := repository.NewSessionRepository(db)
	policies := service.NewPolicyService(repository.NewPolicyRuleRepository(db))
	actions := service.NewActionTokenService(cfg, repository.NewActionTokenRepository(db))
	mfa := service.NewMFAService(cfg, db)
	guard := service.NewRequestGuard(cfg, sessions, actions, rbac, policies, mfa)
	pdRepo := repository.NewPDRepository(db, cfg)
	return &PDSelfHandler{
		pd: service.NewPDSelfService(
			pdRepo,
			repository.NewUserRepository(db, cfg),
			repository.NewUserProfileRepository(db),
			sessions,
			repository.NewAuditRepository(db),
		),
		guard: guard,
	}
}

func (h *PDSelfHandler) ExportMe(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "me.personal_data.read", c, false); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.pd.ExportMe(session)
	return httpx.JSON(c, status, payload)
}

func (h *PDSelfHandler) ListConsents(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "me.consent.read", c, false); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.pd.ListConsents(session)
	return httpx.JSON(c, status, payload)
}

func (h *PDSelfHandler) GrantConsent(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "me.consent.manage", c, true); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.pd.GrantConsent(session, stringVal(input["purpose_code"]), stringVal(input["policy_version"]), c.IP(), c.Get("User-Agent"))
	return httpx.JSON(c, status, payload)
}

func (h *PDSelfHandler) RevokeConsent(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "me.consent.manage", c, true); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.pd.RevokeConsent(session, stringVal(input["purpose_code"]))
	return httpx.JSON(c, status, payload)
}

func (h *PDSelfHandler) ListSubjectRequests(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "me.personal_data.request", c, false); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.pd.ListSubjectRequests(session)
	return httpx.JSON(c, status, payload)
}

func (h *PDSelfHandler) CreateSubjectRequest(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "me.personal_data.request", c, true); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payloadMap, _ := input["payload"].(map[string]any)
	payload, status := h.pd.CreateSubjectRequest(session, stringVal(input["request_type"]), payloadMap)
	return httpx.JSON(c, status, payload)
}
