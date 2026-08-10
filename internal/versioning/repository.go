// Package versioning — запись снимков изменений в maniforge_ver_changes.
//
// Файл: repository.go
// Назначение: insert и проверка реестра отслеживаемых таблиц.
// См. также: recorder.go, app/Maniforge/Versioning (PHP-референс)
package versioning

import (
	"database/sql"
	"encoding/json"
)

const TableManifestRecords = "maniforge_manifest_records"

// ChangeInput — строка журнала версий.
type ChangeInput struct {
	TenantID      string
	SubtenantID   string
	ProjectID     *int64
	EntityTable   string
	EntityID      string
	EntityLabel   string
	Operation     string
	ActorUserID   *int64
	CorrelationID string
	Before        map[string]any
	After         map[string]any
}

// Repository — доступ к maniforge_ver_changes / maniforge_ver_registry.
type Repository struct {
	db *sql.DB
}

func NewRepository(db *sql.DB) *Repository {
	return &Repository{db: db}
}

func (r *Repository) IsTableTracked(entityTable string) (bool, error) {
	var one int
	err := r.db.QueryRow(
		`SELECT 1 FROM maniforge_ver_registry
		 WHERE entity_table = $1 AND is_active = TRUE LIMIT 1`,
		entityTable,
	).Scan(&one)
	if err == sql.ErrNoRows {
		return false, nil
	}
	return err == nil, err
}

func (r *Repository) InsertChange(in ChangeInput) (int64, error) {
	beforeRaw, err := encodeJSON(in.Before)
	if err != nil {
		return 0, err
	}
	afterRaw, err := encodeJSON(in.After)
	if err != nil {
		return 0, err
	}

	var id int64
	err = r.db.QueryRow(
		`INSERT INTO maniforge_ver_changes (
			tenant_id, subtenant_id, project_id, entity_table, entity_id, entity_label,
			operation, actor_user_id, correlation_id, before_json, after_json, changed_at
		 ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, NOW())
		 RETURNING id`,
		in.TenantID, in.SubtenantID, nullInt64(in.ProjectID), in.EntityTable, in.EntityID,
		nullString(in.EntityLabel), in.Operation, nullInt64(in.ActorUserID),
		nullString(in.CorrelationID), beforeRaw, afterRaw,
	).Scan(&id)
	return id, err
}

// CountByEntity — число записей в журнале для entity (тесты / journey).
func (r *Repository) CountByEntity(tenantID, subtenantID, entityTable, entityID string) (int, error) {
	var n int
	err := r.db.QueryRow(
		`SELECT COUNT(*) FROM maniforge_ver_changes
		 WHERE tenant_id = $1 AND subtenant_id = $2
		   AND entity_table = $3 AND entity_id = $4`,
		tenantID, subtenantID, entityTable, entityID,
	).Scan(&n)
	return n, err
}

func encodeJSON(v map[string]any) ([]byte, error) {
	if v == nil {
		return nil, nil
	}
	return json.Marshal(v)
}

func nullInt64(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullString(v string) any {
	if v == "" {
		return nil
	}
	return v
}
