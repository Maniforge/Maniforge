// Файл: query.go
// Назначение: чтение maniforge_ver_changes и registry для HTTP API.
package versioning

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
)

type ChangeFilters struct {
	EntityTable string
	EntityID    string
	Operation   string
	ProjectID   *int64
	Limit       int
	Offset      int
}

func (r *Repository) ListInScope(tenantID, subtenantID string, f ChangeFilters) ([]map[string]any, error) {
	where := []string{"tenant_id = $1", "subtenant_id = $2"}
	args := []any{tenantID, subtenantID}
	n := 3
	if f.EntityTable != "" {
		where = append(where, fmt.Sprintf("entity_table = $%d", n))
		args = append(args, f.EntityTable)
		n++
	}
	if f.EntityID != "" {
		where = append(where, fmt.Sprintf("entity_id = $%d", n))
		args = append(args, f.EntityID)
		n++
	}
	if f.Operation != "" {
		where = append(where, fmt.Sprintf("operation = $%d", n))
		args = append(args, f.Operation)
		n++
	}
	limit := f.Limit
	if limit < 1 {
		limit = 50
	}
	if limit > 200 {
		limit = 200
	}
	offset := f.Offset
	if offset < 0 {
		offset = 0
	}
	query := `SELECT id, tenant_id, subtenant_id, project_id, entity_table, entity_id, entity_label,
		operation, actor_user_id, correlation_id, before_json, after_json, changed_at
		FROM maniforge_ver_changes WHERE ` + strings.Join(where, " AND ") +
		fmt.Sprintf(" ORDER BY changed_at DESC, id DESC LIMIT %d OFFSET %d", limit, offset)

	rows, err := r.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanChangeRows(rows)
}

func (r *Repository) CountInScope(tenantID, subtenantID string, f ChangeFilters) (int, error) {
	where := []string{"tenant_id = $1", "subtenant_id = $2"}
	args := []any{tenantID, subtenantID}
	n := 3
	if f.EntityTable != "" {
		where = append(where, fmt.Sprintf("entity_table = $%d", n))
		args = append(args, f.EntityTable)
		n++
	}
	if f.EntityID != "" {
		where = append(where, fmt.Sprintf("entity_id = $%d", n))
		args = append(args, f.EntityID)
		n++
	}
	if f.Operation != "" {
		where = append(where, fmt.Sprintf("operation = $%d", n))
		args = append(args, f.Operation)
	}
	var total int
	err := r.db.QueryRow(
		`SELECT COUNT(*) FROM maniforge_ver_changes WHERE `+strings.Join(where, " AND "),
		args...).Scan(&total)
	return total, err
}

func (r *Repository) ListRegistry(activeOnly bool) ([]map[string]any, error) {
	query := `SELECT id, entity_table, entity_label, description, is_active, created_at FROM maniforge_ver_registry`
	if activeOnly {
		query += ` WHERE is_active = TRUE`
	}
	query += ` ORDER BY entity_label ASC`
	rows, err := r.db.Query(query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var items []map[string]any
	for rows.Next() {
		var id int64
		var table, label string
		var desc sql.NullString
		var active bool
		var created sql.NullTime
		if err := rows.Scan(&id, &table, &label, &desc, &active, &created); err != nil {
			return nil, err
		}
		item := map[string]any{
			"id": id, "entity_table": table, "entity_label": label, "is_active": active,
		}
		if desc.Valid {
			item["description"] = desc.String
		}
		if created.Valid {
			item["created_at"] = created.Time
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func scanChangeRows(rows *sql.Rows) ([]map[string]any, error) {
	var items []map[string]any
	for rows.Next() {
		var id int64
		var tenantID, subtenantID, entityTable, entityID, operation string
		var entityLabel, correlationID sql.NullString
		var projectID, actorID sql.NullInt64
		var beforeRaw, afterRaw []byte
		var changedAt sql.NullTime
		if err := rows.Scan(&id, &tenantID, &subtenantID, &projectID, &entityTable, &entityID,
			&entityLabel, &operation, &actorID, &correlationID, &beforeRaw, &afterRaw, &changedAt); err != nil {
			return nil, err
		}
		item := map[string]any{
			"id": id, "tenant_id": tenantID, "subtenant_id": subtenantID,
			"entity_table": entityTable, "entity_id": entityID, "operation": operation,
			"before": decodeJSON(beforeRaw), "after": decodeJSON(afterRaw),
		}
		if projectID.Valid {
			item["project_id"] = projectID.Int64
		}
		if entityLabel.Valid {
			item["entity_label"] = entityLabel.String
		}
		if actorID.Valid {
			item["actor_user_id"] = actorID.Int64
		}
		if correlationID.Valid {
			item["correlation_id"] = correlationID.String
		}
		if changedAt.Valid {
			item["changed_at"] = changedAt.Time
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func decodeJSON(raw []byte) any {
	if len(raw) == 0 {
		return nil
	}
	var v any
	if json.Unmarshal(raw, &v) == nil {
		return v
	}
	return nil
}
