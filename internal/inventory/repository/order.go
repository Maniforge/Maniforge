// Файл: order.go
// Назначение: заказы maniforge_inv_orders.
package repository

import (
	"database/sql"
	"encoding/json"
	"time"
)

type OrderLine struct {
	ProductID  int64
	QtyOrdered string
}

type OrderRepository struct {
	db *sql.DB
}

func NewOrderRepository(db *sql.DB) *OrderRepository {
	return &OrderRepository{db: db}
}

func OrderRefCode(orderNumber string) string {
	return "inv-order:" + orderNumber
}

func (r *OrderRepository) Create(tenantID, orderNumber string, stockID int64, note *string,
	metadata json.RawMessage, createdBy int64, lines []OrderLine) (int64, error) {
	tx, err := r.db.Begin()
	if err != nil {
		return 0, err
	}
	defer func() { _ = tx.Rollback() }()
	var id int64
	err = tx.QueryRow(`
		INSERT INTO maniforge_inv_orders (tenant_id, order_number, stock_id, note, metadata_json, created_by, status)
		VALUES ($1,$2,$3,$4,$5,$6,'draft') RETURNING id`,
		tenantID, orderNumber, stockID, note, nullJSONBytes(metadata), createdBy).Scan(&id)
	if err != nil {
		return 0, err
	}
	for i, line := range lines {
		_, err = tx.Exec(`
			INSERT INTO maniforge_inv_order_lines (order_id, line_no, product_id, qty_ordered)
			VALUES ($1,$2,$3,$4::numeric)`, id, i+1, line.ProductID, line.QtyOrdered)
		if err != nil {
			return 0, err
		}
	}
	return id, tx.Commit()
}

func (r *OrderRepository) FindByIDInTenant(id int64, tenantID string) (map[string]any, error) {
	row := r.db.QueryRow(`
		SELECT id, tenant_id, order_number, status, stock_id, note, metadata_json,
			created_by, confirmed_at, fulfilled_at, cancelled_at, created_at
		FROM maniforge_inv_orders WHERE id = $1 AND tenant_id = $2`, id, tenantID)
	return scanOrder(r.db, row)
}

func (r *OrderRepository) UpdateStatus(id int64, tenantID, status, tsCol string) (bool, error) {
	q := `UPDATE maniforge_inv_orders SET status = $3, updated_at = NOW()`
	switch tsCol {
	case "confirmed_at":
		q += `, confirmed_at = NOW()`
	case "fulfilled_at":
		q += `, fulfilled_at = NOW()`
	case "cancelled_at":
		q += `, cancelled_at = NOW()`
	}
	q += ` WHERE id = $1 AND tenant_id = $2`
	res, err := r.db.Exec(q, id, tenantID, status)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func scanOrder(db *sql.DB, row *sql.Row) (map[string]any, error) {
	var id, stockID int64
	var tenantID, orderNumber, status string
	var note sql.NullString
	var meta sql.NullString
	var createdBy sql.NullInt64
	var confirmedAt, fulfilledAt, cancelledAt sql.NullTime
	var createdAt time.Time
	err := row.Scan(&id, &tenantID, &orderNumber, &status, &stockID, &note, &meta,
		&createdBy, &confirmedAt, &fulfilledAt, &cancelledAt, &createdAt)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	o := map[string]any{
		"id": id, "tenant_id": tenantID, "order_number": orderNumber,
		"status": status, "stock_id": stockID,
		"created_at": createdAt.UTC().Format("2006-01-02 15:04:05"),
	}
	if note.Valid {
		o["note"] = note.String
	}
	if meta.Valid && meta.String != "" {
		var data any
		if json.Unmarshal([]byte(meta.String), &data) == nil {
			o["metadata"] = data
		}
	}
	if createdBy.Valid {
		o["created_by"] = createdBy.Int64
	}
	if confirmedAt.Valid {
		o["confirmed_at"] = confirmedAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	if fulfilledAt.Valid {
		o["fulfilled_at"] = fulfilledAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	if cancelledAt.Valid {
		o["cancelled_at"] = cancelledAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	lines, err := orderLines(db, id)
	if err != nil {
		return nil, err
	}
	o["lines"] = lines
	return o, nil
}

func orderLines(db *sql.DB, orderID int64) ([]map[string]any, error) {
	rows, err := db.Query(`
		SELECT id, line_no, product_id, qty_ordered::text
		FROM maniforge_inv_order_lines WHERE order_id = $1 ORDER BY line_no`, orderID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []map[string]any
	for rows.Next() {
		var id, lineNo, productID int64
		var qty string
		if err := rows.Scan(&id, &lineNo, &productID, &qty); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id": id, "line_no": lineNo, "product_id": productID, "qty_ordered": qty,
		})
	}
	return out, rows.Err()
}
