// Файл: action_token.go
// Назначение: maniforge_action_tokens — step-up токены для чувствительных операций.
// См. также: service/action_token.go, service/session.go
package repository

import (
	"database/sql"
	"time"
)

type ActionTokenRepository struct {
	db *sql.DB
}

func NewActionTokenRepository(db *sql.DB) *ActionTokenRepository {
	return &ActionTokenRepository{db: db}
}

type ActionTokenCreateInput struct {
	ID           string
	SessionID    string
	UserID       int64
	TenantID     string
	SubtenantID  string
	Token        string
	Purpose      string
	ExpiresAt    time.Time
}

func (r *ActionTokenRepository) RevokeActiveForSession(sessionID string) error {
	_, err := r.db.Exec(
		`UPDATE maniforge_action_tokens SET revoked_at = NOW()
		 WHERE session_id = $1 AND revoked_at IS NULL`, sessionID)
	return err
}

func (r *ActionTokenRepository) Create(input ActionTokenCreateInput) error {
	_, err := r.db.Exec(
		`INSERT INTO maniforge_action_tokens (
			id, session_id, user_id, tenant_id, subtenant_id, token_hash, purpose, expires_at, created_at
		) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, NOW())`,
		input.ID, input.SessionID, input.UserID, input.TenantID, input.SubtenantID,
		hashToken(input.Token), input.Purpose, input.ExpiresAt)
	return err
}

func (r *ActionTokenRepository) FindActiveByToken(token string, session *SessionRecord) (*ActionTokenRow, error) {
	row := r.db.QueryRow(
		`SELECT id, session_id, user_id, tenant_id, subtenant_id, purpose
		 FROM maniforge_action_tokens
		 WHERE token_hash = $1 AND revoked_at IS NULL AND expires_at > NOW()
		 LIMIT 1`, hashToken(token))

	var rec ActionTokenRow
	err := row.Scan(&rec.ID, &rec.SessionID, &rec.UserID, &rec.TenantID, &rec.SubtenantID, &rec.Purpose)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if session == nil ||
		rec.SessionID != session.ID ||
		rec.UserID != session.UserID ||
		rec.TenantID != session.TenantID ||
		rec.SubtenantID != session.SubtenantID {
		return nil, nil
	}
	return &rec, nil
}

type ActionTokenRow struct {
	ID          string
	SessionID   string
	UserID      int64
	TenantID    string
	SubtenantID string
	Purpose     string
}
