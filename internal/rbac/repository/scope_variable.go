// Файл: scope_variable.go
// Назначение: maniforge_scope_variables — tenant/subtenant/project переменные.
// См. также: service/project.go
package repository

import (
	"database/sql"
	"time"
)

type ScopeVariableRepository struct {
	db *sql.DB
}

func NewScopeVariableRepository(db *sql.DB) *ScopeVariableRepository {
	return &ScopeVariableRepository{db: db}
}

type ScopeVariable struct {
	ID          int64
	TenantID    string
	SubtenantID string
	ProjectID   sql.NullInt64
	Key         string
	Value       string
	ValueType   string
	ScopeLevel  string
}

func (r *ScopeVariableRepository) FindByKey(tenantID, subtenantID string, projectID sql.NullInt64, key string) (*ScopeVariable, error) {
	var pid any
	if projectID.Valid {
		pid = projectID.Int64
	}
	row := r.db.QueryRow(
		`SELECT id, tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level
		 FROM maniforge_scope_variables
		 WHERE tenant_id = $1 AND subtenant_id = $2
		   AND (($3::bigint IS NULL AND project_id IS NULL) OR project_id = $3)
		   AND var_key = $4
		 LIMIT 1`, tenantID, subtenantID, pid, key)

	var item ScopeVariable
	err := row.Scan(&item.ID, &item.TenantID, &item.SubtenantID, &item.ProjectID,
		&item.Key, &item.Value, &item.ValueType, &item.ScopeLevel)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &item, err
}

func (r *ScopeVariableRepository) Upsert(
	tenantID, subtenantID string, projectID sql.NullInt64, scopeLevel, key, value, valueType string,
) (*ScopeVariable, error) {
	var pid any
	if projectID.Valid {
		pid = projectID.Int64
	}
	row := r.db.QueryRow(
		`INSERT INTO maniforge_scope_variables (
			tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level, updated_at
		) VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())
		ON CONFLICT (tenant_id, subtenant_id, project_id, var_key) DO UPDATE SET
			var_value = EXCLUDED.var_value,
			value_type = EXCLUDED.value_type,
			scope_level = EXCLUDED.scope_level,
			updated_at = NOW()
		RETURNING id, tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level`,
		tenantID, subtenantID, pid, key, value, valueType, scopeLevel)

	var item ScopeVariable
	err := row.Scan(&item.ID, &item.TenantID, &item.SubtenantID, &item.ProjectID,
		&item.Key, &item.Value, &item.ValueType, &item.ScopeLevel)
	return &item, err
}

func (v ScopeVariable) ToMap() map[string]any {
	out := map[string]any{
		"id":           v.ID,
		"tenant_id":    v.TenantID,
		"subtenant_id": v.SubtenantID,
		"key":          v.Key,
		"value":        v.Value,
		"value_type":   v.ValueType,
		"scope_level":  v.ScopeLevel,
		"created_at":   time.Now().UTC().Format(time.RFC3339),
	}
	if v.ProjectID.Valid {
		out["project_id"] = v.ProjectID.Int64
	}
	return out
}
