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
}

func NewAuthHandler(cfg config.Config, db *sql.DB) *AuthHandler {
	return &AuthHandler{
		auth:         service.NewAuthService(cfg, db),
		sessions:     service.NewSessionService(cfg, db),
		registration: service.NewRegistrationService(cfg, db),
		users:        repository.NewUserRepository(db, cfg),
		sessionRepo:  repository.NewSessionRepository(db),
		actionTokens: service.NewActionTokenService(cfg, repository.NewActionTokenRepository(db)),
		contexts:     service.NewContextService(cfg, db),
		mfa:          service.NewMFAService(cfg, db),
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
