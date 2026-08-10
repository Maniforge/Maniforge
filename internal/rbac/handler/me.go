// Файл: me.go
// Назначение: /me, PATCH profile (мягко), PATCH identity и change-password (с revoke).
// Зависимости: user, user_profile repos; ProfileService, UserSecurityService.
// См. также: service/profile.go, service/user_security.go
package handler

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
)

type MeHandler struct {
	cfg      config.Config
	users    *repository.UserRepository
	profiles *repository.UserProfileRepository
	security *service.UserSecurityService
	profile  *service.ProfileService
	rbac     *service.RbacService
	contexts *service.ContextService
}

func NewMeHandler(cfg config.Config, db *sql.DB) *MeHandler {
	roles := repository.NewRoleRepository(db)
	return &MeHandler{
		cfg:      cfg,
		users:    repository.NewUserRepository(db, cfg),
		profiles: repository.NewUserProfileRepository(db),
		security: service.NewUserSecurityService(cfg, db),
		profile:  service.NewProfileService(db),
		rbac:     service.NewRbacService(roles),
		contexts: service.NewContextService(cfg, db),
	}
}

func (h *MeHandler) Me(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}

	payload, status := h.contexts.ContextsForSession(session)
	if status != fiber.StatusOK {
		return httpx.JSON(c, status, payload)
	}

	sessionPayload := fiber.Map{
		"id": session.ID, "user_id": session.UserID,
		"tenant_id": session.TenantID, "subtenant_id": session.SubtenantID,
		"aal": session.AAL,
	}
	if session.ProjectID.Valid {
		sessionPayload["project_id"] = session.ProjectID.Int64
	} else {
		sessionPayload["project_id"] = nil
	}
	if current, ok := payload["current"].(map[string]any); ok {
		for _, field := range []string{"kind", "delegated", "grant_level", "principal_tenant_id"} {
			if v, exists := current[field]; exists {
				sessionPayload[field] = v
			}
		}
	}

	return httpx.OK(c, fiber.Map{"ok": true, "session": sessionPayload})
}

func (h *MeHandler) Profile(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	user, err := h.users.FindByIDInScope(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil || user == nil {
		return httpx.Fail(c, fiber.StatusNotFound, "Пользователь не найден")
	}
	roles, _ := h.rbac.RolesForUser(session.UserID, session.TenantID, session.SubtenantID)
	return httpx.OK(c, fiber.Map{
		"ok": true, "user": repository.PublicUser(*user), "roles": roles,
	})
}

func (h *MeHandler) Permissions(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	items, _ := h.rbac.PermissionsForUser(session.UserID, session.TenantID, session.SubtenantID)
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *MeHandler) Contexts(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	payload, status := h.contexts.ContextsForSession(session)
	return httpx.JSON(c, status, payload)
}

func (h *MeHandler) Access(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	access, _ := h.rbac.EffectiveAccess(session.UserID, session.TenantID, session.SubtenantID)
	return httpx.OK(c, fiber.Map{"ok": true, "access": access})
}

func (h *MeHandler) ConsoleAccess(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	roles, _ := h.rbac.RolesForUser(session.UserID, session.TenantID, session.SubtenantID)
	perms, _ := h.rbac.PermissionsForUser(session.UserID, session.TenantID, session.SubtenantID)
	hasAdmin := false
	for _, p := range perms {
		if len(p) >= 6 && p[:6] == "admin." {
			hasAdmin = true
			break
		}
	}
	isSuper, _ := h.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{"super_admin"})
	hasTenant, _ := h.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{
		"super_admin", "tenant_admin", "subtenant_admin", "security_auditor",
	})
	resp := fiber.Map{
		"ok": true, "roles": roles,
		"modules": fiber.Map{"tenant": hasAdmin || hasTenant, "platform": isSuper},
	}
	if isSuper {
		resp["platform_licensing_token_configured"] = h.cfg.TenantLicensingAdminToken != ""
	}
	return httpx.OK(c, resp)
}

// PatchProfile — мягкие поля user_profile без отзыва сессий.
func (h *MeHandler) PatchProfile(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	var req service.ProfileUpdateRequest
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.profile.Update(session.UserID, req)
	return httpx.JSON(c, status, payload)
}

// PatchIdentity — критичные поля users (email, phone) с отзывом всех сессий.
func (h *MeHandler) PatchIdentity(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	var req service.IdentityUpdateRequest
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.security.ApplyIdentityUpdate(
		session.UserID, session.TenantID, session.SubtenantID, req,
	)
	return httpx.JSON(c, status, payload)
}

// ChangePassword — смена пароля с отзывом всех сессий.
func (h *MeHandler) ChangePassword(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	var req service.ChangePasswordRequest
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.security.ChangePassword(
		session.UserID, session.TenantID, session.SubtenantID, req,
	)
	return httpx.JSON(c, status, payload)
}
