// Файл: lot.go
// Назначение: партии maniforge_inv_lots.
package repository

import (
	"database/sql"
	"time"
)

type LotRepository struct {
	db *sql.DB
}

func NewLotRepository(db *sql.DB) *LotRepository {
	return &LotRepository{db: db}
}

func (r *LotRepository) FindByKey(tenantID string, productID int64, batchCode, lotCode string) (map[string]any, error) {
	row := r.db.QueryRow(`
		SELECT id, tenant_id, product_id, batch_code, lot_code, status, created_at
		FROM maniforge_inv_lots
		WHERE tenant_id = $1 AND product_id = $2 AND batch_code = $3 AND lot_code = $4`,
		tenantID, productID, batchCode, lotCode)
	return scanLot(row)
}

func (r *LotRepository) Create(tenantID string, productID int64, batchCode, lotCode string, createdBy int64) (int64, error) {
	var id int64
	err := r.db.QueryRow(`
		INSERT INTO maniforge_inv_lots (tenant_id, product_id, batch_code, lot_code, created_by, status)
		VALUES ($1,$2,$3,$4,$5,'active') RETURNING id`,
		tenantID, productID, batchCode, lotCode, createdBy).Scan(&id)
	return id, err
}

func (r *LotRepository) FindByIDInTenant(id int64, tenantID string) (map[string]any, error) {
	row := r.db.QueryRow(`
		SELECT id, tenant_id, product_id, batch_code, lot_code, status, created_at
		FROM maniforge_inv_lots WHERE id = $1 AND tenant_id = $2`, id, tenantID)
	return scanLot(row)
}

func scanLot(row *sql.Row) (map[string]any, error) {
	var id, productID int64
	var tenantID, batchCode, lotCode, status string
	var createdAt time.Time
	err := row.Scan(&id, &tenantID, &productID, &batchCode, &lotCode, &status, &createdAt)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id": id, "tenant_id": tenantID, "product_id": productID,
		"batch_code": batchCode, "lot_code": lotCode, "status": status,
		"created_at": createdAt.UTC().Format("2006-01-02 15:04:05"),
	}, nil
}
