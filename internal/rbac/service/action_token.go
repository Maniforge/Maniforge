// Файл: action_token.go
// Назначение: выдача и проверка X-Action-Token после reauth.
// См. также: repository/action_token.go, service/guard.go
package service

import (
	"time"

	"maniforge/internal/config"
	"maniforge/internal/rbac/repository"
)

const actionPurposeAdminSensitive = "admin_sensitive"

type ActionTokenService struct {
	cfg    config.Config
	tokens *repository.ActionTokenRepository
}

func NewActionTokenService(cfg config.Config, tokens *repository.ActionTokenRepository) *ActionTokenService {
	return &ActionTokenService{cfg: cfg, tokens: tokens}
}

func (s *ActionTokenService) IssueForSession(session *repository.SessionRecord) (map[string]any, error) {
	_ = s.tokens.RevokeActiveForSession(session.ID)
	raw, err := repository.RandomHex(24)
	if err != nil {
		return nil, err
	}
	id, err := repository.RandomHex(16)
	if err != nil {
		return nil, err
	}
	ttl := s.cfg.RBACActionTokenTTLSec
	if ttl < 60 {
		ttl = 900
	}
	expires := time.Now().UTC().Add(time.Duration(ttl) * time.Second)
	err = s.tokens.Create(repository.ActionTokenCreateInput{
		ID: id, SessionID: session.ID, UserID: session.UserID,
		TenantID: session.TenantID, SubtenantID: session.SubtenantID,
		Token: raw, Purpose: actionPurposeAdminSensitive, ExpiresAt: expires,
	})
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"action_token": raw,
		"expires_in":   ttl,
		"purpose":      actionPurposeAdminSensitive,
	}, nil
}

func (s *ActionTokenService) Authenticate(token string, session *repository.SessionRecord) bool {
	row, err := s.tokens.FindActiveByToken(token, session)
	return err == nil && row != nil
}
