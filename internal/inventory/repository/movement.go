// Файл: movement.go
// Назначение: движения maniforge_inv_movements + строки.
package repository

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"time"

	rbacrepo "maniforge/internal/rbac/repository"
	whrepo "maniforge/internal/warehouses/repository"
)

type MovementLine struct {
	ProductID int64
	StockID   int64
	QtyDelta  string
}

type MovementRepository struct {
	db *sql.DB
}

func NewMovementRepository(db *sql.DB) *MovementRepository {
	return &MovementRepository{db: db}
}

type ScopeRow struct {
	TenantID             string
	SubtenantID          string
	ProjectID            sql.NullInt64
	ScopeVisibility      string
	SharedGrantTenantIDs json.RawMessage
}

func (r *MovementRepository) Insert(scope ScopeRow, docNumber, movementType, status string,
	note *string, metadata json.RawMessage, createdBy int64, postNow bool, lines []MovementLine) (int64, error) {
	tx, err := r.db.Begin()
	if err != nil {
		return 0, err
	}
	defer func() { _ = tx.Rollback() }()

	var movementID int64
	var postedAt any
	if postNow {
		postedAt = time.Now().UTC()
	}
	var postedBy any
	if postNow {
		postedBy = createdBy
	}
	err = tx.QueryRow(`
		INSERT INTO maniforge_inv_movements (
			tenant_id, subtenant_id, project_id, scope_visibility, shared_grant_tenant_ids_json,
			doc_number, movement_type, status, note, metadata_json, created_by, posted_by, posted_at
		) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13) RETURNING id`,
		scope.TenantID, scope.SubtenantID, nullInt64(scope.ProjectID), scope.ScopeVisibility,
		nullJSONBytes(scope.SharedGrantTenantIDs), docNumber, movementType, status,
		note, nullJSONBytes(metadata), createdBy, postedBy, postedAt,
	).Scan(&movementID)
	if err != nil {
		return 0, err
	}
	if err := insertLines(tx, movementID, lines); err != nil {
		return 0, err
	}
	return movementID, tx.Commit()
}

func insertLines(tx *sql.Tx, movementID int64, lines []MovementLine) error {
	for i, line := range lines {
		_, err := tx.Exec(`
			INSERT INTO maniforge_inv_movement_lines (movement_id, line_no, product_id, stock_id, qty_delta)
			VALUES ($1,$2,$3,$4,$5::numeric)`,
			movementID, i+1, line.ProductID, line.StockID, line.QtyDelta)
		if err != nil {
			return err
		}
	}
	return nil
}

func (r *MovementRepository) FindVisibleByID(session *rbacrepo.SessionRecord, id int64) (map[string]any, error) {
	projectID, _ := whrepo.SessionProjectID(r.db, session.ProjectID, session.TenantID, session.SubtenantID)
	row := r.db.QueryRow(`
		SELECT id, tenant_id, subtenant_id, project_id, scope_visibility,
			doc_number, movement_type, status, note, metadata_json,
			created_by, posted_by, posted_at, created_at
		FROM maniforge_inv_movements m
		WHERE m.id = $1 AND m.tenant_id = $2 AND m.subtenant_id = $3
		  AND (m.project_id IS NULL OR m.project_id = $4)`, id, session.TenantID, session.SubtenantID, projectID)
	m, err := scanMovementHeader(row)
	if err != nil || m == nil {
		return nil, err
	}
	lines, err := r.LinesForMovement(id)
	if err != nil {
		return nil, err
	}
	m["lines"] = lines
	return m, nil
}

func (r *MovementRepository) ListVisible(session *rbacrepo.SessionRecord, limit int) ([]map[string]any, error) {
	if limit <= 0 {
		limit = 50
	}
	if limit > 200 {
		limit = 200
	}
	projectID, _ := whrepo.SessionProjectID(r.db, session.ProjectID, session.TenantID, session.SubtenantID)
	rows, err := r.db.Query(fmt.Sprintf(`
		SELECT id, tenant_id, subtenant_id, project_id, scope_visibility,
			doc_number, movement_type, status, note, metadata_json,
			created_by, posted_by, posted_at, created_at
		FROM maniforge_inv_movements m
		WHERE m.tenant_id = $1 AND m.subtenant_id = $2
		  AND (m.project_id IS NULL OR m.project_id = $3)
		ORDER BY COALESCE(m.posted_at, m.created_at) DESC, m.id DESC
		LIMIT %d`, limit), session.TenantID, session.SubtenantID, projectID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []map[string]any
	for rows.Next() {
		m, err := scanMovementHeaderFromRows(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, m)
	}
	return out, rows.Err()
}

func (r *MovementRepository) LinesForMovement(movementID int64) ([]map[string]any, error) {
	rows, err := r.db.Query(`
		SELECT l.id, l.line_no, l.product_id, l.stock_id, l.qty_delta::text,
			p.code, p.name, s.code, s.name
		FROM maniforge_inv_movement_lines l
		INNER JOIN maniforge_products p ON p.id = l.product_id
		INNER JOIN maniforge_wh_stocks s ON s.id = l.stock_id
		WHERE l.movement_id = $1 ORDER BY l.line_no`, movementID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []map[string]any
	for rows.Next() {
		var id, lineNo, productID, stockID int64
		var qtyDelta, pCode, pName, sCode, sName string
		if err := rows.Scan(&id, &lineNo, &productID, &stockID, &qtyDelta, &pCode, &pName, &sCode, &sName); err != nil {
			return nil, err
		}
		out = append(out, map[string]any{
			"id": id, "line_no": lineNo, "product_id": productID, "stock_id": stockID,
			"qty_delta": qtyDelta, "product_code": pCode, "product_name": pName,
			"stock_code": sCode, "stock_name": sName,
		})
	}
	return out, rows.Err()
}

func (r *MovementRepository) MarkPosted(movementID int64, tenantID string, postedBy int64) (bool, error) {
	res, err := r.db.Exec(`
		UPDATE maniforge_inv_movements SET status = 'posted', posted_by = $3, posted_at = NOW(), updated_at = NOW()
		WHERE id = $1 AND tenant_id = $2 AND status = 'draft'`, movementID, tenantID, postedBy)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func scanMovementHeader(row *sql.Row) (map[string]any, error) {
	var id int64
	var tenantID, subtenantID, scopeVis, docNumber, movType, status string
	var projectID sql.NullInt64
	var note sql.NullString
	var meta sql.NullString
	var createdBy, postedBy sql.NullInt64
	var postedAt sql.NullTime
	var createdAt time.Time
	err := row.Scan(&id, &tenantID, &subtenantID, &projectID, &scopeVis,
		&docNumber, &movType, &status, &note, &meta, &createdBy, &postedBy, &postedAt, &createdAt)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return movementMap(id, tenantID, subtenantID, projectID, scopeVis, docNumber, movType, status,
		note, meta, createdBy, postedBy, postedAt, createdAt), nil
}

func scanMovementHeaderFromRows(rows *sql.Rows) (map[string]any, error) {
	var id int64
	var tenantID, subtenantID, scopeVis, docNumber, movType, status string
	var projectID sql.NullInt64
	var note sql.NullString
	var meta sql.NullString
	var createdBy, postedBy sql.NullInt64
	var postedAt sql.NullTime
	var createdAt time.Time
	err := rows.Scan(&id, &tenantID, &subtenantID, &projectID, &scopeVis,
		&docNumber, &movType, &status, &note, &meta, &createdBy, &postedBy, &postedAt, &createdAt)
	if err != nil {
		return nil, err
	}
	return movementMap(id, tenantID, subtenantID, projectID, scopeVis, docNumber, movType, status,
		note, meta, createdBy, postedBy, postedAt, createdAt), nil
}

func movementMap(id int64, tenantID, subtenantID string, projectID sql.NullInt64, scopeVis,
	docNumber, movType, status string, note sql.NullString, meta sql.NullString,
	createdBy, postedBy sql.NullInt64, postedAt sql.NullTime, createdAt time.Time) map[string]any {
	m := map[string]any{
		"id": id, "tenant_id": tenantID, "subtenant_id": subtenantID,
		"scope_visibility": scopeVis, "doc_number": docNumber,
		"movement_type": movType, "status": status,
		"created_at": createdAt.UTC().Format("2006-01-02 15:04:05"),
	}
	if projectID.Valid {
		m["project_id"] = projectID.Int64
	}
	if note.Valid {
		m["note"] = note.String
	}
	if meta.Valid && meta.String != "" {
		var data any
		if json.Unmarshal([]byte(meta.String), &data) == nil {
			m["metadata"] = data
		}
	}
	if createdBy.Valid {
		m["created_by"] = createdBy.Int64
	}
	if postedBy.Valid {
		m["posted_by"] = postedBy.Int64
	}
	if postedAt.Valid {
		m["posted_at"] = postedAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	return m
}

func nullInt64(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}

func nullJSONBytes(b json.RawMessage) any {
	if len(b) == 0 {
		return nil
	}
	return string(b)
}
