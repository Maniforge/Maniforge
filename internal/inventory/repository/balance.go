// Файл: balance.go
// Назначение: остатки maniforge_inv_balances.
// См. также: app/Maniforge/Inventory/Repository/BalanceRepository.php
package repository

import (
	"database/sql"
	"fmt"
	"time"

	"maniforge/internal/platform/qty"
	rbacrepo "maniforge/internal/rbac/repository"
	whrepo "maniforge/internal/warehouses/repository"
)

type BalanceRepository struct {
	db       *sql.DB
	reserves *ReserveRepository
}

func NewBalanceRepository(db *sql.DB) *BalanceRepository {
	return &BalanceRepository{db: db, reserves: NewReserveRepository(db)}
}

func (r *BalanceRepository) QtyForPair(tenantID string, productID, stockID int64) string {
	var q string
	err := r.db.QueryRow(`
		SELECT COALESCE(qty::text, '0') FROM maniforge_inv_balances
		WHERE tenant_id = $1 AND product_id = $2 AND stock_id = $3`,
		tenantID, productID, stockID).Scan(&q)
	if err == sql.ErrNoRows {
		return "0.000000"
	}
	if err != nil {
		return "0.000000"
	}
	return qty.Format(q)
}

func (r *BalanceRepository) AvailableQtyForPair(tenantID string, productID, stockID int64) string {
	onHand := r.QtyForPair(tenantID, productID, stockID)
	reserved, _ := r.reserves.SumActiveForPair(tenantID, productID, stockID)
	return qty.Sub(onHand, reserved)
}

func (r *BalanceRepository) ApplyDelta(tenantID string, productID, stockID int64, delta string) error {
	_, err := r.db.Exec(`
		INSERT INTO maniforge_inv_balances (tenant_id, product_id, stock_id, qty)
		VALUES ($1, $2, $3, $4::numeric)
		ON CONFLICT (tenant_id, product_id, stock_id)
		DO UPDATE SET qty = maniforge_inv_balances.qty + EXCLUDED.qty, updated_at = NOW()`,
		tenantID, productID, stockID, delta)
	return err
}

func (r *BalanceRepository) ListVisible(session *rbacrepo.SessionRecord, filters map[string]any) ([]map[string]any, error) {
	projectID, err := whrepo.SessionProjectID(r.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if err != nil {
		return nil, err
	}
	sqlText := `
		SELECT b.id, b.tenant_id, b.product_id, b.stock_id, b.qty::text, b.updated_at,
			p.code, p.name, p.unit, s.code, s.name, s.type
		FROM maniforge_inv_balances b
		INNER JOIN maniforge_products p ON p.id = b.product_id AND p.tenant_id = b.tenant_id
		INNER JOIN maniforge_wh_stocks s ON s.id = b.stock_id AND s.tenant_id = b.tenant_id
		WHERE b.tenant_id = $1
		  AND p.tenant_id = $1 AND p.subtenant_id = $2 AND (p.project_id IS NULL OR p.project_id = $3)
		  AND s.tenant_id = $1 AND s.subtenant_id = $2 AND (s.project_id IS NULL OR s.project_id = $3)`
	args := []any{session.TenantID, session.SubtenantID, projectID}
	n := 4
	if pid, ok := filters["product_id"].(int64); ok && pid > 0 {
		sqlText += fmt.Sprintf(` AND b.product_id = $%d`, n)
		args = append(args, pid)
		n++
	} else if pid, ok := filters["product_id"].(int); ok && pid > 0 {
		sqlText += fmt.Sprintf(` AND b.product_id = $%d`, n)
		args = append(args, pid)
		n++
	}
	if sid, ok := filters["stock_id"].(int64); ok && sid > 0 {
		sqlText += fmt.Sprintf(` AND b.stock_id = $%d`, n)
		args = append(args, sid)
		n++
	} else if sid, ok := filters["stock_id"].(int); ok && sid > 0 {
		sqlText += fmt.Sprintf(` AND b.stock_id = $%d`, n)
		args = append(args, sid)
		n++
	}
	if nz, ok := filters["non_zero"].(bool); ok && nz {
		sqlText += ` AND b.qty <> 0`
	}
	sqlText += ` ORDER BY p.name ASC, s.name ASC`
	rows, err := r.db.Query(sqlText, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []map[string]any
	for rows.Next() {
		var id, productID, stockID int64
		var tenantID, q, pCode, pName, pUnit, sCode, sName, sType string
		var updatedAt time.Time
		if err := rows.Scan(&id, &tenantID, &productID, &stockID, &q, &updatedAt,
			&pCode, &pName, &pUnit, &sCode, &sName, &sType); err != nil {
			return nil, err
		}
		q = qty.Format(q)
		reserved, _ := r.reserves.SumActiveForPair(tenantID, productID, stockID)
		item := map[string]any{
			"id": id, "tenant_id": tenantID, "product_id": productID, "stock_id": stockID,
			"qty": q, "qty_reserved": reserved, "qty_available": qty.Sub(q, reserved),
			"updated_at": updatedAt.UTC().Format("2006-01-02 15:04:05"),
			"product_code": pCode, "product_name": pName, "product_unit": pUnit,
			"stock_code": sCode, "stock_name": sName, "stock_type": sType,
		}
		out = append(out, item)
	}
	return out, rows.Err()
}
