// Файл: pd_admin.go
// Назначение: PD admin HTTP handlers.
// См. также: service/pd_admin.go, handler/privacy.go
package handler

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
)

type PDAdminHandler struct {
	pd    *service.PDAdminService
	guard *service.RequestGuard
}

func NewPDAdminHandler(cfg config.Config, db *sql.DB) *PDAdminHandler {
	roles := repository.NewRoleRepository(db)
	rbac := service.NewRbacService(roles)
	sessions := repository.NewSessionRepository(db)
	policyRepo := repository.NewPolicyRuleRepository(db)
	policies := service.NewPolicyService(policyRepo)
	actions := service.NewActionTokenService(cfg, repository.NewActionTokenRepository(db))
	mfa := service.NewMFAService(cfg, db)
	guard := service.NewRequestGuard(cfg, sessions, actions, rbac, policies, mfa)
	pdRepo := repository.NewPDRepository(db, cfg)
	return &PDAdminHandler{
		pd:    service.NewPDAdminService(pdRepo, repository.NewAuditRepository(db)),
		guard: guard,
	}
}

func (h *PDAdminHandler) GetOperatorProfile(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.pd.operator.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.pd.GetOperatorProfile(session)
	return httpx.JSON(c, status, payload)
}

func (h *PDAdminHandler) PutOperatorProfile(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.pd.operator.write", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.pd.PutOperatorProfile(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *PDAdminHandler) ComplianceStatus(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.pd.operator.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.pd.ComplianceStatus(session)
	return httpx.JSON(c, status, payload)
}

func (h *PDAdminHandler) ListPurposes(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.pd.purposes.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.pd.ListPurposes(session)
	return httpx.JSON(c, status, payload)
}

func (h *PDAdminHandler) CreatePurpose(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.pd.purposes.write", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.pd.CreatePurpose(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *PDAdminHandler) PatchPurpose(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.pd.purposes.write", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.pd.PatchPurpose(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *PDAdminHandler) ListSubjectRequests(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.pd.requests.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.pd.ListSubjectRequests(session, c.Query("status"))
	return httpx.JSON(c, status, payload)
}

func (h *PDAdminHandler) ResolveSubjectRequest(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.pd.requests.handle", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.pd.ResolveSubjectRequest(session, input)
	return httpx.JSON(c, status, payload)
}
