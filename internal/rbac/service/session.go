// Файл: session.go
// Назначение: выдача сессии, authenticate, refresh с ротацией, revoke.
// Зависимости: maniforge_sessions, refresh_tokens, licensingclient, security_version.
// См. также: repository/session.go, middleware/tenant.go
package service

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/licensingclient"
	"maniforge/internal/rbac/repository"
)

const defaultProjectCode = "main"

type TenantContext struct {
	TenantID    string
	SubtenantID string
	Mode        string
}

type SessionService struct {
	cfg       config.Config
	sessions  *repository.SessionRepository
	users     *repository.UserRepository
	licensing *licensingclient.Client
	db        *sql.DB
}

func NewSessionService(cfg config.Config, db *sql.DB) *SessionService {
	return &SessionService{
		cfg:       cfg,
		sessions:  repository.NewSessionRepository(db),
		users:     repository.NewUserRepository(db, cfg),
		licensing: licensingclient.New(cfg, db),
		db:        db,
	}
}

func (s *SessionService) Issue(c *fiber.Ctx, user repository.User, tenant TenantContext) (map[string]any, error) {
	sessionID, err := repository.RandomHex(16)
	if err != nil {
		return nil, err
	}
	accessToken, err := repository.RandomHex(32)
	if err != nil {
		return nil, err
	}
	refreshToken, err := repository.RandomHex(32)
	if err != nil {
		return nil, err
	}
	refreshID, err := repository.RandomHex(16)
	if err != nil {
		return nil, err
	}

	projectID, err := repository.DefaultProjectID(s.db, tenant.TenantID, tenant.SubtenantID)
	if err != nil {
		return nil, err
	}
	projectCode := repository.ProjectCodeForSession(s.db, tenant.TenantID, projectID)

	aal := "AAL1"
	if user.MFARequired {
		aal = "AAL2"
	}

	csrfToken, err := repository.RandomHex(32)
	if err != nil {
		return nil, err
	}

	err = s.sessions.CreateSession(repository.SessionCreateInput{
		ID:              sessionID,
		UserID:          user.ID,
		TenantID:        tenant.TenantID,
		SubtenantID:     tenant.SubtenantID,
		ProjectID:       projectID,
		AccessToken:     accessToken,
		CsrfToken:       csrfToken,
		IP:              c.IP(),
		UserAgent:       string(c.Request().Header.UserAgent()),
		AAL:             aal,
		ExpiresAt:       repository.SessionExpiresAt(s.cfg.RBACSessionTTLMinutes),
		SecurityVersion: user.SecurityVersion,
	})
	if err != nil {
		return nil, err
	}

	err = s.sessions.CreateRefreshToken(repository.RefreshCreateInput{
		ID:           refreshID,
		SessionID:    sessionID,
		UserID:       user.ID,
		TenantID:     tenant.TenantID,
		SubtenantID:  tenant.SubtenantID,
		ProjectID:    projectID,
		RefreshToken: refreshToken,
		ExpiresAt:    repository.RefreshExpiresAt(s.cfg.RBACRefreshTTLDays),
	})
	if err != nil {
		return nil, err
	}

	var projectIDVal any
	if projectID.Valid {
		projectIDVal = projectID.Int64
	}

	return map[string]any{
		"credential_type": "session_access",
		"session_id":      sessionID,
		"access_token":    accessToken,
		"refresh_token":   refreshToken,
		"csrf_token":      csrfToken,
		"expires_in":      s.cfg.RBACSessionTTLMinutes * 60,
		"user_id":         user.ID,
		"tenant_id":       tenant.TenantID,
		"subtenant_id":    tenant.SubtenantID,
		"project_id":      projectIDVal,
		"project_code":    projectCode,
		"scope": map[string]any{
			"tenant_id":    tenant.TenantID,
			"subtenant_id": tenant.SubtenantID,
			"project_id":   projectIDVal,
			"project_code": projectCode,
		},
	}, nil
}

func (s *SessionService) Authenticate(token string) (*repository.SessionRecord, error) {
	if token == "" {
		return nil, nil
	}
	session, err := s.sessions.FindActiveByToken(token)
	if err != nil || session == nil {
		return nil, err
	}

	user, err := s.users.FindByIDInScope(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return nil, err
	}
	if user == nil {
		homeUser, homeErr := s.users.FindByID(session.UserID)
		if homeErr != nil {
			return nil, homeErr
		}
		if homeUser == nil {
			s.revokeSessionCredentials(session.ID, "user_not_active")
			return nil, nil
		}
		allowed := NewContextService(s.cfg, s.db).IsContextAllowed(
			homeUser.Phone, session.TenantID, session.SubtenantID,
		)
		if ok, _ := allowed["ok"].(bool); !ok {
			s.revokeSessionCredentials(session.ID, "delegated_context_denied")
			return nil, nil
		}
		user = homeUser
	}
	if user.Status != "active" ||
		user.SecurityVersion != session.SecurityVersionSnapshot {
		reason := "user_not_active"
		if user.SecurityVersion != session.SecurityVersionSnapshot {
			reason = "security_version_changed"
		}
		s.revokeSessionCredentials(session.ID, reason)
		return nil, nil
	}

	projectCode := repository.ProjectCodeForSession(s.db, session.TenantID, session.ProjectID)
	decision := s.licensing.AssertAccess(session.TenantID, projectCode, session.SubtenantID)
	if !decision.OK {
		return nil, nil
	}

	_ = s.sessions.Touch(session.ID)
	return session, nil
}

func (s *SessionService) RevokeByToken(token, reason string) bool {
	if token == "" {
		return false
	}
	session, err := s.sessions.FindActiveByToken(token)
	if err != nil || session == nil {
		return false
	}
	s.revokeSessionCredentials(session.ID, reason)
	return true
}

func (s *SessionService) Refresh(c *fiber.Ctx, refreshToken string) (map[string]any, int) {
	if refreshToken == "" {
		return map[string]any{"ok": false, "error": "refresh_token обязателен"}, fiber.StatusUnprocessableEntity
	}

	row, err := s.sessions.FindActiveRefreshByToken(refreshToken)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if row == nil {
		return map[string]any{"ok": false, "error": "Недействительный refresh token"}, fiber.StatusUnauthorized
	}

	tenant := TenantContext{
		TenantID:    row.TenantID,
		SubtenantID: row.SubtenantID,
	}

	user, err := s.users.FindByIDInScope(row.UserID, tenant.TenantID, tenant.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	parentSession, err := s.sessions.FindByID(row.SessionID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	securityVersionChanged := user != nil && parentSession != nil &&
		user.SecurityVersion != parentSession.SecurityVersionSnapshot
	if user == nil || parentSession == nil || user.Status != "active" || securityVersionChanged {
		_ = s.sessions.RevokeRefreshByID(row.ID, "user_not_allowed")
		return map[string]any{"ok": false, "error": "Пользователь недоступен"}, fiber.StatusForbidden
	}

	projectCode := repository.ProjectCodeForSession(s.db, tenant.TenantID, row.ProjectID)
	decision := s.licensing.AssertAccess(tenant.TenantID, projectCode, tenant.SubtenantID)
	if !decision.OK {
		if !decision.Temporary {
			_ = s.sessions.RevokeRefreshByID(row.ID, "tenant_license_denied:"+decision.DenyReason)
		}
		status := decision.Status
		if status == 0 {
			status = fiber.StatusForbidden
		}
		return map[string]any{
			"ok":    false,
			"error": decision.Error,
		}, status
	}

	oldCsrfHash, _ := s.sessions.CsrfHashBySessionID(row.SessionID)

	s.revokeSessionCredentials(row.SessionID, "rotated")

	newSession, err := s.Issue(c, *user, tenant)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if oldCsrfHash != "" {
		if sid, ok := newSession["session_id"].(string); ok {
			_ = s.sessions.SetCsrfHash(sid, oldCsrfHash)
		}
	}
	_ = s.sessions.RevokeRefreshByID(row.ID, "rotated")

	return map[string]any{
		"ok":      true,
		"status":  fiber.StatusOK,
		"session": newSession,
	}, fiber.StatusOK
}

func (s *SessionService) revokeSessionCredentials(sessionID, reason string) {
	_ = s.sessions.Revoke(sessionID, reason)
	_, _ = s.sessions.RevokeRefreshBySessionID(sessionID, reason)
}
