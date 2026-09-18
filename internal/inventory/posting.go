package inventory

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"maniforge/internal/rbac/repository"
	"maniforge/internal/supplychain"
)

var inventoryStockTypes = map[string]bool{
	"warehouse": true, "zone": true, "rack": true, "shelf": true, "cell": true, "location": true,
}

type Engine struct {
	db *sql.DB
}

func NewEngine(db *sql.DB) *Engine { return &Engine{db: db} }

type line struct {
	ProductID     int64
	StockID       int64
	QtyDelta      string
	PackUnitID    sql.NullInt64
	MarkingCodeID sql.NullInt64
	BatchCode     sql.NullString
	LotCode       sql.NullString
	LotID         sql.NullInt64
}

func (e *Engine) Post(sess *repository.SessionRecord, input map[string]any, fromWMS bool) (map[string]any, int) {
	typ := strings.ToLower(supplychain.Str(input, "movement_type", "type"))
	switch typ {
	case "receipt", "issue", "transfer", "adjustment":
	default:
		return map[string]any{"ok": false, "error": "movement_type: receipt|issue|transfer|adjustment"}, 422
	}
	if !fromWMS && (supplychain.Int64(input, "pack_unit_id", "packUnitId") > 0) {
		return map[string]any{"ok": false, "error": "pack_unit_id: используйте WMS POST /api/v1/movements/scan"}, 422
	}
	lines, st, errPayload := e.buildLines(sess, typ, input)
	if st != 200 {
		return errPayload, st
	}
	doc := strings.ToLower(supplychain.Str(input, "doc_number", "docNumber"))
	if doc == "" {
		doc = "mov-" + time.Now().UTC().Format("20060102") + "-" + randHex(6)
	}
	note := supplychain.Str(input, "note")
	var meta any = input["metadata"]
	isDraft := strings.EqualFold(supplychain.Str(input, "status"), "draft") ||
		fmt.Sprint(input["post_immediately"]) == "false"
	scope := supplychain.ScopeFromSession(sess)
	if isDraft {
		id, err := e.insertMovement(sess, scope, doc, typ, note, meta, lines, "draft", false)
		if err != nil {
			if isUnique(err) {
				return map[string]any{"ok": false, "error": "Конфликт doc_number", "code": "duplicate"}, 409
			}
			return map[string]any{"ok": false, "error": "Ошибка сохранения черновика"}, 500
		}
		m, _ := e.findMovement(sess, id)
		return map[string]any{"ok": true, "movement": m}, 201
	}
	tx, err := e.db.Begin()
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	defer func() { _ = tx.Rollback() }()
	for _, ln := range lines {
		if err := e.assertSufficient(tx, sess.TenantID, ln); err != nil {
			if err.Error() == "insufficient_qty" {
				return map[string]any{"ok": false, "error": "Недостаточно остатка", "code": "insufficient_qty"}, 409
			}
			return map[string]any{"ok": false, "error": err.Error()}, 500
		}
		if err := e.applyDelta(tx, sess.TenantID, ln.ProductID, ln.StockID, ln.QtyDelta); err != nil {
			return map[string]any{"ok": false, "error": "Ошибка проведения движения"}, 500
		}
	}
	id, err := e.insertMovementTx(tx, sess, scope, doc, typ, note, meta, lines, "posted", true)
	if err != nil {
		if isUnique(err) {
			return map[string]any{"ok": false, "error": "Конфликт doc_number или данных", "code": "duplicate"}, 409
		}
		return map[string]any{"ok": false, "error": "Ошибка проведения движения"}, 500
	}
	if err := tx.Commit(); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	m, _ := e.findMovement(sess, id)
	return map[string]any{"ok": true, "movement": m}, 201
}

func (e *Engine) Reverse(sess *repository.SessionRecord, movementID int64, input map[string]any) (map[string]any, int) {
	orig, err := e.findMovement(sess, movementID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	if orig == nil {
		return map[string]any{"ok": false, "error": "Движение не найдено"}, 404
	}
	if fmt.Sprint(orig["status"]) != "posted" {
		return map[string]any{"ok": false, "error": "Сторно только для posted"}, 422
	}
	if fmt.Sprint(orig["tenant_id"]) != sess.TenantID {
		return map[string]any{"ok": false, "error": "Сторно только в tenant владельца", "code": "delegated_entity_read_only"}, 403
	}
	if e.hasReversal(movementID) {
		return map[string]any{"ok": false, "error": "Движение уже сторнировано", "code": "already_reversed"}, 409
	}
	meta, _ := orig["metadata"].(map[string]any)
	if meta != nil {
		if _, ok := meta["reversal_of"]; ok {
			return map[string]any{"ok": false, "error": "Нельзя сторнировать сторно", "code": "is_reversal"}, 422
		}
	}
	rawLines, _ := orig["lines"].([]map[string]any)
	revLines := []map[string]any{}
	for _, ln := range rawLines {
		delta := fmt.Sprint(ln["qty_delta"])
		if supplychain.QtyCmp(delta, "0") == 0 {
			continue
		}
		row := map[string]any{
			"product_id": ln["product_id"], "stock_id": ln["stock_id"], "qty_delta": supplychain.QtyNeg(delta),
		}
		revLines = append(revLines, row)
	}
	if len(revLines) == 0 {
		return map[string]any{"ok": false, "error": "Нет строк для сторно"}, 422
	}
	doc := strings.ToLower(supplychain.Str(input, "doc_number"))
	if doc == "" {
		doc = strings.ToLower(fmt.Sprint(orig["doc_number"])) + "-rev-" + randHex(4)
	}
	note := supplychain.Str(input, "note")
	if note == "" {
		note = fmt.Sprintf("Сторно #%d", movementID)
	}
	postIn := map[string]any{
		"movement_type": orig["movement_type"],
		"lines":         revLines,
		"doc_number":    doc,
		"note":          note,
		"metadata":      map[string]any{"reversal_of": movementID},
	}
	out, status := e.Post(sess, postIn, true)
	if status >= 200 && status < 300 {
		if mov, ok := out["movement"].(map[string]any); ok {
			if revID := supplychain.AsInt64(mov["id"]); revID > 0 {
				_ = e.markReversed(movementID, revID)
			}
		}
	}
	return out, status
}

func (e *Engine) PostDraft(sess *repository.SessionRecord, id int64) (map[string]any, int) {
	m, err := e.findMovement(sess, id)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	if m == nil {
		return map[string]any{"ok": false, "error": "Движение не найдено"}, 404
	}
	if fmt.Sprint(m["status"]) != "draft" {
		return map[string]any{"ok": false, "error": "Только draft можно провести", "code": "not_draft"}, 422
	}
	if fmt.Sprint(m["tenant_id"]) != sess.TenantID {
		return map[string]any{"ok": false, "error": "Проведение только в tenant владельца"}, 403
	}
	tx, err := e.db.Begin()
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	defer func() { _ = tx.Rollback() }()
	lines, _ := m["lines"].([]map[string]any)
	for _, ln := range lines {
		row := line{ProductID: supplychain.AsInt64(ln["product_id"]), StockID: supplychain.AsInt64(ln["stock_id"]), QtyDelta: fmt.Sprint(ln["qty_delta"])}
		if err := e.assertSufficient(tx, sess.TenantID, row); err != nil {
			if err.Error() == "insufficient_qty" {
				return map[string]any{"ok": false, "error": "Недостаточно остатка", "code": "insufficient_qty"}, 409
			}
			return map[string]any{"ok": false, "error": err.Error()}, 500
		}
		if err := e.applyDelta(tx, sess.TenantID, row.ProductID, row.StockID, row.QtyDelta); err != nil {
			return map[string]any{"ok": false, "error": "Ошибка проведения черновика"}, 500
		}
	}
	res, err := tx.Exec(`UPDATE maniforge_inv_movements SET status='posted', posted_by=$1, posted_at=NOW() WHERE id=$2 AND tenant_id=$3 AND status='draft'`,
		sess.UserID, id, sess.TenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": "Ошибка проведения черновика"}, 500
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return map[string]any{"ok": false, "error": "Ошибка проведения черновика"}, 500
	}
	if err := tx.Commit(); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	fresh, _ := e.findMovement(sess, id)
	return map[string]any{"ok": true, "movement": fresh}, 200
}

func (e *Engine) CancelDraft(sess *repository.SessionRecord, id int64) (map[string]any, int) {
	m, _ := e.findMovement(sess, id)
	if m == nil {
		return map[string]any{"ok": false, "error": "Движение не найдено"}, 404
	}
	if fmt.Sprint(m["status"]) != "draft" {
		return map[string]any{"ok": false, "error": "Удалять можно только draft"}, 422
	}
	tx, err := e.db.Begin()
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	defer func() { _ = tx.Rollback() }()
	if _, err := tx.Exec(`DELETE FROM maniforge_inv_movement_lines WHERE movement_id=$1`, id); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	res, err := tx.Exec(`DELETE FROM maniforge_inv_movements WHERE id=$1 AND tenant_id=$2 AND status='draft'`, id, sess.TenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return map[string]any{"ok": false, "error": "Движение не найдено"}, 404
	}
	if err := tx.Commit(); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	return map[string]any{"ok": true, "deleted": true, "id": id}, 200
}

func asAnySlice(v any) []any {
	switch t := v.(type) {
	case []any:
		return t
	case []map[string]any:
		out := make([]any, len(t))
		for i := range t {
			out[i] = t[i]
		}
		return out
	default:
		return nil
	}
}

func (e *Engine) buildLines(sess *repository.SessionRecord, typ string, input map[string]any) ([]line, int, map[string]any) {
	if typ == "transfer" {
		if raw := asAnySlice(input["lines"]); len(raw) > 0 {
			return e.linesFromRaw(sess, typ, raw)
		}
		return e.transferFlat(sess, input)
	}
	if typ == "adjustment" {
		if raw := asAnySlice(input["lines"]); len(raw) > 0 {
			return e.linesFromRaw(sess, typ, raw)
		}
		return e.adjustment(sess, input)
	}
	if raw := asAnySlice(input["lines"]); len(raw) > 0 {
		return e.linesFromRaw(sess, typ, raw)
	}
	return e.singleFlat(sess, typ, input)
}

func (e *Engine) linesFromRaw(sess *repository.SessionRecord, typ string, raw []any) ([]line, int, map[string]any) {
	out := []line{}
	for _, r := range raw {
		row, _ := r.(map[string]any)
		if row == nil {
			continue
		}
		ln, st, errp := e.oneLine(sess, typ, row)
		if st != 200 {
			return nil, st, errp
		}
		out = append(out, ln)
	}
	if len(out) == 0 {
		return nil, 422, map[string]any{"ok": false, "error": "lines обязателен"}
	}
	return out, 200, nil
}

func (e *Engine) oneLine(sess *repository.SessionRecord, typ string, row map[string]any) (line, int, map[string]any) {
	productID := supplychain.Int64(row, "product_id")
	stockID := supplychain.Int64(row, "stock_id")
	delta := ""
	if q, ok := supplychain.ParseQty(row["qty_delta"]); ok {
		if supplychain.QtyCmp(q, "0") == 0 {
			return line{}, 422, map[string]any{"ok": false, "error": "qty_delta в строке не может быть 0"}
		}
		delta = q
	} else {
		q, ok := supplychain.ParseQty(row["qty"])
		if !ok {
			q, ok = supplychain.ParseQty(row["quantity"])
		}
		if !ok || supplychain.QtyCmp(q, "0") == 0 {
			return line{}, 422, map[string]any{"ok": false, "error": "qty в строке обязателен"}
		}
		if typ == "issue" && supplychain.QtyCmp(q, "0") > 0 {
			q = supplychain.QtyNeg(q)
		}
		delta = q
	}
	return e.wrapLine(sess, productID, stockID, delta)
}

func (e *Engine) wrapLine(sess *repository.SessionRecord, productID, stockID int64, delta string) (line, int, map[string]any) {
	if _, st, errp := e.requireProduct(sess, productID); st != 200 {
		return line{}, st, errp
	}
	if _, st, errp := e.requireStock(sess, stockID); st != 200 {
		return line{}, st, errp
	}
	return line{ProductID: productID, StockID: stockID, QtyDelta: delta}, 200, nil
}

func (e *Engine) singleFlat(sess *repository.SessionRecord, typ string, input map[string]any) ([]line, int, map[string]any) {
	productID := supplychain.Int64(input, "product_id", "productId")
	stockID := supplychain.Int64(input, "stock_id", "stockId")
	qty, ok := supplychain.ParseQty(input["qty"])
	if !ok {
		qty, ok = supplychain.ParseQty(input["quantity"])
	}
	if productID <= 0 || stockID <= 0 || !ok || supplychain.QtyCmp(qty, "0") <= 0 {
		return nil, 422, map[string]any{"ok": false, "error": "product_id, stock_id, qty > 0 обязательны"}
	}
	delta := qty
	if typ == "issue" {
		delta = supplychain.QtyNeg(qty)
	}
	ln, st, errp := e.wrapLine(sess, productID, stockID, delta)
	if st != 200 {
		return nil, st, errp
	}
	return []line{ln}, 200, nil
}

func (e *Engine) transferFlat(sess *repository.SessionRecord, input map[string]any) ([]line, int, map[string]any) {
	productID := supplychain.Int64(input, "product_id", "productId")
	fromID := supplychain.Int64(input, "from_stock_id", "fromStockId")
	toID := supplychain.Int64(input, "to_stock_id", "toStockId")
	qty, ok := supplychain.ParseQty(input["qty"])
	if !ok {
		qty, ok = supplychain.ParseQty(input["quantity"])
	}
	if productID <= 0 || fromID <= 0 || toID <= 0 || !ok || supplychain.QtyCmp(qty, "0") <= 0 {
		return nil, 422, map[string]any{"ok": false, "error": "transfer: product_id, from_stock_id, to_stock_id, qty > 0"}
	}
	if fromID == toID {
		return nil, 422, map[string]any{"ok": false, "error": "from_stock_id и to_stock_id должны различаться"}
	}
	a, st, errp := e.wrapLine(sess, productID, fromID, supplychain.QtyNeg(qty))
	if st != 200 {
		return nil, st, errp
	}
	b, st, errp := e.wrapLine(sess, productID, toID, qty)
	if st != 200 {
		return nil, st, errp
	}
	return []line{a, b}, 200, nil
}

func (e *Engine) adjustment(sess *repository.SessionRecord, input map[string]any) ([]line, int, map[string]any) {
	productID := supplychain.Int64(input, "product_id")
	stockID := supplychain.Int64(input, "stock_id")
	if productID <= 0 || stockID <= 0 {
		return nil, 422, map[string]any{"ok": false, "error": "adjustment: product_id и stock_id обязательны"}
	}
	if _, st, errp := e.wrapLine(sess, productID, stockID, "0"); st != 200 && st != 422 {
		return nil, st, errp
	}
	if _, st, errp := e.requireProduct(sess, productID); st != 200 {
		return nil, st, errp
	}
	if _, st, errp := e.requireStock(sess, stockID); st != 200 {
		return nil, st, errp
	}
	current := e.qtyOnHand(nil, sess.TenantID, productID, stockID)
	var delta string
	if _, has := input["qty_after"]; has || input["qtyAfter"] != nil {
		target, ok := supplychain.ParseQty(input["qty_after"])
		if !ok {
			target, ok = supplychain.ParseQty(input["qtyAfter"])
		}
		if !ok {
			return nil, 422, map[string]any{"ok": false, "error": "qty_after обязателен"}
		}
		delta = supplychain.FormatQty(supplychain.QtyFloat(target) - supplychain.QtyFloat(current))
	} else {
		q, ok := supplychain.ParseQty(input["qty_delta"])
		if !ok {
			q, ok = supplychain.ParseQty(input["qty"])
		}
		if !ok || supplychain.QtyCmp(q, "0") == 0 {
			return nil, 422, map[string]any{"ok": false, "error": "qty_delta или qty_after обязателен"}
		}
		delta = q
	}
	return []line{{ProductID: productID, StockID: stockID, QtyDelta: delta}}, 200, nil
}

func (e *Engine) requireProduct(sess *repository.SessionRecord, id int64) (map[string]any, int, map[string]any) {
	var tenant, status string
	err := e.db.QueryRow(`SELECT tenant_id, status FROM maniforge_products WHERE id=$1`, id).Scan(&tenant, &status)
	if err == sql.ErrNoRows || status != "active" {
		return nil, 404, map[string]any{"ok": false, "error": "Товар не найден или не active", "code": "product_not_found"}
	}
	if err != nil {
		return nil, 500, map[string]any{"ok": false, "error": err.Error()}
	}
	if tenant != sess.TenantID {
		return nil, 403, map[string]any{"ok": false, "error": "Движение только для сущностей своего tenant", "code": "delegated_entity_read_only"}
	}
	return map[string]any{"id": id}, 200, nil
}

func (e *Engine) requireStock(sess *repository.SessionRecord, id int64) (map[string]any, int, map[string]any) {
	var tenant, status, typ string
	err := e.db.QueryRow(`SELECT tenant_id, status, type FROM maniforge_wh_stocks WHERE id=$1`, id).Scan(&tenant, &status, &typ)
	if err == sql.ErrNoRows || status != "active" {
		return nil, 404, map[string]any{"ok": false, "error": "Складской узел не найден", "code": "stock_not_found"}
	}
	if err != nil {
		return nil, 500, map[string]any{"ok": false, "error": err.Error()}
	}
	if !inventoryStockTypes[typ] {
		return nil, 422, map[string]any{"ok": false, "error": "Тип узла не для учёта остатков", "code": "invalid_stock_type"}
	}
	if tenant != sess.TenantID {
		return nil, 403, map[string]any{"ok": false, "error": "Движение только для сущностей своего tenant", "code": "delegated_entity_read_only"}
	}
	return map[string]any{"id": id, "type": typ, "tenant_id": tenant}, 200, nil
}

func (e *Engine) qtyOnHand(tx *sql.Tx, tenantID string, productID, stockID int64) string {
	var q string
	row := queryRow(tx, e.db, `SELECT COALESCE(qty::text,'0') FROM maniforge_inv_balances WHERE tenant_id=$1 AND product_id=$2 AND stock_id=$3`,
		tenantID, productID, stockID)
	if err := row.Scan(&q); err != nil {
		return "0"
	}
	return q
}

func (e *Engine) qtyReserved(tx *sql.Tx, tenantID string, productID, stockID int64) string {
	var q string
	row := queryRow(tx, e.db, `SELECT COALESCE(SUM(qty),0)::text FROM maniforge_inv_reserves WHERE tenant_id=$1 AND product_id=$2 AND stock_id=$3 AND status='active'`,
		tenantID, productID, stockID)
	if err := row.Scan(&q); err != nil {
		return "0"
	}
	return q
}

func (e *Engine) assertSufficient(tx *sql.Tx, tenantID string, ln line) error {
	if supplychain.QtyCmp(ln.QtyDelta, "0") >= 0 {
		return nil
	}
	onHand := e.qtyOnHand(tx, tenantID, ln.ProductID, ln.StockID)
	after := supplychain.QtyAdd(onHand, ln.QtyDelta)
	if supplychain.QtyCmp(after, "0") < 0 {
		return fmt.Errorf("insufficient_qty")
	}
	reserved := e.qtyReserved(tx, tenantID, ln.ProductID, ln.StockID)
	available := supplychain.FormatQty(supplychain.QtyFloat(onHand) - supplychain.QtyFloat(reserved))
	if supplychain.QtyCmp(supplychain.QtyAdd(available, ln.QtyDelta), "0") < 0 {
		return fmt.Errorf("insufficient_qty")
	}
	return nil
}

func (e *Engine) applyDelta(tx *sql.Tx, tenantID string, productID, stockID int64, delta string) error {
	_, err := exec(tx, e.db, `
		INSERT INTO maniforge_inv_balances (tenant_id, product_id, stock_id, qty, updated_at)
		VALUES ($1,$2,$3,$4::numeric, NOW())
		ON CONFLICT (tenant_id, product_id, stock_id)
		DO UPDATE SET qty = maniforge_inv_balances.qty + EXCLUDED.qty, updated_at = NOW()`,
		tenantID, productID, stockID, delta)
	return err
}

func (e *Engine) insertMovement(sess *repository.SessionRecord, scope supplychain.Scope, doc, typ, note string, meta any, lines []line, status string, posted bool) (int64, error) {
	tx, err := e.db.Begin()
	if err != nil {
		return 0, err
	}
	defer func() { _ = tx.Rollback() }()
	id, err := e.insertMovementTx(tx, sess, scope, doc, typ, note, meta, lines, status, posted)
	if err != nil {
		return 0, err
	}
	return id, tx.Commit()
}

func (e *Engine) insertMovementTx(tx *sql.Tx, sess *repository.SessionRecord, scope supplychain.Scope, doc, typ, note string, meta any, lines []line, status string, posted bool) (int64, error) {
	var metaJSON any
	if meta != nil {
		b, err := json.Marshal(meta)
		if err != nil {
			return 0, err
		}
		metaJSON = b
	}
	var postedBy any
	if posted {
		postedBy = sess.UserID
	}
	var id int64
	err := tx.QueryRow(`
		INSERT INTO maniforge_inv_movements (
			tenant_id, subtenant_id, project_id, scope_visibility, doc_number, movement_type, status, note, metadata_json, created_by, posted_by, posted_at
		) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9::jsonb,$10,$11, CASE WHEN $12 THEN NOW() ELSE NULL END)
		RETURNING id`,
		scope.TenantID, scope.SubtenantID, nullInt(scope.ProjectID), scope.Visibility,
		doc, typ, status, nullStr(note), metaJSON, sess.UserID, postedBy, posted,
	).Scan(&id)
	if err != nil {
		return 0, err
	}
	for i, ln := range lines {
		if _, err := tx.Exec(`
			INSERT INTO maniforge_inv_movement_lines (movement_id, line_no, product_id, stock_id, qty_delta, pack_unit_id, marking_code_id, batch_code, lot_code, lot_id)
			VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)`,
			id, i+1, ln.ProductID, ln.StockID, ln.QtyDelta,
			nullInt(ln.PackUnitID), nullInt(ln.MarkingCodeID), nullNS(ln.BatchCode), nullNS(ln.LotCode), nullInt(ln.LotID),
		); err != nil {
			return 0, err
		}
	}
	return id, nil
}

func (e *Engine) findMovement(sess *repository.SessionRecord, id int64) (map[string]any, error) {
	args := append(supplychain.VisibleArgs(sess), id)
	var mid int64
	var tenant, sub, vis, doc, typ, status string
	var project sql.NullInt64
	var note sql.NullString
	var meta []byte
	var created time.Time
	err := e.db.QueryRow(`
		SELECT id, tenant_id, subtenant_id, project_id, scope_visibility, doc_number, movement_type, status, note, metadata_json, created_at
		FROM maniforge_inv_movements WHERE `+supplychain.VisibleSQL("")+` AND id=$4`, args...).
		Scan(&mid, &tenant, &sub, &project, &vis, &doc, &typ, &status, &note, &meta, &created)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	item := map[string]any{
		"id": mid, "tenant_id": tenant, "subtenant_id": sub, "scope_visibility": vis,
		"doc_number": doc, "movement_type": typ, "status": status,
		"project_id": supplychain.NullInt(project),
		"created_at": created.UTC().Format(time.RFC3339),
	}
	if note.Valid {
		item["note"] = note.String
	}
	if len(meta) > 0 {
		var m any
		_ = json.Unmarshal(meta, &m)
		item["metadata"] = m
	}
	rows, err := e.db.Query(`SELECT line_no, product_id, stock_id, qty_delta::text, pack_unit_id, marking_code_id, batch_code, lot_code, lot_id
		FROM maniforge_inv_movement_lines WHERE movement_id=$1 ORDER BY line_no`, mid)
	if err != nil {
		return item, err
	}
	defer rows.Close()
	lines := []map[string]any{}
	for rows.Next() {
		var no int
		var pid, sid int64
		var qty string
		var pack, mark, lot sql.NullInt64
		var batch, lotc sql.NullString
		if err := rows.Scan(&no, &pid, &sid, &qty, &pack, &mark, &batch, &lotc, &lot); err != nil {
			return nil, err
		}
		ln := map[string]any{"line_no": no, "product_id": pid, "stock_id": sid, "qty_delta": qty}
		if pack.Valid {
			ln["pack_unit_id"] = pack.Int64
		}
		if mark.Valid {
			ln["marking_code_id"] = mark.Int64
		}
		if batch.Valid {
			ln["batch_code"] = batch.String
		}
		if lotc.Valid {
			ln["lot_code"] = lotc.String
		}
		if lot.Valid {
			ln["lot_id"] = lot.Int64
		}
		lines = append(lines, ln)
	}
	item["lines"] = lines
	return item, nil
}

func (e *Engine) listMovements(sess *repository.SessionRecord) ([]map[string]any, error) {
	rows, err := e.db.Query(`
		SELECT id FROM maniforge_inv_movements WHERE `+supplychain.VisibleSQL("")+` ORDER BY id DESC LIMIT 100`,
		supplychain.VisibleArgs(sess)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	ids := []int64{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		ids = append(ids, id)
	}
	items := []map[string]any{}
	for _, id := range ids {
		m, err := e.findMovement(sess, id)
		if err != nil {
			return nil, err
		}
		if m != nil {
			items = append(items, m)
		}
	}
	return items, nil
}

func (e *Engine) markReversed(movementID, reversalID int64) error {
	_, err := e.db.Exec(`
		UPDATE maniforge_inv_movements
		SET metadata_json = COALESCE(
			CASE
				WHEN metadata_json IS NULL THEN '{}'::jsonb
				WHEN jsonb_typeof(metadata_json) = 'object' THEN metadata_json
				WHEN jsonb_typeof(metadata_json) = 'string' THEN COALESCE((NULLIF(metadata_json #>> '{}', ''))::jsonb, '{}'::jsonb)
				ELSE '{}'::jsonb
			END,
			'{}'::jsonb
		) || jsonb_build_object('reversed_by_movement_id', $1)
		WHERE id = $2`, reversalID, movementID)
	return err
}

func reversalOfExpr() string {
	return `COALESCE(
		metadata_json->>'reversal_of',
		CASE WHEN jsonb_typeof(metadata_json) = 'string'
			THEN (NULLIF(metadata_json #>> '{}', ''))::jsonb->>'reversal_of' END
	)`
}

func (e *Engine) hasReversal(id int64) bool {
	var n int
	q := `
		SELECT COUNT(*) FROM maniforge_inv_movements
		WHERE (id = $1 AND COALESCE(metadata_json->>'reversed_by_movement_id',
			CASE WHEN jsonb_typeof(metadata_json) = 'string'
				THEN (NULLIF(metadata_json #>> '{}', ''))::jsonb->>'reversed_by_movement_id' END, '') <> '')
		   OR (` + reversalOfExpr() + `) = $1::text`
	_ = e.db.QueryRow(q, id).Scan(&n)
	return n > 0
}

func (e *Engine) listBalances(sess *repository.SessionRecord, productID, stockID string) ([]map[string]any, error) {
	q := `
		SELECT b.id, b.tenant_id, b.product_id, b.stock_id, b.qty::text, b.updated_at,
		       p.code, p.name, p.unit, s.code, s.name, s.type,
		       COALESCE((SELECT SUM(r.qty) FROM maniforge_inv_reserves r WHERE r.tenant_id=b.tenant_id AND r.product_id=b.product_id AND r.stock_id=b.stock_id AND r.status='active'),0)::text
		FROM maniforge_inv_balances b
		JOIN maniforge_products p ON p.id=b.product_id
		JOIN maniforge_wh_stocks s ON s.id=b.stock_id
		WHERE b.tenant_id=$1`
	args := []any{sess.TenantID}
	n := 2
	if productID != "" {
		q += fmt.Sprintf(` AND b.product_id=$%d`, n)
		args = append(args, supplychain.AsInt64(productID))
		n++
	}
	if stockID != "" {
		q += fmt.Sprintf(` AND b.stock_id=$%d`, n)
		args = append(args, supplychain.AsInt64(stockID))
	}
	q += ` ORDER BY b.id`
	rows, err := e.db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var id, pid, sid int64
		var tenant, qty, pcode, pname, unit, scode, sname, stype, reserved string
		var updated time.Time
		if err := rows.Scan(&id, &tenant, &pid, &sid, &qty, &updated, &pcode, &pname, &unit, &scode, &sname, &stype, &reserved); err != nil {
			return nil, err
		}
		avail := supplychain.FormatQty(supplychain.QtyFloat(qty) - supplychain.QtyFloat(reserved))
		items = append(items, map[string]any{
			"id": id, "tenant_id": tenant, "product_id": pid, "stock_id": sid,
			"qty": qty, "qty_reserved": reserved, "qty_available": avail,
			"product_code": pcode, "product_name": pname, "product_unit": unit,
			"stock_code": scode, "stock_name": sname, "stock_type": stype,
			"updated_at": updated.UTC().Format(time.RFC3339),
		})
	}
	return items, nil
}

func (e *Engine) balancesSummary(sess *repository.SessionRecord) ([]map[string]any, error) {
	rows, err := e.db.Query(`
		SELECT b.product_id, p.code, p.name, SUM(b.qty)::text
		FROM maniforge_inv_balances b
		JOIN maniforge_products p ON p.id=b.product_id
		WHERE b.tenant_id=$1
		GROUP BY b.product_id, p.code, p.name
		ORDER BY p.code`, sess.TenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var pid int64
		var code, name, qty string
		if err := rows.Scan(&pid, &code, &name, &qty); err != nil {
			return nil, err
		}
		items = append(items, map[string]any{"product_id": pid, "product_code": code, "product_name": name, "qty": qty})
	}
	return items, nil
}

func (e *Engine) overview(sess *repository.SessionRecord) (map[string]any, error) {
	var bal, mov, res int
	_ = e.db.QueryRow(`SELECT COUNT(*) FROM maniforge_inv_balances WHERE tenant_id=$1`, sess.TenantID).Scan(&bal)
	_ = e.db.QueryRow(`SELECT COUNT(*) FROM maniforge_inv_movements WHERE tenant_id=$1`, sess.TenantID).Scan(&mov)
	_ = e.db.QueryRow(`SELECT COUNT(*) FROM maniforge_inv_reserves WHERE tenant_id=$1 AND status='active'`, sess.TenantID).Scan(&res)
	return map[string]any{"ok": true, "balances": bal, "movements": mov, "active_reserves": res}, nil
}

func randHex(n int) string {
	b := make([]byte, (n+1)/2)
	_, _ = rand.Read(b)
	s := hex.EncodeToString(b)
	if len(s) > n {
		return s[:n]
	}
	return s
}

func isUnique(err error) bool {
	if err == nil {
		return false
	}
	s := strings.ToLower(err.Error())
	return strings.Contains(s, "unique") || strings.Contains(s, "duplicate")
}

func nullInt(n sql.NullInt64) any {
	if n.Valid {
		return n.Int64
	}
	return nil
}
func nullStr(s string) any {
	if s == "" {
		return nil
	}
	return s
}
func nullNS(s sql.NullString) any {
	if s.Valid {
		return s.String
	}
	return nil
}

func queryRow(tx *sql.Tx, db *sql.DB, q string, args ...any) *sql.Row {
	if tx != nil {
		return tx.QueryRow(q, args...)
	}
	return db.QueryRow(q, args...)
}
func exec(tx *sql.Tx, db *sql.DB, q string, args ...any) (sql.Result, error) {
	if tx != nil {
		return tx.Exec(q, args...)
	}
	return db.Exec(q, args...)
}
