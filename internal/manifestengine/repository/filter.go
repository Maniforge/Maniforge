// Файл: filter.go
// Назначение: list/count records с JSONB filter и pagination meta.
package repository

import (
	"encoding/json"
	"fmt"
	"strings"

	"maniforge/internal/manifestengine/model"
)

// ListRecordsResult — страница записей и total для meta.
type ListRecordsResult struct {
	Records []model.Record
	Total   int
}

func (r *Repository) ListRecordsFiltered(
	manifestID int64,
	scope model.Scope,
	fields []model.FieldDef,
	filter model.RecordFilter,
	limit, offset int,
) (*ListRecordsResult, error) {
	if limit <= 0 || limit > 200 {
		limit = 50
	}
	if offset < 0 {
		offset = 0
	}

	where, args, err := buildRecordFilterSQL(manifestID, scope, filter)
	if err != nil {
		return nil, err
	}

	var total int
	countSQL := `SELECT COUNT(*) FROM maniforge_manifest_records WHERE ` + where
	if err := r.db.QueryRow(countSQL, args...).Scan(&total); err != nil {
		return nil, err
	}

	listArgs := append(append([]any{}, args...), limit, offset)
	listSQL := `SELECT id, manifest_id, tenant_id, project_id, data_json, created_by, updated_by, created_at, updated_at
		FROM maniforge_manifest_records WHERE ` + where +
		` ORDER BY id DESC LIMIT $` + fmt.Sprint(len(args)+1) + ` OFFSET $` + fmt.Sprint(len(args)+2)

	rows, err := r.db.Query(listSQL, listArgs...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var records []model.Record
	for rows.Next() {
		rec, err := scanRecordRows(rows)
		if err != nil {
			return nil, err
		}
		records = append(records, *rec)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return &ListRecordsResult{Records: records, Total: total}, nil
}

func buildRecordFilterSQL(manifestID int64, scope model.Scope, filter model.RecordFilter) (string, []any, error) {
	parts := []string{
		"manifest_id = $1",
		"tenant_id = $2",
		"project_id = $3",
		recordActiveSQL,
	}
	args := []any{manifestID, scope.TenantID, scope.ProjectID}
	argN := 4

	exact := make(map[string]any)
	for key, val := range filter {
		if like, ok := model.IsLikePattern(val); ok {
			parts = append(parts, fmt.Sprintf("data_json->>'%s' ILIKE $%d", key, argN))
			args = append(args, like)
			argN++
			continue
		}
		exact[key] = val
	}
	if len(exact) > 0 {
		raw, err := json.Marshal(exact)
		if err != nil {
			return "", nil, err
		}
		parts = append(parts, fmt.Sprintf("data_json @> $%d::jsonb", argN))
		args = append(args, string(raw))
	}

	return strings.Join(parts, " AND "), args, nil
}
