// Файл: stock.go
// Назначение: maniforge_wh_stocks — CRUD, visibility, delegation read.
// См. также: app/Maniforge/Warehouses/Repository/StockRepository.php
package repository

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

type StockRow struct {
	ID                     int64
	TenantID               string
	SubtenantID            string
	ProjectID              sql.NullInt64
	ScopeVisibility        string
	SharedSubtenantIDs     json.RawMessage
	SharedGrantTenantIDs   json.RawMessage
	Code                   string
	Name                   string
	Type                   string
	ParentID               sql.NullInt64
	ParentCode             sql.NullString
	ParentName             sql.NullString
	DataJSON               json.RawMessage
	Active                 bool
	Status                 string
	CreatedBy              sql.NullInt64
	UpdatedBy              sql.NullInt64
	CreatedAt              time.Time
	UpdatedAt              sql.NullTime
}

type StockFilters struct {
	Type      string
	Search    string
	RootsOnly bool
	Status    string
	ParentID  *int64
}

type StockRepository struct {
	db *sql.DB
}

func NewStockRepository(db *sql.DB) *StockRepository {
	return &StockRepository{db: db}
}

func (r *StockRepository) ListVisible(tenantID, subtenantID string, projectID int64, filters StockFilters) ([]StockRow, error) {
	status := filters.Status
	if status == "" {
		status = "active"
	}
	query := stockSelectSQL() + `
	 WHERE (` + stockLocalVisibilitySQL(1) + ` OR ` + stockDelegatedReadSQL(4) + `)`
	args := []any{tenantID, subtenantID, projectID, tenantID, tenantID, tenantID, tenantID, subtenantID, projectID, tenantID}

	if status != "all" {
		query += ` AND s.status = $` + fmt.Sprint(len(args)+1)
		args = append(args, status)
	}
	if filters.Type != "" {
		query += ` AND s.type = $` + fmt.Sprint(len(args)+1)
		args = append(args, filters.Type)
	}
	if filters.ParentID != nil {
		if *filters.ParentID == 0 {
			query += ` AND s.parent_id IS NULL`
		} else {
			query += ` AND s.parent_id = $` + fmt.Sprint(len(args)+1)
			args = append(args, *filters.ParentID)
		}
	}
	if filters.RootsOnly {
		query += ` AND s.parent_id IS NULL`
	}
	if filters.Search != "" {
		query += ` AND (s.name ILIKE $` + fmt.Sprint(len(args)+1) + ` OR s.code ILIKE $` + fmt.Sprint(len(args)+1) + `)`
		args = append(args, "%"+filters.Search+"%")
	}
	query += ` ORDER BY s.parent_id IS NULL DESC, s.name ASC`

	rows, err := r.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanStockRows(rows)
}

func (r *StockRepository) FindVisibleByID(tenantID, subtenantID string, projectID, id int64) (*StockRow, error) {
	query := stockSelectSQL() + `
	 WHERE s.id = $1 AND (` + stockLocalVisibilitySQL(2) + ` OR ` + stockDelegatedReadSQL(5) + `) LIMIT 1`
	args := []any{id, tenantID, subtenantID, projectID, tenantID, tenantID, tenantID, tenantID, subtenantID, projectID, tenantID}
	row := r.db.QueryRow(query, args...)
	return scanStockRow(row)
}

func (r *StockRepository) FindByIDInTenant(id int64, tenantID string) (*StockRow, error) {
	row := r.db.QueryRow(stockSelectSQL()+` WHERE s.id = $1 AND s.tenant_id = $2 LIMIT 1`, id, tenantID)
	return scanStockRow(row)
}

func (r *StockRepository) FindByCodeInScope(tenantID, subtenantID string, projectID sql.NullInt64, code string) (*StockRow, error) {
	row := r.db.QueryRow(
		stockSelectSQL()+` WHERE s.tenant_id = $1 AND s.subtenant_id = $2 AND s.project_id IS NOT DISTINCT FROM $3 AND s.code = $4 LIMIT 1`,
		tenantID, subtenantID, projectID, code)
	return scanStockRow(row)
}

type CreateStockInput struct {
	TenantID             string
	SubtenantID          string
	ProjectID            sql.NullInt64
	ScopeVisibility      string
	SharedSubtenantIDs   json.RawMessage
	SharedGrantTenantIDs json.RawMessage
	Code                 string
	Name                 string
	Type                 string
	ParentID             sql.NullInt64
	DataJSON             json.RawMessage
	CreatedBy            int64
}

func (r *StockRepository) Create(in CreateStockInput) (*StockRow, error) {
	row := r.db.QueryRow(
		`INSERT INTO maniforge_wh_stocks (
			tenant_id, subtenant_id, project_id, scope_visibility,
			shared_subtenant_ids_json, shared_grant_tenant_ids_json,
			code, name, type, parent_id, data_json, created_by, updated_by
		) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$12)
		RETURNING id`,
		in.TenantID, in.SubtenantID, in.ProjectID, in.ScopeVisibility,
		nullJSON(in.SharedSubtenantIDs), nullJSON(in.SharedGrantTenantIDs),
		in.Code, in.Name, in.Type, in.ParentID, nullJSON(in.DataJSON), in.CreatedBy,
	)
	var id int64
	if err := row.Scan(&id); err != nil {
		return nil, err
	}
	return r.FindByIDInTenant(id, in.TenantID)
}

func (r *StockRepository) Update(id int64, tenantID string, fields map[string]any, updatedBy int64) error {
	if len(fields) == 0 {
		return nil
	}
	sets := []string{"updated_by = $1", "updated_at = NOW()"}
	args := []any{updatedBy}
	n := 2
	for k, v := range fields {
		switch k {
		case "name", "type", "status":
			sets = append(sets, fmt.Sprintf("%s = $%d", k, n))
			args = append(args, v)
			n++
		case "active":
			sets = append(sets, fmt.Sprintf("active = $%d", n))
			args = append(args, v)
			n++
		case "parent_id":
			sets = append(sets, fmt.Sprintf("parent_id = $%d", n))
			args = append(args, v)
			n++
		case "data_json":
			sets = append(sets, fmt.Sprintf("data_json = $%d", n))
			args = append(args, v)
			n++
		case "shared_grant_tenant_ids_json":
			sets = append(sets, fmt.Sprintf("shared_grant_tenant_ids_json = $%d", n))
			args = append(args, v)
			n++
		}
	}
	args = append(args, id, tenantID)
	query := fmt.Sprintf(`UPDATE maniforge_wh_stocks SET %s WHERE id = $%d AND tenant_id = $%d`,
		strings.Join(sets, ", "), n, n+1)
	_, err := r.db.Exec(query, args...)
	return err
}

func (r *StockRepository) CountChildren(id int64, tenantID string, activeOnly bool) (int, error) {
	query := `SELECT COUNT(*) FROM maniforge_wh_stocks WHERE parent_id = $1 AND tenant_id = $2`
	if activeOnly {
		query += ` AND status = 'active'`
	}
	var n int
	err := r.db.QueryRow(query, id, tenantID).Scan(&n)
	return n, err
}

func (r *StockRepository) ListDescendantIDs(tenantID, subtenantID string, projectID, rootID int64) ([]int64, error) {
	rows, err := r.ListVisible(tenantID, subtenantID, projectID, StockFilters{Status: "all"})
	if err != nil {
		return nil, err
	}
	byParent := map[int64][]int64{}
	for _, row := range rows {
		if !row.ParentID.Valid {
			continue
		}
		byParent[row.ParentID.Int64] = append(byParent[row.ParentID.Int64], row.ID)
	}
	var out []int64
	var walk func(int64)
	walk = func(pid int64) {
		for _, cid := range byParent[pid] {
			out = append(out, cid)
			walk(cid)
		}
	}
	walk(rootID)
	return out, nil
}

func stockSelectSQL() string {
	return `SELECT s.id, s.tenant_id, s.subtenant_id, s.project_id, s.scope_visibility,
		s.shared_subtenant_ids_json, s.shared_grant_tenant_ids_json,
		s.code, s.name, s.type, s.parent_id, s.data_json, s.active, s.status,
		s.created_by, s.updated_by, s.created_at, s.updated_at,
		p.code, p.name
	 FROM maniforge_wh_stocks s
	 LEFT JOIN maniforge_wh_stocks p ON p.id = s.parent_id`
}

func stockLocalVisibilitySQL(start int) string {
	return fmt.Sprintf(`(
		s.tenant_id = $%d
		AND (
			(s.scope_visibility = 'project' AND s.project_id = $%d)
			OR (s.scope_visibility = 'subtenant' AND s.subtenant_id = $%d)
			OR (s.scope_visibility = 'tenant')
		)
	)`, start, start+2, start+1)
}

func stockDelegatedReadSQL(start int) string {
	return fmt.Sprintf(`(
		s.tenant_id <> $%d
		AND s.shared_grant_tenant_ids_json IS NOT NULL
		AND s.shared_grant_tenant_ids_json @> to_jsonb(ARRAY[$%d::text])
		AND EXISTS (
			SELECT 1 FROM maniforge_tl_tenant_grants g
			WHERE g.status = 'active' AND (
				(g.principal_tenant_code = s.tenant_id AND g.managed_tenant_code = $%d)
				OR (g.managed_tenant_code = s.tenant_id AND g.principal_tenant_code = $%d)
			)
		)
		AND (
			s.scope_visibility = 'tenant'
			OR (s.scope_visibility = 'subtenant' AND s.subtenant_id = $%d)
			OR (
				s.scope_visibility = 'project'
				AND (
					s.project_id IS NULL
					OR EXISTS (
						SELECT 1 FROM maniforge_projects po, maniforge_projects ps
						WHERE po.id = s.project_id AND ps.id = $%d
						  AND ps.tenant_id = $%d AND po.code = ps.code
					)
				)
			)
		)
	)`, start, start+1, start+2, start+3, start+4, start+5, start+6)
}

func scanStockRows(rows *sql.Rows) ([]StockRow, error) {
	var items []StockRow
	for rows.Next() {
		row, err := scanStockFromRows(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, row)
	}
	return items, rows.Err()
}

func scanStockRow(row *sql.Row) (*StockRow, error) {
	var s StockRow
	var parentCode, parentName sql.NullString
	var sharedSub, sharedGrant, data sql.NullString
	err := row.Scan(
		&s.ID, &s.TenantID, &s.SubtenantID, &s.ProjectID, &s.ScopeVisibility,
		&sharedSub, &sharedGrant,
		&s.Code, &s.Name, &s.Type, &s.ParentID, &data, &s.Active, &s.Status,
		&s.CreatedBy, &s.UpdatedBy, &s.CreatedAt, &s.UpdatedAt,
		&parentCode, &parentName,
	)
	s.SharedSubtenantIDs = nullJSONRaw(sharedSub)
	s.SharedGrantTenantIDs = nullJSONRaw(sharedGrant)
	s.DataJSON = nullJSONRaw(data)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	s.ParentCode = parentCode
	s.ParentName = parentName
	return &s, nil
}

func scanStockFromRows(rows *sql.Rows) (StockRow, error) {
	var s StockRow
	var parentCode, parentName sql.NullString
	var sharedSub, sharedGrant, data sql.NullString
	err := rows.Scan(
		&s.ID, &s.TenantID, &s.SubtenantID, &s.ProjectID, &s.ScopeVisibility,
		&sharedSub, &sharedGrant,
		&s.Code, &s.Name, &s.Type, &s.ParentID, &data, &s.Active, &s.Status,
		&s.CreatedBy, &s.UpdatedBy, &s.CreatedAt, &s.UpdatedAt,
		&parentCode, &parentName,
	)
	s.SharedSubtenantIDs = nullJSONRaw(sharedSub)
	s.SharedGrantTenantIDs = nullJSONRaw(sharedGrant)
	s.DataJSON = nullJSONRaw(data)
	s.ParentCode = parentCode
	s.ParentName = parentName
	return s, err
}

func nullJSONRaw(v sql.NullString) json.RawMessage {
	if !v.Valid || v.String == "" {
		return nil
	}
	return json.RawMessage(v.String)
}

func (s StockRow) ToMap(viewerTenant string) map[string]any {
	out := map[string]any{
		"id": s.ID, "tenant_id": s.TenantID, "subtenant_id": s.SubtenantID,
		"code": s.Code, "name": s.Name, "type": s.Type,
		"active": s.Active, "status": s.Status,
		"scope_visibility": s.ScopeVisibility,
		"is_delegated_view": s.TenantID != viewerTenant,
	}
	if s.ProjectID.Valid {
		out["project_id"] = s.ProjectID.Int64
	}
	if s.ParentID.Valid {
		out["parent_id"] = s.ParentID.Int64
	}
	if s.ParentCode.Valid {
		out["parent_code"] = s.ParentCode.String
	}
	if s.ParentName.Valid {
		out["parent_name"] = s.ParentName.String
	}
	if len(s.DataJSON) > 0 {
		var data any
		if json.Unmarshal(s.DataJSON, &data) == nil {
			out["data"] = data
		}
	}
	if s.CreatedBy.Valid {
		out["created_by"] = s.CreatedBy.Int64
	}
	if s.UpdatedBy.Valid {
		out["updated_by"] = s.UpdatedBy.Int64
	}
	out["created_at"] = s.CreatedAt.UTC().Format("2006-01-02 15:04:05")
	if s.UpdatedAt.Valid {
		out["updated_at"] = s.UpdatedAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	return out
}

func nullJSON(b json.RawMessage) any {
	if len(b) == 0 {
		return nil
	}
	return b
}

func SessionProjectID(db *sql.DB, sessionProject sql.NullInt64, tenantID, subtenantID string) (int64, error) {
	if sessionProject.Valid && sessionProject.Int64 > 0 {
		return sessionProject.Int64, nil
	}
	var id int64
	err := db.QueryRow(
		`SELECT id FROM maniforge_projects
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND code = 'main' AND status = 'active'
		 LIMIT 1`, tenantID, subtenantID).Scan(&id)
	if err == sql.ErrNoRows {
		return 0, nil
	}
	return id, err
}
