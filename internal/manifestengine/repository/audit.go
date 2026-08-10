package repository

import (
	"database/sql"
	"encoding/json"
)

func (r *Repository) WriteAudit(eventType, tenantID string, projectID int64, manifestCode string, recordID *int64, actorUserID int64, payload map[string]any) error {
	raw, _ := json.Marshal(payload)
	_, err := r.db.Exec(
		`INSERT INTO maniforge_manifest_audit_log (
			event_type, tenant_id, project_id, manifest_code, record_id, actor_user_id, payload_json
		) VALUES ($1, $2, $3, $4, $5, $6, $7)`,
		eventType, tenantID, projectID, nullStr(manifestCode), nullInt64(recordID), nullInt64Ptr(actorUserID), raw)
	return err
}

func nullStr(s string) sql.NullString {
	if s == "" {
		return sql.NullString{}
	}
	return sql.NullString{String: s, Valid: true}
}

func nullInt64(id *int64) sql.NullInt64 {
	if id == nil {
		return sql.NullInt64{}
	}
	return sql.NullInt64{Int64: *id, Valid: true}
}

func nullInt64Ptr(id int64) sql.NullInt64 {
	if id == 0 {
		return sql.NullInt64{}
	}
	return sql.NullInt64{Int64: id, Valid: true}
}
