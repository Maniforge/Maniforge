// Файл: invite.go
// Назначение: registration invites — чтение и погашение токена при invite-register.
// Таблицы: maniforge_registration_invites.
// См. также: service/registration.go
package repository

import (
	"database/sql"
	"encoding/json"
	"time"
)

type InviteRecord struct {
	ID             int64
	TenantID       string
	SubtenantName  string
	SubtenantCode  sql.NullString
	RoleCode       string
	MetadataJSON   []byte
}

type InviteRepository struct {
	db *sql.DB
}

func NewInviteRepository(db *sql.DB) *InviteRepository {
	return &InviteRepository{db: db}
}

func (r *InviteRepository) IsConsumedToken(rawToken string) bool {
	var id int64
	err := r.db.QueryRow(
		`SELECT id FROM maniforge_registration_invites
		 WHERE token_hash = $1 AND status = 'consumed' AND consumed_at IS NOT NULL
		 LIMIT 1`, hashToken(rawToken)).Scan(&id)
	return err == nil
}

func (r *InviteRepository) FindPendingByToken(rawToken string) (*InviteRecord, error) {
	row := r.db.QueryRow(
		`SELECT id, tenant_id, subtenant_name, subtenant_code, role_code, metadata_json
		 FROM maniforge_registration_invites
		 WHERE token_hash = $1 AND status = 'pending' AND consumed_at IS NULL AND expires_at > NOW()
		 LIMIT 1`, hashToken(rawToken))

	var rec InviteRecord
	var meta []byte
	err := row.Scan(&rec.ID, &rec.TenantID, &rec.SubtenantName, &rec.SubtenantCode, &rec.RoleCode, &meta)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	rec.MetadataJSON = meta
	return &rec, nil
}

func (r *InviteRepository) ClaimPendingByToken(rawToken, subtenantCode string) (*InviteRecord, error) {
	tx, err := r.db.Begin()
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()

	row := tx.QueryRow(
		`SELECT id, tenant_id, subtenant_name, subtenant_code, role_code, metadata_json
		 FROM maniforge_registration_invites
		 WHERE token_hash = $1 AND status = 'pending' AND consumed_at IS NULL AND expires_at > NOW()
		 LIMIT 1 FOR UPDATE`, hashToken(rawToken))

	var rec InviteRecord
	var meta []byte
	err = row.Scan(&rec.ID, &rec.TenantID, &rec.SubtenantName, &rec.SubtenantCode, &rec.RoleCode, &meta)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	rec.MetadataJSON = meta

	res, err := tx.Exec(
		`UPDATE maniforge_registration_invites
		 SET status = 'consumed', subtenant_code = $2, consumed_at = NOW()
		 WHERE id = $1 AND status = 'pending'`,
		rec.ID, subtenantCode)
	if err != nil {
		return nil, err
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return nil, nil
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	rec.SubtenantCode = sql.NullString{String: subtenantCode, Valid: subtenantCode != ""}
	return &rec, nil
}

func InviteFlow(rec InviteRecord) string {
	if len(rec.MetadataJSON) > 0 {
		var meta map[string]any
		if json.Unmarshal(rec.MetadataJSON, &meta) == nil {
			if flow, ok := meta["flow"].(string); ok && flow != "" {
				return flow
			}
		}
	}
	if rec.SubtenantCode.Valid && rec.SubtenantCode.String != "" {
		return "user_invite"
	}
	return "subtenant_invite"
}

type CreatedInvite struct {
	ID           int64
	TenantID     string
	SubtenantID  string
	RoleCode     string
	ExpiresAt    time.Time
	RawToken     string
}

func (r *InviteRepository) CreateUserInvite(
	tenantID, subtenantID, roleCode string,
	rawToken string, expiresAt time.Time, createdBy int64, metadata map[string]any,
) (*CreatedInvite, error) {
	meta, _ := json.Marshal(metadata)
	var id int64
	err := r.db.QueryRow(
		`INSERT INTO maniforge_registration_invites (
			token_hash, tenant_id, subtenant_name, subtenant_code, role_code, expires_at, created_by, metadata_json
		) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
		RETURNING id`,
		hashToken(rawToken), tenantID, subtenantID, subtenantID, roleCode, expiresAt, createdBy, meta,
	).Scan(&id)
	if err != nil {
		return nil, err
	}
	return &CreatedInvite{
		ID: id, TenantID: tenantID, SubtenantID: subtenantID,
		RoleCode: roleCode, ExpiresAt: expiresAt, RawToken: rawToken,
	}, nil
}
