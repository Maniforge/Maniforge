// Файл: mfa.go
// Назначение: TOTP factors и recovery codes (maniforge_mfa_*).
// См. также: service/mfa.go
package repository

import (
	"database/sql"
	"time"
)

type MFAFactor struct {
	ID          int64
	UserID      int64
	TenantID    string
	SubtenantID string
	FactorType  string
	Label       string
	SecretEnc   string
	VerifiedAt  sql.NullTime
	CreatedAt   time.Time
	RevokedAt   sql.NullTime
}

type MFARepository struct {
	db *sql.DB
}

func NewMFARepository(db *sql.DB) *MFARepository {
	return &MFARepository{db: db}
}

func (r *MFARepository) UpsertPendingTOTP(userID int64, tenantID, subtenantID, label, secretEnc string) error {
	_, err := r.db.Exec(
		`INSERT INTO maniforge_mfa_factors (
			user_id, tenant_id, subtenant_id, factor_type, label, secret_enc, verified_at, revoked_at
		) VALUES ($1, $2, $3, 'totp', $4, $5, NULL, NULL)
		ON CONFLICT (user_id, tenant_id, subtenant_id, factor_type)
		DO UPDATE SET label = EXCLUDED.label, secret_enc = EXCLUDED.secret_enc,
		              verified_at = NULL, revoked_at = NULL, created_at = NOW()`,
		userID, tenantID, subtenantID, label, secretEnc,
	)
	return err
}

func (r *MFARepository) ActiveTOTP(userID int64, tenantID, subtenantID string) (*MFAFactor, error) {
	var f MFAFactor
	err := r.db.QueryRow(
		`SELECT id, user_id, tenant_id, subtenant_id, factor_type, label, secret_enc, verified_at, created_at, revoked_at
		 FROM maniforge_mfa_factors
		 WHERE user_id = $1 AND tenant_id = $2 AND subtenant_id = $3
		   AND factor_type = 'totp' AND revoked_at IS NULL
		 LIMIT 1`,
		userID, tenantID, subtenantID,
	).Scan(&f.ID, &f.UserID, &f.TenantID, &f.SubtenantID, &f.FactorType, &f.Label, &f.SecretEnc,
		&f.VerifiedAt, &f.CreatedAt, &f.RevokedAt)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &f, nil
}

func (r *MFARepository) MarkVerified(factorID int64) error {
	_, err := r.db.Exec(`UPDATE maniforge_mfa_factors SET verified_at = NOW() WHERE id = $1`, factorID)
	return err
}

func (r *MFARepository) RevokeTOTP(userID int64, tenantID, subtenantID string) error {
	_, err := r.db.Exec(
		`UPDATE maniforge_mfa_factors SET revoked_at = NOW()
		 WHERE user_id = $1 AND tenant_id = $2 AND subtenant_id = $3 AND factor_type = 'totp' AND revoked_at IS NULL`,
		userID, tenantID, subtenantID,
	)
	return err
}

func (r *MFARepository) ReplaceRecoveryCodes(userID int64, tenantID, subtenantID string, hashes []string) error {
	tx, err := r.db.Begin()
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()
	_, err = tx.Exec(
		`DELETE FROM maniforge_mfa_recovery_codes
		 WHERE user_id = $1 AND tenant_id = $2 AND subtenant_id = $3`,
		userID, tenantID, subtenantID,
	)
	if err != nil {
		return err
	}
	for _, h := range hashes {
		_, err = tx.Exec(
			`INSERT INTO maniforge_mfa_recovery_codes (user_id, tenant_id, subtenant_id, code_hash)
			 VALUES ($1, $2, $3, $4)`,
			userID, tenantID, subtenantID, h,
		)
		if err != nil {
			return err
		}
	}
	return tx.Commit()
}

func (r *MFARepository) ConsumeRecoveryCode(userID int64, tenantID, subtenantID, codeHash string) (bool, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_mfa_recovery_codes SET used_at = NOW()
		 WHERE user_id = $1 AND tenant_id = $2 AND subtenant_id = $3
		   AND code_hash = $4 AND used_at IS NULL`,
		userID, tenantID, subtenantID, codeHash,
	)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func (r *MFARepository) UnusedRecoveryCount(userID int64, tenantID, subtenantID string) (int, error) {
	var n int
	err := r.db.QueryRow(
		`SELECT COUNT(*) FROM maniforge_mfa_recovery_codes
		 WHERE user_id = $1 AND tenant_id = $2 AND subtenant_id = $3 AND used_at IS NULL`,
		userID, tenantID, subtenantID,
	).Scan(&n)
	return n, err
}
