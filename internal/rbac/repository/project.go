// Файл: project.go
// Назначение: maniforge_projects — bootstrap main, CodeByID для licensing access-state.
// См. также: service/session.go, licensingclient/client.go
package repository

import (
	"database/sql"
	"encoding/json"
)

const (
	mainProjectCode  = "main"
	defaultProjectName = "Main"
)

type ProjectRepository struct {
	db *sql.DB
}

func NewProjectRepository(db *sql.DB) *ProjectRepository {
	return &ProjectRepository{db: db}
}

func (r *ProjectRepository) EnsureDefaultTenant(tenantID string) error {
	return r.ensureProject(tenantID, "", defaultProjectName+" (tenant)", true)
}

func (r *ProjectRepository) EnsureDefaultSubtenant(tenantID, subtenantID string) error {
	return r.ensureProject(tenantID, subtenantID, defaultProjectName, true)
}

func (r *ProjectRepository) ensureProject(tenantID, subtenantID, name string, isDefault bool) error {
	var exists bool
	err := r.db.QueryRow(
		`SELECT EXISTS(
			SELECT 1 FROM maniforge_projects
			WHERE tenant_id = $1 AND subtenant_id = $2 AND code = $3
		)`, tenantID, subtenantID, mainProjectCode).Scan(&exists)
	if err != nil {
		return err
	}
	if exists {
		if isDefault {
			_, _ = r.db.Exec(
				`UPDATE maniforge_projects SET is_default = TRUE
				 WHERE tenant_id = $1 AND subtenant_id = $2 AND code = $3`,
				tenantID, subtenantID, mainProjectCode)
		}
		return nil
	}

	meta, _ := json.Marshal(map[string]any{
		"bootstrap":     "go_registration",
		"project_scope": scopeLabel(subtenantID),
	})
	_, err = r.db.Exec(
		`INSERT INTO maniforge_projects (tenant_id, subtenant_id, code, name, status, is_default, metadata_json)
		 VALUES ($1, $2, $3, $4, 'active', $5, $6)
		 ON CONFLICT (tenant_id, subtenant_id, code) DO NOTHING`,
		tenantID, subtenantID, mainProjectCode, name, isDefault, meta)
	return err
}

func scopeLabel(subtenantID string) string {
	if subtenantID == "" {
		return "tenant"
	}
	return "subtenant"
}

// ProjectCodeForSession — code проекта сессии; fallback "main".
func ProjectCodeForSession(db *sql.DB, tenantID string, projectID sql.NullInt64) string {
	if !projectID.Valid {
		return mainProjectCode
	}
	code, err := NewProjectRepository(db).CodeByID(tenantID, projectID.Int64)
	if err != nil || code == "" {
		return mainProjectCode
	}
	return code
}

// CodeByID возвращает code проекта в tenant (для licensing access-state).
func (r *ProjectRepository) CodeByID(tenantID string, projectID int64) (string, error) {
	var projectCode string
	err := r.db.QueryRow(
		`SELECT code FROM maniforge_projects WHERE tenant_id = $1 AND id = $2 LIMIT 1`,
		tenantID, projectID).Scan(&projectCode)
	if err == sql.ErrNoRows {
		return "", nil
	}
	return projectCode, err
}

type ProjectRow struct {
	ID            int64
	TenantID      string
	SubtenantID   string
	Code          string
	Name          string
	Status        string
	IsDefault     bool
	Metadata      []byte
	WarehouseID   sql.NullInt64
	WarehouseCode sql.NullString
	WarehouseName sql.NullString
	WarehouseType sql.NullString
}

func (r *ProjectRepository) ListInScope(tenantID, subtenantID string, includeTenantLevel bool) ([]ProjectRow, error) {
	query := `SELECT id, tenant_id, subtenant_id, code, name, status, is_default, metadata_json
		FROM maniforge_projects
		WHERE tenant_id = $1 AND status = 'active' AND (subtenant_id = $2`
	if includeTenantLevel {
		query += ` OR subtenant_id = ''`
	}
	query += `) ORDER BY is_default DESC, code ASC`

	rows, err := r.db.Query(query, tenantID, subtenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var items []ProjectRow
	for rows.Next() {
		var p ProjectRow
		if err := rows.Scan(&p.ID, &p.TenantID, &p.SubtenantID, &p.Code, &p.Name, &p.Status, &p.IsDefault, &p.Metadata); err != nil {
			return nil, err
		}
		items = append(items, p)
	}
	return items, rows.Err()
}

func (r *ProjectRepository) CreateProject(tenantID, subtenantID, code, name string, metadata map[string]any, warehouseID sql.NullInt64) (*ProjectRow, error) {
	meta, _ := json.Marshal(metadata)
	var id int64
	err := r.db.QueryRow(
		`INSERT INTO maniforge_projects (tenant_id, subtenant_id, code, name, status, metadata_json, warehouse_id)
		 VALUES ($1, $2, $3, $4, 'active', $5, $6)
		 RETURNING id`,
		tenantID, subtenantID, code, name, meta, warehouseID).Scan(&id)
	if err != nil {
		return nil, err
	}
	return r.FindByIDInScope(id, tenantID, subtenantID)
}

func (r *ProjectRepository) FindByIDInScope(id int64, tenantID, subtenantID string) (*ProjectRow, error) {
	row := r.db.QueryRow(
		`SELECT p.id, p.tenant_id, p.subtenant_id, p.code, p.name, p.status, p.is_default, p.metadata_json,
		        p.warehouse_id, w.code, w.name, w.type
		 FROM maniforge_projects p
		 LEFT JOIN maniforge_wh_stocks w ON w.id = p.warehouse_id
		 WHERE p.id = $1 AND p.tenant_id = $2 AND p.subtenant_id = $3 LIMIT 1`,
		id, tenantID, subtenantID)
	return scanProjectRow(row)
}

func (r *ProjectRepository) LookupWarehouseNode(tenantID string, warehouseID int64) (stockType, status string, err error) {
	err = r.db.QueryRow(
		`SELECT type, status FROM maniforge_wh_stocks WHERE id = $1 AND tenant_id = $2 LIMIT 1`,
		warehouseID, tenantID).Scan(&stockType, &status)
	return
}

func scanProjectRow(row *sql.Row) (*ProjectRow, error) {
	var p ProjectRow
	err := row.Scan(
		&p.ID, &p.TenantID, &p.SubtenantID, &p.Code, &p.Name, &p.Status, &p.IsDefault, &p.Metadata,
		&p.WarehouseID, &p.WarehouseCode, &p.WarehouseName, &p.WarehouseType,
	)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &p, nil
}

func (p ProjectRow) ToMap() map[string]any {
	out := map[string]any{
		"id": p.ID, "tenant_id": p.TenantID, "subtenant_id": p.SubtenantID,
		"code": p.Code, "name": p.Name, "status": p.Status, "is_default": p.IsDefault,
	}
	if p.SubtenantID == "" {
		out["scope"] = "tenant"
		out["project_scope"] = "tenant"
	} else {
		out["scope"] = "subtenant"
		out["project_scope"] = "subtenant"
	}
	if len(p.Metadata) > 0 {
		var meta map[string]any
		if json.Unmarshal(p.Metadata, &meta) == nil {
			out["metadata"] = meta
		}
	}
	if p.WarehouseID.Valid {
		out["warehouse_id"] = p.WarehouseID.Int64
		out["warehouse"] = map[string]any{
			"id": p.WarehouseID.Int64, "code": p.WarehouseCode.String,
			"name": p.WarehouseName.String, "type": p.WarehouseType.String,
		}
	} else {
		out["warehouse_id"] = nil
		out["warehouse"] = nil
	}
	return out
}
