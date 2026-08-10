// Файл: admin.go
// Назначение: admin HTTP handlers (users, roles, policies, invites).
package handler

import (
	"database/sql"
	"strconv"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/licensingclient"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
	"maniforge/internal/versioning"
)

type AdminHandler struct {
	admin *service.AdminService
	guard *service.RequestGuard
}

func NewAdminHandler(cfg config.Config, db *sql.DB) *AdminHandler {
	roles := repository.NewRoleRepository(db)
	rbac := service.NewRbacService(roles)
	sessions := repository.NewSessionRepository(db)
	policyRepo := repository.NewPolicyRuleRepository(db)
	policies := service.NewPolicyService(policyRepo)
	actions := service.NewActionTokenService(cfg, repository.NewActionTokenRepository(db))
	mfa := service.NewMFAService(cfg, db)
	guard := service.NewRequestGuard(cfg, sessions, actions, rbac, policies, mfa)
	users := repository.NewUserRepository(db, cfg)
	admin := service.NewAdminService(
		cfg,
		repository.NewInviteRepository(db),
		licensingclient.New(cfg, db),
		versioning.NewRecorder(cfg, db),
		users,
		roles,
		sessions,
		repository.NewAuditRepository(db),
		repository.NewSecurityEventRepository(db, cfg),
		policies,
		policyRepo,
		rbac,
		service.NewUserAdminService(users),
		service.NewRoleAdminService(roles, repository.NewSecurityEventRepository(db, cfg)),
	)
	return &AdminHandler{admin: admin, guard: guard}
}

func (h *AdminHandler) CreateRegistrationInvite(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.users.status.bulk", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.CreateRegistrationInvite(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ListUsers(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.users.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	limit, _ := strconv.Atoi(c.Query("limit", "50"))
	payload, status := h.admin.ListUsers(session, limit)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) CreateUser(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.users.status.bulk", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.CreateUser(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) BatchUserStatus(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.users.status.bulk", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.BatchUserStatus(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) AssignUserRole(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.user_roles.assign", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.AssignUserRole(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ListUserRoles(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.user_roles.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	userID, _ := strconv.ParseInt(c.Query("user_id"), 10, 64)
	payload, status := h.admin.ListUserRoles(session, userID)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) EffectiveAccess(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.user_access.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	userID, _ := strconv.ParseInt(c.Query("user_id"), 10, 64)
	payload, status := h.admin.EffectiveAccess(session, userID)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) GetPolicies(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.policies.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.admin.GetPolicies(session)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) UpdatePolicies(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.policies.update", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.UpdatePolicies(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) OpsSummary(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.users.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.admin.OpsSummary(session)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ListSessions(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.sessions.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.admin.ListSessions(session)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) RevokeSession(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.sessions.revoke", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.RevokeSession(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) BatchRevokeSessions(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.sessions.bulk", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.BatchRevokeSessions(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ListAudit(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.audit.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.admin.ListAudit(session)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ExportAudit(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.audit.export", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	limit, _ := strconv.Atoi(c.Query("limit", "5000"))
	payload, status := h.admin.ExportAudit(session, limit)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ListSecurityEvents(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.security_events.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.admin.ListSecurityEvents(session)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ListRoles(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.roles.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.admin.ListRoles(session)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) CreateRole(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.user_roles.bulk", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.CreateRole(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) UpdateRole(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.user_roles.bulk", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.UpdateRole(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) DeleteRole(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.user_roles.bulk", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.DeleteRole(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ListPermissions(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.permissions.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.admin.ListPermissionsCatalog()
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ListRolePermissions(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdminRead(session, "admin.permissions.read", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.admin.ListRolePermissions(session, c.Query("role_code"))
	return httpx.JSON(c, status, payload)
}

func (h *AdminHandler) ReplaceRolePermissions(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardAdmin(session, "admin.user_roles.bulk", c); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.admin.ReplaceRolePermissions(session, input)
	return httpx.JSON(c, status, payload)
}
