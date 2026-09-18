package inventory

import (
	"database/sql"
	"fmt"
	"strings"
	"time"

	"maniforge/internal/rbac/repository"
	"maniforge/internal/supplychain"
)

func (e *Engine) listReserves(sess *repository.SessionRecord, productID, stockID, ref, status string) ([]map[string]any, error) {
	if status == "" {
		status = "active"
	}
	q := `SELECT id, tenant_id, product_id, stock_id, qty::text, ref_code, note, status, created_at
		FROM maniforge_inv_reserves WHERE tenant_id=$1 AND status=$2`
	args := []any{sess.TenantID, status}
	n := 3
	if productID != "" {
		q += fmt.Sprintf(` AND product_id=$%d`, n)
		args = append(args, supplychain.AsInt64(productID))
		n++
	}
	if stockID != "" {
		q += fmt.Sprintf(` AND stock_id=$%d`, n)
		args = append(args, supplychain.AsInt64(stockID))
		n++
	}
	if ref != "" {
		q += fmt.Sprintf(` AND ref_code=$%d`, n)
		args = append(args, ref)
	}
	q += ` ORDER BY id DESC`
	rows, err := e.db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		item, err := scanReserve(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, nil
}

func (e *Engine) createReserve(sess *repository.SessionRecord, in map[string]any) (map[string]any, int) {
	productID := supplychain.Int64(in, "product_id")
	stockID := supplychain.Int64(in, "stock_id")
	qty, ok := supplychain.ParseQty(in["qty"])
	ref := supplychain.Str(in, "ref_code", "refCode")
	if productID <= 0 || stockID <= 0 || !ok || supplychain.QtyCmp(qty, "0") <= 0 {
		return map[string]any{"ok": false, "error": "product_id, stock_id, qty > 0 обязательны"}, 422
	}
	if ref == "" {
		return map[string]any{"ok": false, "error": "ref_code обязателен"}, 422
	}
	if _, st, errp := e.requireProduct(sess, productID); st != 200 {
		return errp, st
	}
	if _, st, errp := e.requireStock(sess, stockID); st != 200 {
		return errp, st
	}
	onHand := e.qtyOnHand(nil, sess.TenantID, productID, stockID)
	reserved := e.qtyReserved(nil, sess.TenantID, productID, stockID)
	available := supplychain.FormatQty(supplychain.QtyFloat(onHand) - supplychain.QtyFloat(reserved))
	if supplychain.QtyCmp(available, qty) < 0 {
		return map[string]any{
			"ok": false, "error": "Недостаточно свободного остатка", "code": "insufficient_available",
			"qty_on_hand": onHand, "qty_reserved": reserved, "qty_available": available,
		}, 409
	}
	note := supplychain.Str(in, "note")
	var id int64
	err := e.db.QueryRow(`
		INSERT INTO maniforge_inv_reserves (tenant_id, product_id, stock_id, qty, ref_code, note, created_by)
		VALUES ($1,$2,$3,$4::numeric,$5,$6,$7) RETURNING id`,
		sess.TenantID, productID, stockID, qty, ref, nullStr(note), sess.UserID,
	).Scan(&id)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	row, _ := e.getReserve(sess.TenantID, id)
	return map[string]any{"ok": true, "reserve": row}, 201
}

func (e *Engine) releaseReserve(sess *repository.SessionRecord, id int64) (map[string]any, int) {
	row, _ := e.getReserve(sess.TenantID, id)
	if row == nil || fmt.Sprint(row["status"]) != "active" {
		return map[string]any{"ok": false, "error": "Резерв не найден или уже снят"}, 404
	}
	res, err := e.db.Exec(`UPDATE maniforge_inv_reserves SET status='released', released_by=$1, released_at=NOW() WHERE id=$2 AND tenant_id=$3 AND status='active'`,
		sess.UserID, id, sess.TenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return map[string]any{"ok": false, "error": "Не удалось снять резерв"}, 409
	}
	return map[string]any{"ok": true, "released": true, "id": id}, 200
}

func (e *Engine) releaseByRef(tenantID, ref string, userID int64) {
	_, _ = e.db.Exec(`UPDATE maniforge_inv_reserves SET status='released', released_by=$1, released_at=NOW() WHERE tenant_id=$2 AND ref_code=$3 AND status='active'`,
		userID, tenantID, ref)
}

func (e *Engine) getReserve(tenantID string, id int64) (map[string]any, error) {
	return scanReserve(e.db.QueryRow(`SELECT id, tenant_id, product_id, stock_id, qty::text, ref_code, note, status, created_at FROM maniforge_inv_reserves WHERE id=$1 AND tenant_id=$2`, id, tenantID))
}

func (e *Engine) listLots(sess *repository.SessionRecord, productID string) ([]map[string]any, error) {
	q := `SELECT id, tenant_id, product_id, batch_code, lot_code, manufactured_at, expires_at, status, note, created_at
		FROM maniforge_inv_lots WHERE tenant_id=$1`
	args := []any{sess.TenantID}
	if productID != "" {
		q += ` AND product_id=$2`
		args = append(args, supplychain.AsInt64(productID))
	}
	q += ` ORDER BY id DESC`
	rows, err := e.db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		item, err := scanLot(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, nil
}

func (e *Engine) createLot(sess *repository.SessionRecord, in map[string]any) (map[string]any, int) {
	productID := supplychain.Int64(in, "product_id")
	if productID <= 0 {
		return map[string]any{"ok": false, "error": "product_id обязателен"}, 422
	}
	if _, st, errp := e.requireProduct(sess, productID); st != 200 {
		return errp, st
	}
	batch := supplychain.Str(in, "batch_code")
	lotc := supplychain.Str(in, "lot_code")
	existing, _ := e.findLotKey(sess.TenantID, productID, batch, lotc)
	if existing != nil {
		return map[string]any{"ok": true, "lot": existing, "created": false}, 200
	}
	var id int64
	err := e.db.QueryRow(`
		INSERT INTO maniforge_inv_lots (tenant_id, product_id, batch_code, lot_code, manufactured_at, expires_at, note, created_by)
		VALUES ($1,$2,$3,$4,$5,$6,$7,$8) RETURNING id`,
		sess.TenantID, productID, batch, lotc,
		nullDate(supplychain.Str(in, "manufactured_at")), nullDate(supplychain.Str(in, "expires_at")),
		nullStr(supplychain.Str(in, "note")), sess.UserID,
	).Scan(&id)
	if err != nil {
		if isUnique(err) {
			existing, _ = e.findLotKey(sess.TenantID, productID, batch, lotc)
			if existing != nil {
				return map[string]any{"ok": true, "lot": existing}, 200
			}
		}
		return map[string]any{"ok": false, "error": "Ошибка регистрации партии"}, 500
	}
	lot, _ := e.getLot(sess, id)
	return map[string]any{"ok": true, "created": true, "lot": lot}, 201
}

func (e *Engine) getLot(sess *repository.SessionRecord, id int64) (map[string]any, error) {
	item, err := scanLot(e.db.QueryRow(`SELECT id, tenant_id, product_id, batch_code, lot_code, manufactured_at, expires_at, status, note, created_at FROM maniforge_inv_lots WHERE id=$1 AND tenant_id=$2`, id, sess.TenantID))
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return item, err
}

func (e *Engine) findLotKey(tenantID string, productID int64, batch, lotc string) (map[string]any, error) {
	item, err := scanLot(e.db.QueryRow(`SELECT id, tenant_id, product_id, batch_code, lot_code, manufactured_at, expires_at, status, note, created_at FROM maniforge_inv_lots WHERE tenant_id=$1 AND product_id=$2 AND batch_code=$3 AND lot_code=$4`, tenantID, productID, batch, lotc))
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return item, err
}

func (e *Engine) listOrders(sess *repository.SessionRecord) ([]map[string]any, error) {
	rows, err := e.db.Query(`SELECT id FROM maniforge_inv_orders WHERE tenant_id=$1 ORDER BY id DESC LIMIT 100`, sess.TenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		o, _ := e.getOrder(sess, id)
		if o != nil {
			items = append(items, o)
		}
	}
	return items, nil
}

func (e *Engine) createOrder(sess *repository.SessionRecord, in map[string]any) (map[string]any, int) {
	stockID := supplychain.Int64(in, "stock_id")
	if stockID <= 0 {
		return map[string]any{"ok": false, "error": "Складской узел не найден"}, 404
	}
	if _, st, errp := e.requireStock(sess, stockID); st != 200 {
		return errp, st
	}
	num := strings.ToLower(supplychain.Str(in, "order_number", "orderNumber"))
	if num == "" {
		num = "ord-" + time.Now().UTC().Format("20060102") + "-" + randHex(6)
	}
	rawLines := asAnySlice(in["lines"])
	parsed := []map[string]any{}
	for _, r := range rawLines {
		row, _ := r.(map[string]any)
		if row == nil {
			continue
		}
		pid := supplychain.Int64(row, "product_id")
		qty, ok := supplychain.ParseQty(row["qty"])
		if !ok {
			qty, ok = supplychain.ParseQty(row["qty_ordered"])
		}
		if pid <= 0 || !ok || supplychain.QtyCmp(qty, "0") <= 0 {
			continue
		}
		if _, st, _ := e.requireProduct(sess, pid); st != 200 {
			return map[string]any{"ok": false, "error": "Товар в строке не найден"}, 404
		}
		parsed = append(parsed, map[string]any{"product_id": pid, "qty": qty})
	}
	if len(parsed) == 0 {
		return map[string]any{"ok": false, "error": "lines обязателен (product_id, qty)"}, 422
	}
	tx, err := e.db.Begin()
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	defer func() { _ = tx.Rollback() }()
	var id int64
	err = tx.QueryRow(`
		INSERT INTO maniforge_inv_orders (tenant_id, order_number, stock_id, note, metadata_json, created_by)
		VALUES ($1,$2,$3,$4,$5,$6) RETURNING id`,
		sess.TenantID, num, stockID, nullStr(supplychain.Str(in, "note")), nil, sess.UserID,
	).Scan(&id)
	if err != nil {
		if isUnique(err) {
			return map[string]any{"ok": false, "error": "order_number уже существует", "code": "duplicate"}, 409
		}
		return map[string]any{"ok": false, "error": "Ошибка создания заказа"}, 500
	}
	for i, ln := range parsed {
		if _, err := tx.Exec(`INSERT INTO maniforge_inv_order_lines (order_id, line_no, product_id, qty_ordered) VALUES ($1,$2,$3,$4::numeric)`,
			id, i+1, ln["product_id"], ln["qty"]); err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, 500
		}
	}
	if err := tx.Commit(); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	o, _ := e.getOrder(sess, id)
	return map[string]any{"ok": true, "order": o}, 201
}

func (e *Engine) getOrder(sess *repository.SessionRecord, id int64) (map[string]any, error) {
	var oid, stockID int64
	var tenant, num, status string
	var note sql.NullString
	var created time.Time
	err := e.db.QueryRow(`SELECT id, tenant_id, order_number, status, stock_id, note, created_at FROM maniforge_inv_orders WHERE id=$1 AND tenant_id=$2`,
		id, sess.TenantID).Scan(&oid, &tenant, &num, &status, &stockID, &note, &created)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	item := map[string]any{
		"id": oid, "tenant_id": tenant, "order_number": num, "status": status, "stock_id": stockID,
		"created_at": created.UTC().Format(time.RFC3339),
	}
	if note.Valid {
		item["note"] = note.String
	}
	rows, err := e.db.Query(`SELECT line_no, product_id, qty_ordered::text FROM maniforge_inv_order_lines WHERE order_id=$1 ORDER BY line_no`, oid)
	if err != nil {
		return item, err
	}
	defer rows.Close()
	lines := []map[string]any{}
	for rows.Next() {
		var no int
		var pid int64
		var qty string
		if err := rows.Scan(&no, &pid, &qty); err != nil {
			return nil, err
		}
		lines = append(lines, map[string]any{"line_no": no, "product_id": pid, "qty_ordered": qty})
	}
	item["lines"] = lines
	return item, nil
}

func (e *Engine) confirmOrder(sess *repository.SessionRecord, id int64) (map[string]any, int) {
	o, _ := e.getOrder(sess, id)
	if o == nil {
		return map[string]any{"ok": false, "error": "Заказ не найден"}, 404
	}
	if fmt.Sprint(o["status"]) != "draft" {
		return map[string]any{"ok": false, "error": "Только draft можно подтвердить", "code": "invalid_status"}, 422
	}
	ref := orderRef(o)
	stockID := supplychain.AsInt64(o["stock_id"])
	lines, _ := o["lines"].([]map[string]any)
	for _, ln := range lines {
		res, st := e.createReserve(sess, map[string]any{
			"product_id": ln["product_id"], "stock_id": stockID,
			"qty": ln["qty_ordered"], "ref_code": ref, "note": "order #" + fmt.Sprint(o["order_number"]),
		})
		if st >= 300 {
			return res, st
		}
	}
	_, err := e.db.Exec(`UPDATE maniforge_inv_orders SET status='confirmed', confirmed_at=NOW() WHERE id=$1 AND tenant_id=$2`, id, sess.TenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": "Не удалось обновить статус"}, 500
	}
	fresh, _ := e.getOrder(sess, id)
	return map[string]any{"ok": true, "order": fresh}, 200
}

func (e *Engine) fulfillOrder(sess *repository.SessionRecord, id int64) (map[string]any, int) {
	o, _ := e.getOrder(sess, id)
	if o == nil {
		return map[string]any{"ok": false, "error": "Заказ не найден"}, 404
	}
	if fmt.Sprint(o["status"]) != "confirmed" {
		return map[string]any{"ok": false, "error": "Только confirmed можно отгрузить", "code": "invalid_status"}, 422
	}
	stockID := supplychain.AsInt64(o["stock_id"])
	issueLines := []any{}
	lines, _ := o["lines"].([]map[string]any)
	for _, ln := range lines {
		issueLines = append(issueLines, map[string]any{"product_id": ln["product_id"], "stock_id": stockID, "qty": ln["qty_ordered"]})
	}
	e.releaseByRef(sess.TenantID, orderRef(o), sess.UserID)
	posted, st := e.Post(sess, map[string]any{
		"movement_type": "issue",
		"stock_id":      stockID,
		"doc_number":    "fulfill-" + fmt.Sprint(o["order_number"]),
		"lines":         issueLines,
		"metadata":      map[string]any{"order_id": id, "order_number": o["order_number"]},
	}, false)
	if st >= 300 {
		return posted, st
	}
	_, _ = e.db.Exec(`UPDATE maniforge_inv_orders SET status='fulfilled', fulfilled_at=NOW() WHERE id=$1 AND tenant_id=$2`, id, sess.TenantID)
	fresh, _ := e.getOrder(sess, id)
	if m, ok := posted["movement"].(map[string]any); ok && fresh != nil {
		fresh["fulfillment_movement_id"] = m["id"]
	}
	return map[string]any{"ok": true, "order": fresh, "movement": posted["movement"]}, 200
}

func (e *Engine) cancelOrder(sess *repository.SessionRecord, id int64) (map[string]any, int) {
	o, _ := e.getOrder(sess, id)
	if o == nil {
		return map[string]any{"ok": false, "error": "Заказ не найден"}, 404
	}
	status := fmt.Sprint(o["status"])
	if status != "draft" && status != "confirmed" {
		return map[string]any{"ok": false, "error": "Заказ нельзя отменить", "code": "invalid_status"}, 422
	}
	if status == "confirmed" {
		e.releaseByRef(sess.TenantID, orderRef(o), sess.UserID)
	}
	_, err := e.db.Exec(`UPDATE maniforge_inv_orders SET status='cancelled', cancelled_at=NOW() WHERE id=$1 AND tenant_id=$2`, id, sess.TenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": "Не удалось отменить"}, 500
	}
	fresh, _ := e.getOrder(sess, id)
	return map[string]any{"ok": true, "order": fresh}, 200
}

func orderRef(o map[string]any) string {
	return "order:" + fmt.Sprint(o["order_number"])
}

type rowScanner interface{ Scan(dest ...any) error }

func scanReserve(s rowScanner) (map[string]any, error) {
	var id, pid, sid int64
	var tenant, qty, ref, status string
	var note sql.NullString
	var created time.Time
	if err := s.Scan(&id, &tenant, &pid, &sid, &qty, &ref, &note, &status, &created); err != nil {
		return nil, err
	}
	item := map[string]any{
		"id": id, "tenant_id": tenant, "product_id": pid, "stock_id": sid,
		"qty": qty, "ref_code": ref, "status": status,
		"created_at": created.UTC().Format(time.RFC3339),
	}
	if note.Valid {
		item["note"] = note.String
	}
	return item, nil
}

func scanLot(s rowScanner) (map[string]any, error) {
	var id, pid int64
	var tenant, batch, lotc, status string
	var mfg, exp sql.NullTime
	var note sql.NullString
	var created time.Time
	if err := s.Scan(&id, &tenant, &pid, &batch, &lotc, &mfg, &exp, &status, &note, &created); err != nil {
		return nil, err
	}
	item := map[string]any{
		"id": id, "tenant_id": tenant, "product_id": pid, "batch_code": batch, "lot_code": lotc, "status": status,
		"created_at": created.UTC().Format(time.RFC3339),
	}
	if mfg.Valid {
		item["manufactured_at"] = mfg.Time.Format("2006-01-02")
	}
	if exp.Valid {
		item["expires_at"] = exp.Time.Format("2006-01-02")
	}
	if note.Valid {
		item["note"] = note.String
	}
	return item, nil
}

func nullDate(s string) any {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil
	}
	return s
}
