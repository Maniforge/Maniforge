// Файл: audit.go
// Назначение: audit trail складских узлов из maniforge_audit_log.
package repository

import (
	"database/sql"
	"encoding/json"
	"time"

	rbacrepo "maniforge/internal/rbac/repository"
)

type WarehouseAuditRepository struct {
	db    *sql.DB
	audit *rbacrepo.AuditRepository
	users *rbacrepo.UserRepository
}

func NewWarehouseAuditRepository(db *sql.DB, users *rbacrepo.UserRepository) *WarehouseAuditRepository {
	return &WarehouseAuditRepository{db: db, audit: rbacrepo.NewAuditRepository(db), users: users}
}

func (r *WarehouseAuditRepository) Write(eventType string, actorUserID int64, tenantID, subtenantID string, stockID int64, payload map[string]any) error {
	if payload == nil {
		payload = map[string]any{}
	}
	payload["stock_id"] = stockID
	actor := actorUserID
	return r.audit.Write(eventType, &actor, tenantID, subtenantID, payload)
}

func (r *WarehouseAuditRepository) ListForStock(tenantID, subtenantID string, stockID int64, limit int) ([]map[string]any, error) {
	if limit < 1 {
		limit = 50
	}
	if limit > 200 {
		limit = 200
	}
	rows, err := r.db.Query(
		`SELECT id, event_type, actor_user_id, payload_json, correlation_id, created_at
		 FROM maniforge_audit_log
		 WHERE tenant_id = $1 AND subtenant_id = $2
		   AND event_type LIKE 'warehouses.%'
		   AND (payload_json->>'stock_id')::bigint = $3
		 ORDER BY id DESC LIMIT $4`,
		tenantID, subtenantID, stockID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []map[string]any
	for rows.Next() {
		var (
			id        int64
			eventType string
			actorID   sql.NullInt64
			payload   []byte
			corr      sql.NullString
			created   time.Time
		)
		if err := rows.Scan(&id, &eventType, &actorID, &payload, &corr, &created); err != nil {
			return nil, err
		}
		var pl map[string]any
		_ = json.Unmarshal(payload, &pl)
		item := map[string]any{
			"id": id, "event_type": eventType, "stock_id": stockID,
			"payload": pl, "created_at": created.UTC().Format("2006-01-02 15:04:05"),
		}
		if corr.Valid {
			item["correlation_id"] = corr.String
		}
		if actorID.Valid && actorID.Int64 > 0 {
			if u, _ := r.users.FindByID(actorID.Int64); u != nil {
				item["actor_user"] = rbacrepo.PublicUser(*u)
			}
		}
		items = append(items, item)
	}
	return items, rows.Err()
}
