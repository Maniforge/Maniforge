// Файл: reserve.go
// Назначение: резервы maniforge_inv_reserves.
package repository

import (
	"database/sql"

	"maniforge/internal/platform/qty"
)

type ReserveRepository struct {
	db *sql.DB
}

func NewReserveRepository(db *sql.DB) *ReserveRepository {
	return &ReserveRepository{db: db}
}

func (r *ReserveRepository) SumActiveForPair(tenantID string, productID, stockID int64) (string, error) {
	var sum sql.NullString
	err := r.db.QueryRow(`
		SELECT COALESCE(SUM(qty)::text, '0') FROM maniforge_inv_reserves
		WHERE tenant_id = $1 AND product_id = $2 AND stock_id = $3 AND status = 'active'`,
		tenantID, productID, stockID).Scan(&sum)
	if err != nil {
		return "0.000000", err
	}
	if !sum.Valid {
		return "0.000000", nil
	}
	return qty.Format(sum.String), nil
}

func (r *ReserveRepository) Create(tenantID string, productID, stockID int64, q, refCode, note string, createdBy int64) (int64, error) {
	var id int64
	err := r.db.QueryRow(`
		INSERT INTO maniforge_inv_reserves (tenant_id, product_id, stock_id, qty, ref_code, note, created_by, status)
		VALUES ($1,$2,$3,$4::numeric,$5,$6,$7,'active') RETURNING id`,
		tenantID, productID, stockID, q, refCode, note, createdBy).Scan(&id)
	return id, err
}

func (r *ReserveRepository) ReleaseActiveByRefCode(tenantID, refCode string, releasedBy int64) error {
	_, err := r.db.Exec(`
		UPDATE maniforge_inv_reserves SET status = 'released', released_by = $3, released_at = NOW(), updated_at = NOW()
		WHERE tenant_id = $1 AND ref_code = $2 AND status = 'active'`,
		tenantID, refCode, releasedBy)
	return err
}
