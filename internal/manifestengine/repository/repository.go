// Package repository — PostgreSQL для manifests и manifest_records.
package repository

import (
	"database/sql"
	"encoding/json"
	"time"

	"maniforge/internal/manifestengine/model"
)

type Repository struct {
	db *sql.DB
}

const recordActiveSQL = "deleted_at IS NULL"

func New(db *sql.DB) *Repository {
	return &Repository{db: db}
}

func (r *Repository) CreateManifest(scope model.Scope, code, name, origin string, fields []model.FieldDef, meta map[string]any, createdBy int64) (*model.Manifest, error) {
	if origin == "" {
		origin = model.OriginCustom
	}
	fieldsJSON, _ := json.Marshal(fields)
	metaJSON, _ := json.Marshal(meta)
	var id int64
	err := r.db.QueryRow(
		`INSERT INTO maniforge_manifests (
			tenant_id, project_id, code, name, origin, fields_json, metadata_json, created_by, updated_at
		) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, NOW())
		RETURNING id`,
		scope.TenantID, scope.ProjectID, code, name, origin, fieldsJSON, metaJSON, createdBy,
	).Scan(&id)
	if err != nil {
		return nil, err
	}
	return r.GetManifestByCode(scope, code)
}

func (r *Repository) GetManifestByCode(scope model.Scope, code string) (*model.Manifest, error) {
	row := r.db.QueryRow(
		`SELECT id, tenant_id, project_id, code, name, version, status, origin, fields_json, metadata_json,
		        created_by, created_at, updated_at
		 FROM maniforge_manifests
		 WHERE tenant_id = $1 AND project_id = $2 AND code = $3 AND status = 'active'
		 LIMIT 1`,
		scope.TenantID, scope.ProjectID, code)
	return scanManifest(row)
}

func (r *Repository) ListManifests(scope model.Scope, originFilter string, limit int) ([]model.Manifest, error) {
	if limit <= 0 || limit > 200 {
		limit = 100
	}
	base := `SELECT id, tenant_id, project_id, code, name, version, status, origin, fields_json, metadata_json,
		        created_by, created_at, updated_at
		 FROM maniforge_manifests
		 WHERE tenant_id = $1 AND project_id = $2 AND status = 'active'`
	if originFilter != "" {
		rows, err := r.db.Query(
			base+` AND origin = $3 ORDER BY code ASC LIMIT $4`,
			scope.TenantID, scope.ProjectID, originFilter, limit,
		)
		if err != nil {
			return nil, err
		}
		defer rows.Close()
		return collectManifestRows(rows)
	}
	rows, err := r.db.Query(
		base+` ORDER BY origin ASC, code ASC LIMIT $3`,
		scope.TenantID, scope.ProjectID, limit,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return collectManifestRows(rows)
}

func collectManifestRows(rows *sql.Rows) ([]model.Manifest, error) {
	var out []model.Manifest
	for rows.Next() {
		m, err := scanManifestRows(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, *m)
	}
	return out, rows.Err()
}

func (r *Repository) ArchiveManifest(scope model.Scope, code string) (bool, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_manifests SET status = 'archived', updated_at = NOW()
		 WHERE tenant_id = $1 AND project_id = $2 AND code = $3 AND status = 'active'`,
		scope.TenantID, scope.ProjectID, code)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func (r *Repository) UpdateManifest(scope model.Scope, code, name string, fields []model.FieldDef, meta map[string]any) (*model.Manifest, error) {
	fieldsJSON, _ := json.Marshal(fields)
	metaJSON, _ := json.Marshal(meta)
	_, err := r.db.Exec(
		`UPDATE maniforge_manifests
		 SET name = $4, fields_json = $5, metadata_json = $6, version = version + 1, updated_at = NOW()
		 WHERE tenant_id = $1 AND project_id = $2 AND code = $3 AND status = 'active'`,
		scope.TenantID, scope.ProjectID, code, name, fieldsJSON, metaJSON)
	if err != nil {
		return nil, err
	}
	return r.GetManifestByCode(scope, code)
}

func (r *Repository) CreateRecord(manifestID int64, scope model.Scope, data map[string]any, userID int64) (*model.Record, error) {
	raw, _ := json.Marshal(data)
	var id int64
	err := r.db.QueryRow(
		`INSERT INTO maniforge_manifest_records (
			manifest_id, tenant_id, project_id, data_json, created_by, updated_by, updated_at
		) VALUES ($1, $2, $3, $4, $5, $5, NOW())
		RETURNING id`,
		manifestID, scope.TenantID, scope.ProjectID, raw, userID,
	).Scan(&id)
	if err != nil {
		return nil, err
	}
	return r.GetRecord(scope, id)
}

func (r *Repository) GetRecord(scope model.Scope, id int64) (*model.Record, error) {
	row := r.db.QueryRow(
		`SELECT id, manifest_id, tenant_id, project_id, data_json, created_by, updated_by, created_at, updated_at
		 FROM maniforge_manifest_records
		 WHERE id = $1 AND tenant_id = $2 AND project_id = $3 AND `+recordActiveSQL+` LIMIT 1`,
		id, scope.TenantID, scope.ProjectID)
	return scanRecord(row)
}

func (r *Repository) ListRecords(manifestID int64, scope model.Scope, limit, offset int) ([]model.Record, error) {
	if limit <= 0 || limit > 200 {
		limit = 50
	}
	rows, err := r.db.Query(
		`SELECT id, manifest_id, tenant_id, project_id, data_json, created_by, updated_by, created_at, updated_at
		 FROM maniforge_manifest_records
		 WHERE manifest_id = $1 AND tenant_id = $2 AND project_id = $3 AND `+recordActiveSQL+`
		 ORDER BY id DESC LIMIT $4 OFFSET $5`,
		manifestID, scope.TenantID, scope.ProjectID, limit, offset)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []model.Record
	for rows.Next() {
		rec, err := scanRecordRows(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, *rec)
	}
	return out, rows.Err()
}

func (r *Repository) UpdateRecord(scope model.Scope, id int64, data map[string]any, userID int64) (*model.Record, error) {
	raw, _ := json.Marshal(data)
	_, err := r.db.Exec(
		`UPDATE maniforge_manifest_records
		 SET data_json = $4, updated_by = $5, updated_at = NOW()
		 WHERE id = $1 AND tenant_id = $2 AND project_id = $3 AND `+recordActiveSQL,
		id, scope.TenantID, scope.ProjectID, raw, userID)
	if err != nil {
		return nil, err
	}
	return r.GetRecord(scope, id)
}

// SoftDeleteRecord помечает запись удалённой (deleted_at), без физического DELETE.
func (r *Repository) SoftDeleteRecord(scope model.Scope, id int64, userID int64) (bool, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_manifest_records
		 SET deleted_at = NOW(), deleted_by = $4, updated_at = NOW()
		 WHERE id = $1 AND tenant_id = $2 AND project_id = $3 AND `+recordActiveSQL,
		id, scope.TenantID, scope.ProjectID, nullUserID(userID))
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func nullUserID(id int64) sql.NullInt64 {
	if id <= 0 {
		return sql.NullInt64{}
	}
	return sql.NullInt64{Int64: id, Valid: true}
}

func scanManifest(row *sql.Row) (*model.Manifest, error) {
	var m model.Manifest
	var fieldsRaw, metaRaw []byte
	var createdBy sql.NullInt64
	var updatedAt sql.NullTime
	err := row.Scan(
		&m.ID, &m.TenantID, &m.ProjectID, &m.Code, &m.Name, &m.Version, &m.Status, &m.Origin,
		&fieldsRaw, &metaRaw, &createdBy, &m.CreatedAt, &updatedAt,
	)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	_ = json.Unmarshal(fieldsRaw, &m.Fields)
	_ = json.Unmarshal(metaRaw, &m.Metadata)
	if createdBy.Valid {
		v := createdBy.Int64
		m.CreatedBy = &v
	}
	if updatedAt.Valid {
		t := updatedAt.Time.UTC()
		m.UpdatedAt = &t
	}
	return &m, nil
}

func scanManifestRows(rows *sql.Rows) (*model.Manifest, error) {
	var m model.Manifest
	var fieldsRaw, metaRaw []byte
	var createdBy sql.NullInt64
	var updatedAt sql.NullTime
	err := rows.Scan(
		&m.ID, &m.TenantID, &m.ProjectID, &m.Code, &m.Name, &m.Version, &m.Status, &m.Origin,
		&fieldsRaw, &metaRaw, &createdBy, &m.CreatedAt, &updatedAt,
	)
	if err != nil {
		return nil, err
	}
	_ = json.Unmarshal(fieldsRaw, &m.Fields)
	_ = json.Unmarshal(metaRaw, &m.Metadata)
	if createdBy.Valid {
		v := createdBy.Int64
		m.CreatedBy = &v
	}
	if updatedAt.Valid {
		t := updatedAt.Time.UTC()
		m.UpdatedAt = &t
	}
	return &m, nil
}

func scanRecord(row *sql.Row) (*model.Record, error) {
	var rec model.Record
	var raw []byte
	var createdBy, updatedBy sql.NullInt64
	var updatedAt sql.NullTime
	err := row.Scan(
		&rec.ID, &rec.ManifestID, &rec.TenantID, &rec.ProjectID, &raw,
		&createdBy, &updatedBy, &rec.CreatedAt, &updatedAt,
	)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	_ = json.Unmarshal(raw, &rec.Data)
	if createdBy.Valid {
		v := createdBy.Int64
		rec.CreatedBy = &v
	}
	if updatedBy.Valid {
		v := updatedBy.Int64
		rec.UpdatedBy = &v
	}
	if updatedAt.Valid {
		t := updatedAt.Time.UTC()
		rec.UpdatedAt = &t
	}
	return &rec, nil
}

func scanRecordRows(rows *sql.Rows) (*model.Record, error) {
	var rec model.Record
	var raw []byte
	var createdBy, updatedBy sql.NullInt64
	var updatedAt sql.NullTime
	err := rows.Scan(
		&rec.ID, &rec.ManifestID, &rec.TenantID, &rec.ProjectID, &raw,
		&createdBy, &updatedBy, &rec.CreatedAt, &updatedAt,
	)
	if err != nil {
		return nil, err
	}
	_ = json.Unmarshal(raw, &rec.Data)
	if createdBy.Valid {
		v := createdBy.Int64
		rec.CreatedBy = &v
	}
	if updatedBy.Valid {
		v := updatedBy.Int64
		rec.UpdatedBy = &v
	}
	if updatedAt.Valid {
		t := updatedAt.Time.UTC()
		rec.UpdatedAt = &t
	}
	return &rec, nil
}

func PublicManifest(m *model.Manifest) map[string]any {
	if m == nil {
		return nil
	}
	origin := m.Origin
	if origin == "" {
		origin = model.OriginCustom
	}
	out := map[string]any{
		"id": m.ID, "code": m.Code, "name": m.Name, "version": m.Version,
		"status": m.Status, "origin": origin, "fields": m.Fields,
		"tenant_id": m.TenantID, "project_id": m.ProjectID,
		"created_at": m.CreatedAt.UTC().Format(time.RFC3339),
	}
	out["type"] = model.DocTypeFromMetadata(m.Metadata)
	out["section"] = model.DocSectionFromMetadata(m.Metadata)
	if m.Metadata != nil {
		out["metadata"] = m.Metadata
	}
	if m.UpdatedAt != nil {
		out["updated_at"] = m.UpdatedAt.UTC().Format(time.RFC3339)
	}
	return out
}

func PublicRecord(rec *model.Record) map[string]any {
	if rec == nil {
		return nil
	}
	out := map[string]any{
		"id": rec.ID, "manifest_id": rec.ManifestID,
		"tenant_id": rec.TenantID, "project_id": rec.ProjectID,
		"data": rec.Data,
		"created_at": rec.CreatedAt.UTC().Format(time.RFC3339),
	}
	if rec.UpdatedAt != nil {
		out["updated_at"] = rec.UpdatedAt.UTC().Format(time.RFC3339)
	}
	return out
}
