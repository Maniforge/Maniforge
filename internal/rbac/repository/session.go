// Файл: session.go
// Назначение: maniforge_sessions и maniforge_refresh_tokens — создание, поиск, revoke.
// Зависимости: security_version_snapshot при CreateSession.
// См. также: service/session.go, user_security.go (RevokeAllForUser)
package repository

import (
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"fmt"
	"time"
)

type SessionRepository struct {
	db *sql.DB
}

func NewSessionRepository(db *sql.DB) *SessionRepository {
	return &SessionRepository{db: db}
}

type SessionRecord struct {
	ID                      string
	UserID                  int64
	TenantID                string
	SubtenantID             string
	ProjectID               sql.NullInt64
	AAL                     string
	SecurityVersionSnapshot int
}

func (r *SessionRepository) CreateSession(input SessionCreateInput) error {
	_, err := r.db.Exec(
		`INSERT INTO maniforge_sessions (
			id, user_id, tenant_id, subtenant_id, project_id, session_secret_hash,
			ip_hash, user_agent_hash, aal, last_activity_at, expires_at,
			security_version_snapshot, csrf_token_hash, created_at
		) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, NOW(), $10, $11, $12, NOW())`,
		input.ID, input.UserID, input.TenantID, input.SubtenantID, input.ProjectID,
		hashToken(input.AccessToken), hashString(input.IP), hashString(input.UserAgent),
		input.AAL, input.ExpiresAt, input.SecurityVersion, hashToken(input.CsrfToken),
	)
	return err
}

// MarkMfaVerified — step-up после reauth (mfa_verified_at).
func (r *SessionRepository) MarkMfaVerified(sessionID string) error {
	_, err := r.db.Exec(
		`UPDATE maniforge_sessions SET mfa_verified_at = NOW() WHERE id = $1 AND revoked_at IS NULL`, sessionID)
	return err
}

// IsStepUpFresh проверяет свежесть mfa_verified_at.
func (r *SessionRepository) IsStepUpFresh(sessionID string, maxAgeSec int) bool {
	var verifiedAt sql.NullTime
	err := r.db.QueryRow(
		`SELECT mfa_verified_at FROM maniforge_sessions WHERE id = $1 LIMIT 1`, sessionID).
		Scan(&verifiedAt)
	if err != nil || !verifiedAt.Valid {
		return false
	}
	return time.Since(verifiedAt.Time) <= time.Duration(maxAgeSec)*time.Second
}

// CsrfHashBySessionID возвращает сохранённый хеш CSRF (для ротации refresh).
func (r *SessionRepository) CsrfHashBySessionID(sessionID string) (string, error) {
	var stored sql.NullString
	err := r.db.QueryRow(
		`SELECT csrf_token_hash FROM maniforge_sessions WHERE id = $1 LIMIT 1`, sessionID).
		Scan(&stored)
	if err != nil {
		return "", err
	}
	if !stored.Valid {
		return "", nil
	}
	return stored.String, nil
}

// SetCsrfHash обновляет csrf_token_hash сессии (при refresh сохраняем прежний токен клиента).
func (r *SessionRepository) SetCsrfHash(sessionID, hash string) error {
	_, err := r.db.Exec(`UPDATE maniforge_sessions SET csrf_token_hash = $2 WHERE id = $1`, sessionID, hash)
	return err
}

// ValidateCsrfToken сравнивает CSRF с хешем сессии.
func (r *SessionRepository) ValidateCsrfToken(sessionID, csrfToken string) bool {
	if csrfToken == "" {
		return false
	}
	var stored sql.NullString
	err := r.db.QueryRow(
		`SELECT csrf_token_hash FROM maniforge_sessions WHERE id = $1 AND revoked_at IS NULL LIMIT 1`,
		sessionID).Scan(&stored)
	if err != nil || !stored.Valid {
		return false
	}
	return stored.String == hashToken(csrfToken)
}

func (r *SessionRepository) CreateRefreshToken(input RefreshCreateInput) error {
	_, err := r.db.Exec(
		`INSERT INTO maniforge_refresh_tokens (
			id, session_id, user_id, tenant_id, subtenant_id, project_id,
			token_hash, expires_at, created_at
		) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, NOW())`,
		input.ID, input.SessionID, input.UserID, input.TenantID, input.SubtenantID,
		input.ProjectID, hashToken(input.RefreshToken), input.ExpiresAt,
	)
	return err
}

func (r *SessionRepository) FindActiveByToken(token string) (*SessionRecord, error) {
	row := r.db.QueryRow(
		`SELECT id, user_id, tenant_id, subtenant_id, project_id, aal, security_version_snapshot
		 FROM maniforge_sessions
		 WHERE session_secret_hash = $1 AND revoked_at IS NULL AND expires_at > NOW()
		 LIMIT 1`, hashToken(token))

	var s SessionRecord
	err := row.Scan(&s.ID, &s.UserID, &s.TenantID, &s.SubtenantID, &s.ProjectID, &s.AAL, &s.SecurityVersionSnapshot)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &s, err
}

func (r *SessionRepository) Touch(id string) error {
	_, err := r.db.Exec(`UPDATE maniforge_sessions SET last_activity_at = NOW() WHERE id = $1`, id)
	return err
}

// RebindScope переключает tenant/subtenant сессии (switch-context); project_id сбрасывается.
func (r *SessionRepository) RebindScope(id, tenantID, subtenantID string) (bool, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_sessions
		 SET tenant_id = $2, subtenant_id = $3, project_id = NULL, last_activity_at = NOW()
		 WHERE id = $1 AND revoked_at IS NULL AND expires_at > NOW()`,
		id, tenantID, subtenantID)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

// RebindProject привязывает project_id к активной сессии.
func (r *SessionRepository) RebindProject(id string, projectID sql.NullInt64) (bool, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_sessions
		 SET project_id = $2, last_activity_at = NOW()
		 WHERE id = $1 AND revoked_at IS NULL AND expires_at > NOW()`,
		id, projectID)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func (r *SessionRepository) FindByID(id string) (*SessionRecord, error) {
	row := r.db.QueryRow(
		`SELECT id, user_id, tenant_id, subtenant_id, project_id, aal, security_version_snapshot
		 FROM maniforge_sessions WHERE id = $1 LIMIT 1`, id)

	var s SessionRecord
	err := row.Scan(&s.ID, &s.UserID, &s.TenantID, &s.SubtenantID, &s.ProjectID, &s.AAL, &s.SecurityVersionSnapshot)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &s, err
}

func (r *SessionRepository) Revoke(id, reason string) error {
	_, err := r.db.Exec(
		`UPDATE maniforge_sessions
		 SET revoked_at = NOW(), revoke_reason = $2
		 WHERE id = $1 AND revoked_at IS NULL`, id, reason)
	return err
}

type RefreshTokenRecord struct {
	ID          string
	SessionID   string
	UserID      int64
	TenantID    string
	SubtenantID string
	ProjectID   sql.NullInt64
}

func (r *SessionRepository) FindActiveRefreshByToken(token string) (*RefreshTokenRecord, error) {
	row := r.db.QueryRow(
		`SELECT id, session_id, user_id, tenant_id, subtenant_id, project_id
		 FROM maniforge_refresh_tokens
		 WHERE token_hash = $1 AND revoked_at IS NULL AND expires_at > NOW()
		 LIMIT 1`, hashToken(token))

	var rec RefreshTokenRecord
	err := row.Scan(&rec.ID, &rec.SessionID, &rec.UserID, &rec.TenantID, &rec.SubtenantID, &rec.ProjectID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &rec, err
}

func (r *SessionRepository) RevokeRefreshByID(id, reason string) error {
	_, err := r.db.Exec(
		`UPDATE maniforge_refresh_tokens
		 SET revoked_at = NOW(), revoke_reason = $2
		 WHERE id = $1 AND revoked_at IS NULL`, id, reason)
	return err
}

func (r *SessionRepository) RevokeRefreshBySessionID(sessionID, reason string) (int, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_refresh_tokens
		 SET revoked_at = NOW(), revoke_reason = $2
		 WHERE session_id = $1 AND revoked_at IS NULL`, sessionID, reason)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

func (r *SessionRepository) RevokeRefreshBySessionIDsInScope(sessionIDs []string, tenantID, subtenantID, reason string) (int, error) {
	if len(sessionIDs) == 0 {
		return 0, nil
	}
	total := 0
	for _, sessionID := range sessionIDs {
		res, err := r.db.Exec(
			`UPDATE maniforge_refresh_tokens
			 SET revoked_at = NOW(), revoke_reason = $4
			 WHERE session_id = $1 AND tenant_id = $2 AND subtenant_id = $3 AND revoked_at IS NULL`,
			sessionID, tenantID, subtenantID, reason)
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += int(n)
	}
	return total, nil
}

func (r *SessionRepository) ListByScope(tenantID, subtenantID string, limit int) ([]map[string]any, error) {
	if limit < 1 {
		limit = 100
	}
	rows, err := r.db.Query(
		`SELECT id, user_id, tenant_id, subtenant_id, project_id, aal, mfa_verified_at,
		        last_activity_at, expires_at, revoked_at, revoke_reason
		 FROM maniforge_sessions
		 WHERE tenant_id = $1 AND subtenant_id = $2
		 ORDER BY created_at DESC LIMIT $3`,
		tenantID, subtenantID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []map[string]any
	for rows.Next() {
		var (
			id, tenant, subtenant, aal string
			userID                     int64
			projectID                  sql.NullInt64
			mfaVerified                sql.NullTime
			lastActivity, expires      time.Time
			revokedAt                  sql.NullTime
			revokeReason               sql.NullString
		)
		if err := rows.Scan(&id, &userID, &tenant, &subtenant, &projectID, &aal, &mfaVerified,
			&lastActivity, &expires, &revokedAt, &revokeReason); err != nil {
			return nil, err
		}
		item := map[string]any{
			"id": id, "user_id": userID, "tenant_id": tenant, "subtenant_id": subtenant, "aal": aal,
			"last_activity_at": lastActivity.UTC().Format("2006-01-02 15:04:05"),
			"expires_at":       expires.UTC().Format("2006-01-02 15:04:05"),
		}
		if projectID.Valid {
			item["project_id"] = projectID.Int64
		}
		if mfaVerified.Valid {
			item["mfa_verified_at"] = mfaVerified.Time.UTC().Format("2006-01-02 15:04:05")
		}
		if revokedAt.Valid {
			item["revoked_at"] = revokedAt.Time.UTC().Format("2006-01-02 15:04:05")
		}
		if revokeReason.Valid {
			item["revoke_reason"] = revokeReason.String
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func (r *SessionRepository) ExistsInScope(sessionID, tenantID, subtenantID string) (bool, error) {
	var id string
	err := r.db.QueryRow(
		`SELECT id FROM maniforge_sessions
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3 LIMIT 1`,
		sessionID, tenantID, subtenantID).Scan(&id)
	if err == sql.ErrNoRows {
		return false, nil
	}
	return err == nil, err
}

func (r *SessionRepository) ExistsActiveInScope(sessionID, tenantID, subtenantID string) (bool, error) {
	var id string
	err := r.db.QueryRow(
		`SELECT id FROM maniforge_sessions
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3
		   AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1`,
		sessionID, tenantID, subtenantID).Scan(&id)
	if err == sql.ErrNoRows {
		return false, nil
	}
	return err == nil, err
}

func (r *SessionRepository) RevokeInScope(sessionID, tenantID, subtenantID, reason string) (bool, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_sessions
		 SET revoked_at = NOW(), revoke_reason = $4
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3 AND revoked_at IS NULL`,
		sessionID, tenantID, subtenantID, reason)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func (r *SessionRepository) RevokeBatchInScope(sessionIDs []string, tenantID, subtenantID, reason string) (int, error) {
	if len(sessionIDs) == 0 {
		return 0, nil
	}
	revoked := 0
	for _, sessionID := range sessionIDs {
		ok, err := r.RevokeInScope(sessionID, tenantID, subtenantID, reason)
		if err != nil {
			return revoked, err
		}
		if ok {
			revoked++
		}
	}
	return revoked, nil
}

// RevokeAllForUser отзывает все активные сессии и refresh-токены пользователя.
func (r *SessionRepository) RevokeAllForUser(userID int64, reason string) (int, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_sessions
		 SET revoked_at = NOW(), revoke_reason = $2
		 WHERE user_id = $1 AND revoked_at IS NULL`, userID, reason)
	if err != nil {
		return 0, err
	}
	sessions, _ := res.RowsAffected()

	_, err = r.db.Exec(
		`UPDATE maniforge_refresh_tokens
		 SET revoked_at = NOW(), revoke_reason = $2
		 WHERE user_id = $1 AND revoked_at IS NULL`, userID, reason)
	if err != nil {
		return int(sessions), err
	}
	return int(sessions), nil
}

type SessionCreateInput struct {
	ID              string
	UserID          int64
	TenantID        string
	SubtenantID     string
	ProjectID       sql.NullInt64
	AccessToken     string
	CsrfToken       string
	IP              string
	UserAgent       string
	AAL             string
	ExpiresAt       string
	SecurityVersion int
}

type RefreshCreateInput struct {
	ID           string
	SessionID    string
	UserID       int64
	TenantID     string
	SubtenantID  string
	ProjectID    sql.NullInt64
	RefreshToken string
	ExpiresAt    string
}

func RandomHex(nBytes int) (string, error) {
	b := make([]byte, nBytes)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

func hashToken(token string) string {
	return hashString(token)
}

// HashCredentialToken — SHA-256 hex для invite/session lookup (тесты, racebench).
func HashCredentialToken(token string) string {
	return hashToken(token)
}

func hashString(v string) string {
	sum := sha256.Sum256([]byte(v))
	return hex.EncodeToString(sum[:])
}

func SessionExpiresAt(ttlMinutes int) string {
	return time.Now().UTC().Add(time.Duration(ttlMinutes) * time.Minute).Format("2006-01-02 15:04:05")
}

func RefreshExpiresAt(days int) string {
	return time.Now().UTC().Add(time.Duration(days) * 24 * time.Hour).Format("2006-01-02 15:04:05")
}

func (r *SessionRepository) CountActiveInScope(tenantID, subtenantID string) (int, error) {
	var total int
	err := r.db.QueryRow(
		`SELECT COUNT(*) FROM maniforge_sessions
		 WHERE tenant_id = $1 AND subtenant_id = $2
		   AND revoked_at IS NULL AND expires_at > NOW()`,
		tenantID, subtenantID).Scan(&total)
	return total, err
}

func (r *SessionRepository) RevokeAllInTenant(tenantID string, subtenantID *string, reason string) (int, error) {
	query := `UPDATE maniforge_sessions SET revoked_at = NOW(), revoke_reason = $2
	          WHERE tenant_id = $1 AND revoked_at IS NULL`
	args := []any{tenantID, reason}
	if subtenantID != nil && *subtenantID != "" {
		query += ` AND subtenant_id = $3`
		args = append(args, *subtenantID)
	}
	res, err := r.db.Exec(query, args...)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

func (r *SessionRepository) RevokeAllRefreshInTenant(tenantID string, subtenantID *string, reason string) (int, error) {
	query := `UPDATE maniforge_refresh_tokens SET revoked_at = NOW(), revoke_reason = $2
	          WHERE tenant_id = $1 AND revoked_at IS NULL`
	args := []any{tenantID, reason}
	if subtenantID != nil && *subtenantID != "" {
		query += ` AND subtenant_id = $3`
		args = append(args, *subtenantID)
	}
	res, err := r.db.Exec(query, args...)
	if err != nil {
		return 0, err
	}
	n, _ := res.RowsAffected()
	return int(n), nil
}

func DefaultProjectID(db *sql.DB, tenantID, subtenantID string) (sql.NullInt64, error) {
	var id sql.NullInt64
	err := db.QueryRow(
		`SELECT id FROM maniforge_projects
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND code = 'main' AND status = 'active'
		 LIMIT 1`, tenantID, subtenantID).Scan(&id)
	if err == sql.ErrNoRows {
		return sql.NullInt64{}, nil
	}
	if err != nil {
		return sql.NullInt64{}, fmt.Errorf("default project: %w", err)
	}
	return id, nil
}
