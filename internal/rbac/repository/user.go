// Package repository — доступ к PostgreSQL для RBAC (users, sessions, roles, …).
//
// Файл: user.go
// Назначение: maniforge_users — критичная идентичность (phone, email, password, status).
// Изменение identity → security_version++ (см. ApplyIdentityUpdate, user_security service).
// См. также: user_profile.go, docs/MANIFORGE_GO_CODEMAP.md
package repository

import (
	"database/sql"
	"fmt"
	"regexp"
	"strings"

	"maniforge/internal/config"
	"maniforge/internal/platform/code"
	"maniforge/internal/rbac/security"
)

var phonePattern = regexp.MustCompile(`^\+\d{10,15}$`)

// User — критичные security-данные идентичности.
// Любое изменение полей identity → security_version++ и отзыв всех сессий.
// Мягкие данные (display_name, avatar, …) — в maniforge_user_profile.
type User struct {
	ID              int64
	TenantID        string
	SubtenantID     string
	Login           string
	Phone           string
	Email           sql.NullString
	PasswordHash    string
	MFARequired     bool
	SecurityVersion int
	Status          string
}

// IdentityUpdateInput — только поля maniforge_users (критичные).
type IdentityUpdateInput struct {
	Email        *string
	Phone        *string
	PasswordHash *string
	MFARequired  *bool
	Status       *string
	Login        *string
}

type UserRepository struct {
	db  *sql.DB
	pii *security.PII
}

func NewUserRepository(db *sql.DB, cfg config.Config) *UserRepository {
	return &UserRepository{db: db, pii: security.NewPII(cfg)}
}

func (r *UserRepository) FindAllByPhone(phone string) ([]User, error) {
	lookup := r.pii.PhoneLookupGlobal(phone)
	rows, err := r.db.Query(
		`SELECT id, tenant_id, subtenant_id, login, phone, email, password_hash,
		        mfa_required, security_version, status, pii_enc_version, phone_enc, email_enc
		 FROM maniforge_users WHERE phone = $1 ORDER BY updated_at DESC, id DESC`, lookup)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var users []User
	for rows.Next() {
		u, err := r.scanUser(rows)
		if err != nil {
			return nil, err
		}
		users = append(users, u)
	}
	return users, rows.Err()
}

func (r *UserRepository) FindByID(id int64) (*User, error) {
	row := r.db.QueryRow(
		`SELECT id, tenant_id, subtenant_id, login, phone, email, password_hash,
		        mfa_required, security_version, status, pii_enc_version, phone_enc, email_enc
		 FROM maniforge_users WHERE id = $1 LIMIT 1`, id)
	return r.scanUserRow(row)
}

func (r *UserRepository) FindByIDInScope(id int64, tenantID, subtenantID string) (*User, error) {
	row := r.db.QueryRow(
		`SELECT id, tenant_id, subtenant_id, login, phone, email, password_hash,
		        mfa_required, security_version, status, pii_enc_version, phone_enc, email_enc
		 FROM maniforge_users
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3 LIMIT 1`,
		id, tenantID, subtenantID)

	u, err := r.scanUserRow(row)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return u, err
}

func (r *UserRepository) scanUser(rows *sql.Rows) (User, error) {
	var (
		u          User
		mfa        bool
		piiVersion int
		phoneEnc   sql.NullString
		emailEnc   sql.NullString
	)
	err := rows.Scan(
		&u.ID, &u.TenantID, &u.SubtenantID, &u.Login, &u.Phone, &u.Email, &u.PasswordHash,
		&mfa, &u.SecurityVersion, &u.Status, &piiVersion, &phoneEnc, &emailEnc,
	)
	if err != nil {
		return User{}, err
	}
	u.MFARequired = mfa
	u.Phone, u.Email = r.pii.HydrateContact(piiVersion, u.Phone, u.Email, phoneEnc, emailEnc)
	return u, nil
}

func (r *UserRepository) scanUserRow(row *sql.Row) (*User, error) {
	var (
		u          User
		mfa        bool
		piiVersion int
		phoneEnc   sql.NullString
		emailEnc   sql.NullString
	)
	err := row.Scan(
		&u.ID, &u.TenantID, &u.SubtenantID, &u.Login, &u.Phone, &u.Email, &u.PasswordHash,
		&mfa, &u.SecurityVersion, &u.Status, &piiVersion, &phoneEnc, &emailEnc,
	)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	u.MFARequired = mfa
	u.Phone, u.Email = r.pii.HydrateContact(piiVersion, u.Phone, u.Email, phoneEnc, emailEnc)
	return &u, nil
}

func ValidatePhone(phone string) bool {
	return phonePattern.MatchString(phone)
}

func FilterUsersByScope(users []User, tenantID, subtenantID string) []User {
	tenantID = code.Normalize(tenantID)
	subtenantID = code.Normalize(subtenantID)
	if tenantID == "" || subtenantID == "" {
		return users
	}
	var out []User
	for _, u := range users {
		if code.Normalize(u.TenantID) == tenantID && code.Normalize(u.SubtenantID) == subtenantID {
			out = append(out, u)
		}
	}
	return out
}

// FilterActiveUsers исключает удалённых/заблокированных пользователей из phone-login.
func FilterActiveUsers(users []User) []User {
	var out []User
	for _, u := range users {
		if u.Status == "active" {
			out = append(out, u)
		}
	}
	return out
}

func ResolvePhoneLoginUser(users []User, password string) *User {
	for i := range users {
		if security.VerifyPassword(password, users[i].PasswordHash) {
			u := users[i]
			return &u
		}
	}
	return nil
}

func (r *UserRepository) FindByLogin(tenantID, subtenantID, login string) (*User, error) {
	row := r.db.QueryRow(
		`SELECT id, tenant_id, subtenant_id, login, phone, email, password_hash,
		        mfa_required, security_version, status, pii_enc_version, phone_enc, email_enc
		 FROM maniforge_users
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND login = $3 LIMIT 1`,
		tenantID, subtenantID, login)
	return r.scanUserRow(row)
}

func (r *UserRepository) CountActiveUsers(tenantID, subtenantID string) (int, error) {
	var total int
	err := r.db.QueryRow(
		`SELECT COUNT(*) FROM maniforge_users
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND status = 'active'`,
		tenantID, subtenantID).Scan(&total)
	return total, err
}

func (r *UserRepository) HasActivePhone(phone string) (bool, error) {
	lookup := r.pii.PhoneLookupGlobal(phone)
	rows, err := r.db.Query(
		`SELECT status FROM maniforge_users WHERE phone = $1`, lookup)
	if err != nil {
		return false, err
	}
	defer rows.Close()
	for rows.Next() {
		var status string
		if err := rows.Scan(&status); err != nil {
			return false, err
		}
		if status == "active" {
			return true, nil
		}
	}
	return false, rows.Err()
}

type CreateUserInput struct {
	TenantID     string
	SubtenantID  string
	Login        string
	Email        string
	Phone        string
	PasswordHash string
	MFARequired  bool
	Status       string
}

// ApplyIdentityUpdate обновляет критичные поля и инкрементирует security_version.
func (r *UserRepository) ApplyIdentityUpdate(
	userID int64, tenantID, subtenantID string, input IdentityUpdateInput,
) (*User, error) {
	current, err := r.FindByIDInScope(userID, tenantID, subtenantID)
	if err != nil || current == nil {
		return current, err
	}

	sets := []string{"updated_at = NOW()"}
	args := []any{userID, tenantID, subtenantID}
	argN := 4
	changed := false

	if input.Email != nil {
		changed = true
		email := sql.NullString{}
		v := strings.ToLower(strings.TrimSpace(*input.Email))
		if v != "" {
			email = sql.NullString{String: v, Valid: true}
		}
		sets = append(sets, fmt.Sprintf("email = $%d", argN))
		args = append(args, email)
		argN++
	}
	if input.Phone != nil {
		changed = true
		sets = append(sets, fmt.Sprintf("phone = $%d", argN))
		args = append(args, r.pii.PhoneLookupGlobal(*input.Phone))
		argN++
	}
	if input.PasswordHash != nil {
		changed = true
		sets = append(sets, fmt.Sprintf("password_hash = $%d", argN))
		args = append(args, *input.PasswordHash)
		argN++
		sets = append(sets, "last_password_changed_at = NOW()")
	}
	if input.MFARequired != nil {
		changed = true
		sets = append(sets, fmt.Sprintf("mfa_required = $%d", argN))
		args = append(args, *input.MFARequired)
		argN++
	}
	if input.Status != nil {
		changed = true
		sets = append(sets, fmt.Sprintf("status = $%d", argN))
		args = append(args, strings.TrimSpace(*input.Status))
		argN++
	}
	if input.Login != nil {
		changed = true
		sets = append(sets, fmt.Sprintf("login = $%d", argN))
		args = append(args, strings.TrimSpace(*input.Login))
		argN++
	}

	if !changed {
		return current, nil
	}
	sets = append(sets, "security_version = security_version + 1")

	query := fmt.Sprintf(
		`UPDATE maniforge_users SET %s
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3`,
		strings.Join(sets, ", "),
	)
	_, err = r.db.Exec(query, args...)
	if err != nil {
		return nil, err
	}
	return r.FindByIDInScope(userID, tenantID, subtenantID)
}

func (r *UserRepository) CreateUser(input CreateUserInput) (*User, error) {
	packed, err := r.pii.PackForStorage(input.Email, input.Phone, input.TenantID, input.SubtenantID)
	if err != nil {
		return nil, err
	}

	var id int64
	err = r.db.QueryRow(
		`INSERT INTO maniforge_users (
			tenant_id, subtenant_id, login, email, phone, email_enc, phone_enc, pii_enc_version,
			password_hash, mfa_required, security_version, status, last_password_changed_at
		) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, 1, $11, NOW())
		RETURNING id`,
		input.TenantID, input.SubtenantID, input.Login,
		packed.Email, packed.Phone, packed.EmailEnc, packed.PhoneEnc, packed.PIIEncVersion,
		input.PasswordHash, input.MFARequired, input.Status).Scan(&id)
	if err != nil {
		return nil, err
	}
	if err := NewUserProfileRepository(r.db).EnsureEmpty(id); err != nil {
		return nil, err
	}
	return r.FindByIDInScope(id, input.TenantID, input.SubtenantID)
}

func (r *UserRepository) FindStatusInScope(userID int64, tenantID, subtenantID string) (*string, error) {
	var status string
	err := r.db.QueryRow(
		`SELECT status FROM maniforge_users
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3 LIMIT 1`,
		userID, tenantID, subtenantID).Scan(&status)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &status, nil
}

func (r *UserRepository) ListUsers(tenantID, subtenantID string, limit int) ([]User, error) {
	if limit < 1 {
		limit = 50
	}
	rows, err := r.db.Query(
		`SELECT id, tenant_id, subtenant_id, login, phone, email, password_hash,
		        mfa_required, security_version, status, pii_enc_version, phone_enc, email_enc
		 FROM maniforge_users
		 WHERE tenant_id = $1 AND subtenant_id = $2
		 ORDER BY id DESC LIMIT $3`,
		tenantID, subtenantID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var users []User
	for rows.Next() {
		u, err := r.scanUser(rows)
		if err != nil {
			return nil, err
		}
		users = append(users, u)
	}
	return users, rows.Err()
}

type StatusBatchItem struct {
	UserID int64
	Status string
}

type StatusBatchSummary struct {
	Changed          int            `json:"changed"`
	Skipped          int            `json:"skipped"`
	NotFound         int            `json:"not_found"`
	Total            int            `json:"total"`
	ByStatus         map[string]int `json:"by_status"`
	RevokedSessions  int            `json:"revoked_sessions,omitempty"`
}

func (r *UserRepository) ApplyStatusBatchInScope(tenantID, subtenantID string, items []StatusBatchItem) (StatusBatchSummary, error) {
	summary := StatusBatchSummary{
		Total: len(items),
		ByStatus: map[string]int{"active": 0, "locked": 0, "disabled": 0},
	}
	tx, err := r.db.Begin()
	if err != nil {
		return summary, err
	}
	defer func() { _ = tx.Rollback() }()

	for _, item := range items {
		if _, ok := summary.ByStatus[item.Status]; ok {
			summary.ByStatus[item.Status]++
		}
		var current string
		err := tx.QueryRow(
			`SELECT status FROM maniforge_users
			 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3 LIMIT 1`,
			item.UserID, tenantID, subtenantID).Scan(&current)
		if err == sql.ErrNoRows {
			summary.NotFound++
			continue
		}
		if err != nil {
			return summary, err
		}
		if current == item.Status {
			summary.Skipped++
			continue
		}
		_, err = tx.Exec(
			`UPDATE maniforge_users SET status = $1, updated_at = NOW()
			 WHERE id = $2 AND tenant_id = $3 AND subtenant_id = $4`,
			item.Status, item.UserID, tenantID, subtenantID)
		if err != nil {
			return summary, err
		}
		summary.Changed++
	}
	if err := tx.Commit(); err != nil {
		return summary, err
	}
	return summary, nil
}

func AdminUser(u User) map[string]any {
	payload := map[string]any{
		"id": u.ID, "login": u.Login, "phone": u.Phone, "status": u.Status,
		"mfa_required": u.MFARequired, "security_version": u.SecurityVersion,
	}
	if u.Email.Valid && u.Email.String != "" {
		payload["email"] = u.Email.String
	}
	return payload
}

func PublicUser(u User) map[string]any {
	payload := map[string]any{
		"id":     u.ID,
		"phone":  u.Phone,
		"status": u.Status,
	}
	if u.Email.Valid && u.Email.String != "" {
		payload["email"] = u.Email.String
	}
	return payload
}

func PublicUserWithProfile(u User, profile *UserProfile) map[string]any {
	payload := PublicUser(u)
	payload["profile"] = PublicProfile(profile)
	return payload
}
