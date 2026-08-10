// Файл: login_attempt.go
// Назначение: brute-force lockout для login (maniforge_login_attempts).
// См. также: service/auth.go, app/Maniforge/Rbac/Repository/LoginAttemptRepository.php
package repository

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"time"
)

type LoginAttemptRepository struct {
	db         *sql.DB
	maxFails   int
	lockMinutes int
}

func NewLoginAttemptRepository(db *sql.DB, maxFails, lockMinutes int) *LoginAttemptRepository {
	if maxFails < 1 {
		maxFails = 5
	}
	if lockMinutes < 1 {
		lockMinutes = 15
	}
	return &LoginAttemptRepository{db: db, maxFails: maxFails, lockMinutes: lockMinutes}
}

type LoginLock struct {
	FailedCount int
	LockedUntil *time.Time
}

func (r *LoginAttemptRepository) ActiveLock(tenantID, subtenantID, login, ip string) (*LoginLock, error) {
	var failedCount int
	var lockedUntil sql.NullTime
	err := r.db.QueryRow(
		`SELECT failed_count, locked_until
		 FROM maniforge_login_attempts
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND login = $3 AND ip_hash = $4
		   AND locked_until IS NOT NULL AND locked_until > NOW()
		 LIMIT 1`,
		tenantID, subtenantID, login, hashIP(ip),
	).Scan(&failedCount, &lockedUntil)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	lock := &LoginLock{FailedCount: failedCount}
	if lockedUntil.Valid {
		t := lockedUntil.Time
		lock.LockedUntil = &t
	}
	return lock, nil
}

func (r *LoginAttemptRepository) RegisterFailure(tenantID, subtenantID, login, ip string) (LoginLock, error) {
	ipHash := hashIP(ip)
	tx, err := r.db.Begin()
	if err != nil {
		return LoginLock{}, err
	}
	defer func() { _ = tx.Rollback() }()

	var id int64
	var failedCount int
	err = tx.QueryRow(
		`SELECT id, failed_count FROM maniforge_login_attempts
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND login = $3 AND ip_hash = $4
		 FOR UPDATE`,
		tenantID, subtenantID, login, ipHash,
	).Scan(&id, &failedCount)

	if err == sql.ErrNoRows {
		lockedUntil := (*time.Time)(nil)
		if r.maxFails <= 1 {
			t := time.Now().UTC().Add(time.Duration(r.lockMinutes) * time.Minute)
			lockedUntil = &t
		}
		_, err = tx.Exec(
			`INSERT INTO maniforge_login_attempts (
				tenant_id, subtenant_id, login, ip_hash, failed_count, last_failed_at, locked_until, created_at
			) VALUES ($1, $2, $3, $4, 1, NOW(), $5, NOW())`,
			tenantID, subtenantID, login, ipHash, lockedUntil,
		)
		if err != nil {
			return LoginLock{}, err
		}
		if err := tx.Commit(); err != nil {
			return LoginLock{}, err
		}
		return LoginLock{FailedCount: 1, LockedUntil: lockedUntil}, nil
	}
	if err != nil {
		return LoginLock{}, err
	}

	newCount := failedCount + 1
	var lockedUntil *time.Time
	if newCount >= r.maxFails {
		t := time.Now().UTC().Add(time.Duration(r.lockMinutes) * time.Minute)
		lockedUntil = &t
	}
	_, err = tx.Exec(
		`UPDATE maniforge_login_attempts
		 SET failed_count = $2, last_failed_at = NOW(), locked_until = $3
		 WHERE id = $1`,
		id, newCount, lockedUntil,
	)
	if err != nil {
		return LoginLock{}, err
	}
	if err := tx.Commit(); err != nil {
		return LoginLock{}, err
	}
	return LoginLock{FailedCount: newCount, LockedUntil: lockedUntil}, nil
}

func (r *LoginAttemptRepository) Clear(tenantID, subtenantID, login, ip string) error {
	_, err := r.db.Exec(
		`DELETE FROM maniforge_login_attempts
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND login = $3 AND ip_hash = $4`,
		tenantID, subtenantID, login, hashIP(ip),
	)
	return err
}

func hashIP(ip string) string {
	sum := sha256.Sum256([]byte(ip))
	return hex.EncodeToString(sum[:])
}

func FormatLockedUntil(t *time.Time) string {
	if t == nil {
		return ""
	}
	return t.UTC().Format("2006-01-02 15:04:05")
}

func LoginLockPayload(lock LoginLock) map[string]any {
	out := map[string]any{"failed_count": lock.FailedCount}
	if lock.LockedUntil != nil {
		out["locked_until"] = FormatLockedUntil(lock.LockedUntil)
	}
	return out
}

func LoginLockError(lock *LoginLock) map[string]any {
	payload := map[string]any{
		"ok":    false,
		"error": "Слишком много неудачных попыток. Вход временно заблокирован",
	}
	if lock != nil && lock.LockedUntil != nil {
		payload["locked_until"] = FormatLockedUntil(lock.LockedUntil)
	}
	return payload
}

func ScopeKnown(tenantID, subtenantID string) bool {
	return tenantID != "" && subtenantID != ""
}
