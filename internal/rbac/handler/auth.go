// Package handler — HTTP-обработчики RBAC (тонкий слой над service).
//
// Файл: auth.go
// Назначение: register, login, refresh, logout.
// Зависимости: service.AuthService, SessionService, RegistrationService.
// См. также: me.go, internal/rbac/app.go
package handler

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/auth"
	"maniforge/internal/platform/httpx"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/security"
	"maniforge/internal/rbac/service"
	"maniforge/internal/versioning"
)

type AuthHandler struct {
	auth         *service.AuthService
	sessions     *service.SessionService
	registration *service.RegistrationService
	users        *repository.UserRepository
	sessionRepo  *repository.SessionRepository
	actionTokens *service.ActionTokenService
	contexts     *service.ContextService
	mfa          *service.MFAService
	org          *service.OrganizationService
	projects     *service.ProjectService
	audit        *repository.AuditRepository
}

func NewAuthHandler(cfg config.Config, db *sql.DB) *AuthHandler {
	roles := repository.NewRoleRepository(db)
	rbac := service.NewRbacService(roles)
	return &AuthHandler{
		auth:         service.NewAuthService(cfg, db),
		sessions:     service.NewSessionService(cfg, db),
		registration: service.NewRegistrationService(cfg, db),
		users:        repository.NewUserRepository(db, cfg),
		sessionRepo:  repository.NewSessionRepository(db),
		actionTokens: service.NewActionTokenService(cfg, repository.NewActionTokenRepository(db)),
		contexts:     service.NewContextService(cfg, db),
		mfa:          service.NewMFAService(cfg, db),
		org:          service.NewOrganizationService(cfg, db),
		projects: service.NewProjectService(
			repository.NewProjectRepository(db),
			repository.NewScopeVariableRepository(db),
			repository.NewUserRepository(db, cfg),
			rbac,
			versioning.NewRecorder(cfg, db),
		),
		audit: repository.NewAuditRepository(db),
	}
}

func (h *AuthHandler) Register(c *fiber.Ctx) error {
	var req service.RegisterInput
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.registration.Register(c, req)
	return httpx.JSON(c, status, payload)
}

func (h *AuthHandler) Login(c *fiber.Ctx) error {
	var req service.LoginRequest
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.auth.Login(c, rbacmw.TenantFromCtx(c), req)
	return httpx.JSON(c, status, payload)
}

func (h *AuthHandler) Refresh(c *fiber.Ctx) error {
	var req struct {
		RefreshToken string `json:"refresh_token"`
	}
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.sessions.Refresh(c, req.RefreshToken)
	return httpx.JSON(c, status, payload)
}

func (h *AuthHandler) Logout(c *fiber.Ctx) error {
	token := auth.BearerToken(c)
	if token == "" {
		return httpx.JSON(c, fiber.StatusUnauthorized, fiber.Map{
			"ok":    false,
			"error": "Bearer token обязателен",
		})
	}
	revoked := h.sessions.RevokeByToken(token, "manual_logout")
	if !revoked {
		return httpx.JSON(c, fiber.StatusNotFound, fiber.Map{"ok": false})
	}
	return httpx.JSON(c, fiber.StatusOK, fiber.Map{"ok": true})
}

func (h *AuthHandler) LogoutAll(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	count, err := h.sessions.RevokeAllForUser(session.UserID, "logout_all")
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	actor := session.UserID
	_ = h.audit.Write("auth.logout_all", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"revoked_sessions": count,
	})
	return httpx.OK(c, fiber.Map{"ok": true, "revoked_sessions": count})
}

func (h *AuthHandler) AcceptInvite(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	var req struct {
		InviteToken string `json:"invite_token"`
	}
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.org.AcceptInvite(session, req.InviteToken)
	return httpx.JSON(c, status, payload)
}

func (h *AuthHandler) SwitchProject(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil && len(c.Body()) > 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	if input == nil {
		input = map[string]any{}
	}
	var projectID *int64
	if raw, exists := input["project_id"]; exists && raw != nil && raw != "" && raw != "null" {
		id := parseInt64Input(raw)
		if id <= 0 {
			return httpx.Fail(c, fiber.StatusUnprocessableEntity, "Некорректный project_id")
		}
		projectID = &id
	}
	payload, status := h.projects.SwitchProject(session, projectID)
	if status != fiber.StatusOK {
		return httpx.JSON(c, status, payload)
	}
	sess, _ := payload["session"].(map[string]any)
	if unchanged, _ := sess["unchanged"].(bool); unchanged {
		return httpx.JSON(c, status, payload)
	}
	var bind sql.NullInt64
	if projectID != nil {
		bind = sql.NullInt64{Int64: *projectID, Valid: true}
	}
	okBind, err := h.sessionRepo.RebindProject(session.ID, bind)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	if !okBind {
		return httpx.Fail(c, fiber.StatusInternalServerError, "Не удалось переключить проект сессии")
	}
	return httpx.JSON(c, status, payload)
}

func (h *AuthHandler) SwitchContext(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	var req struct {
		TenantID    string `json:"tenant_id"`
		SubtenantID string `json:"subtenant_id"`
	}
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.contexts.SwitchContext(session, req.TenantID, req.SubtenantID)
	return httpx.JSON(c, status, payload)
}

func (h *AuthHandler) Reauth(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	var req struct {
		Password     string `json:"password"`
		TotpCode     string `json:"totp_code"`
		RecoveryCode string `json:"recovery_code"`
	}
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	stepUpOK := false
	if req.RecoveryCode != "" && h.mfa.ValidateRecovery(session, req.RecoveryCode) {
		stepUpOK = true
	}
	if !stepUpOK && req.TotpCode != "" && h.mfa.ValidateTOTP(session, req.TotpCode) {
		stepUpOK = true
	}
	if !stepUpOK && req.Password != "" {
		user, err := h.users.FindByIDInScope(session.UserID, session.TenantID, session.SubtenantID)
		if err != nil {
			return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
		}
		if user != nil && security.VerifyPassword(req.Password, user.PasswordHash) {
			stepUpOK = true
		}
	}
	if !stepUpOK {
		return httpx.JSON(c, fiber.StatusForbidden, fiber.Map{
			"ok": false, "error": "Неверный пароль, TOTP или recovery code",
		})
	}
	if err := h.sessionRepo.MarkMfaVerified(session.ID); err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	action, err := h.actionTokens.IssueForSession(session)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{
		"ok": true, "step_up": true,
		"credentials": fiber.Map{"action": mergeAction(action)},
	})
}

func mergeAction(action map[string]any) map[string]any {
	out := map[string]any{"credential_type": "action"}
	for k, v := range action {
		out[k] = v
	}
	return out
}
