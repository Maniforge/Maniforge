// Package service — бизнес-логика RBAC (auth, session, registration, profile).
//
// Файл: auth.go
// Назначение: Login по телефону, scope filter, licensing gate перед Issue.
// Зависимости: repository.UserRepository, licensingclient, SessionService.
// См. также: session.go, docs/MANIFORGE_GO_CODEMAP.md
package service

import (
	"database/sql"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/licensingclient"
	"maniforge/internal/platform/code"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/security"
)

type AuthService struct {
	cfg       config.Config
	db        *sql.DB
	users     *repository.UserRepository
	sessions  *SessionService
	licensing *licensingclient.Client
	security  *repository.SecurityEventRepository
	attempts  *repository.LoginAttemptRepository
	policies  *PolicyService
	mfa       *MFAService
}

func NewAuthService(cfg config.Config, db *sql.DB) *AuthService {
	return &AuthService{
		cfg:       cfg,
		db:        db,
		users:     repository.NewUserRepository(db, cfg),
		sessions:  NewSessionService(cfg, db),
		licensing: licensingclient.New(cfg, db),
		security:  repository.NewSecurityEventRepository(db, cfg),
		attempts:  repository.NewLoginAttemptRepository(db, cfg.RBACLoginMaxFails, cfg.RBACLoginLockMinutes),
		policies:  NewPolicyService(repository.NewPolicyRuleRepository(db)),
		mfa:       NewMFAService(cfg, db),
	}
}

type LoginRequest struct {
	Phone       string `json:"phone"`
	Password    string `json:"password"`
	TenantID    string `json:"tenant_id"`
	SubtenantID string `json:"subtenant_id"`
}

func (s *AuthService) Login(c *fiber.Ctx, tenant TenantContext, req LoginRequest) (map[string]any, int) {
	phone := strings.TrimSpace(req.Phone)
	password := req.Password

	if phone == "" || password == "" {
		return map[string]any{"ok": false, "error": "Телефон и пароль обязательны"}, fiber.StatusUnprocessableEntity
	}
	if !repository.ValidatePhone(phone) {
		return map[string]any{
			"ok":    false,
			"error": "Телефон: укажите код страны и номер (10–15 цифр в международном формате)",
		}, fiber.StatusUnprocessableEntity
	}

	matches, err := s.users.FindAllByPhone(phone)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	hintTenant := code.Normalize(req.TenantID)
	hintSubtenant := code.Normalize(req.SubtenantID)
	if hintTenant == "" {
		hintTenant = code.Normalize(tenant.TenantID)
	}
	if hintSubtenant == "" {
		hintSubtenant = code.Normalize(tenant.SubtenantID)
	}
	matches = repository.FilterUsersByScope(matches, hintTenant, hintSubtenant)

	if len(matches) == 0 {
		return s.failPhoneLogin(c, phone, hintTenant, hintSubtenant)
	}

	var user *repository.User
	if len(matches) == 1 {
		u := matches[0]
		user = &u
	} else {
		user = repository.ResolvePhoneLoginUser(matches, password)
	}
	if user == nil {
		return s.failPhoneLogin(c, phone, "", "")
	}

	scope := TenantContext{
		TenantID:    user.TenantID,
		SubtenantID: user.SubtenantID,
	}
	return s.loginInScope(c, scope, phone, password, user)
}

func (s *AuthService) loginInScope(
	c *fiber.Ctx, tenant TenantContext, phone, password string, user *repository.User,
) (map[string]any, int) {
	tenantID := code.Normalize(tenant.TenantID)
	subtenantID := code.Normalize(tenant.SubtenantID)
	if tenantID == "" || subtenantID == "" {
		return map[string]any{"ok": false, "error": "Не удалось определить область входа"}, fiber.StatusBadRequest
	}

	decision := s.licensing.AssertAccess(tenantID, defaultProjectCode, subtenantID)
	if !decision.OK {
		status := decision.Status
		if status == 0 {
			status = fiber.StatusForbidden
		}
		return map[string]any{"ok": false, "error": decision.Error}, status
	}

	ip := c.IP()
	if ip == "" {
		ip = "unknown"
	}
	if lock, err := s.attempts.ActiveLock(tenantID, subtenantID, phone, ip); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	} else if lock != nil {
		payload := repository.LoginLockError(lock)
		return payload, fiber.StatusTooManyRequests
	}

	if user == nil || !security.VerifyPassword(password, user.PasswordHash) {
		return s.failPhoneLogin(c, phone, tenantID, subtenantID)
	}

	if user.Status != "active" {
		actor := user.ID
		_ = s.security.Write("auth.login.blocked", &actor, tenantID, subtenantID, "warning", map[string]any{
			"status": user.Status,
		})
		return map[string]any{"ok": false, "error": "Аккаунт не активен"}, fiber.StatusForbidden
	}

	_ = s.attempts.Clear(tenantID, subtenantID, phone, ip)
	payload, status := s.authenticateUser(c, tenant, user)
	if status == fiber.StatusOK {
		tid, sid := sessionTenantContext(tenant)
		s.appendMFAEnrollmentHint(payload, tid, sid, user.ID)
	}
	return payload, status
}

func sessionTenantContext(tenant TenantContext) (string, string) {
	return code.Normalize(tenant.TenantID), code.Normalize(tenant.SubtenantID)
}

func (s *AuthService) appendMFAEnrollmentHint(payload map[string]any, tenantID, subtenantID string, userID int64) {
	if s.policies == nil || !s.policies.RequiresMFAEnrollment(tenantID, subtenantID) {
		return
	}
	session := &repository.SessionRecord{
		UserID: userID, TenantID: tenantID, SubtenantID: subtenantID,
	}
	if s.mfa != nil && !s.mfa.HasVerifiedTOTP(session) {
		payload["mfa_enrollment_required"] = true
	}
}

func (s *AuthService) failPhoneLogin(c *fiber.Ctx, phone, tenantID, subtenantID string) (map[string]any, int) {
	ip := c.IP()
	if ip == "" {
		ip = "unknown"
	}
	if repository.ScopeKnown(tenantID, subtenantID) {
		attempt, err := s.attempts.RegisterFailure(tenantID, subtenantID, phone, ip)
		if err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
		_ = s.security.Write("auth.login.failed", nil, tenantID, subtenantID, "warning", map[string]any{
			"phone": phone, "failed_count": attempt.FailedCount, "locked_until": repository.FormatLockedUntil(attempt.LockedUntil),
		})
	} else {
		_ = s.security.Write("auth.login.failed", nil, "", "", "warning", map[string]any{
			"phone": phone, "scope_unknown": true,
		})
	}
	return map[string]any{"ok": false, "error": "Неверные учетные данные"}, fiber.StatusUnauthorized
}

func (s *AuthService) authenticateUser(c *fiber.Ctx, tenant TenantContext, user *repository.User) (map[string]any, int) {
	session, err := s.sessions.Issue(c, *user, tenant)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	payload := map[string]any{
		"ok":          true,
		"status":      fiber.StatusOK,
		"user":        repository.PublicUser(*user),
		"credentials": map[string]any{"session": session},
		"session":     session,
	}
	if csrf, ok := session["csrf_token"].(string); ok && csrf != "" {
		payload["csrf_token"] = csrf
	}
	return payload, fiber.StatusOK
}
