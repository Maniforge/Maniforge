// Файл: audit.go
// Назначение: maniforge_audit_log — запись и чтение audit-событий RBAC.
// См. также: repository/security_event.go, service/admin.go
package repository

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

type AuditRepository struct {
	db *sql.DB
}

func NewAuditRepository(db *sql.DB) *AuditRepository {
	return &AuditRepository{db: db}
}

type AuditRecord struct {
	ID             int64
	EventType      string
	ActorUserID    sql.NullInt64
	TenantID       string
	SubtenantID    string
	PayloadJSON    json.RawMessage
	CorrelationID  sql.NullString
	IntegrityHash  sql.NullString
	CreatedAt      time.Time
}

func (r *AuditRepository) Write(eventType string, actorUserID *int64, tenantID, subtenantID string, payload map[string]any) error {
	payloadJSON, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	correlationID := correlationFromPayload(payload)
	var actor sql.NullInt64
	if actorUserID != nil {
		actor = sql.NullInt64{Int64: *actorUserID, Valid: true}
	}
	integrity := sha256.Sum256([]byte(fmt.Sprintf("%s|%s|%s|%s|%s|%s",
		eventType, actorString(actor), tenantID, subtenantID, string(payloadJSON), correlationID)))
	_, err = r.db.Exec(
		`INSERT INTO maniforge_audit_log (
			event_type, actor_user_id, tenant_id, subtenant_id, payload_json, correlation_id, integrity_hash
		) VALUES ($1, $2, $3, $4, $5::jsonb, $6, $7)`,
		eventType, actor, tenantID, subtenantID, string(payloadJSON), nullString(correlationID), hex.EncodeToString(integrity[:]))
	return err
}

func (r *AuditRepository) ExportForScope(tenantID, subtenantID string, limit int) map[string]any {
	items, err := r.ListByScope(tenantID, subtenantID, limit)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}
	}
	digestParts := make([]string, 0, len(items))
	for _, row := range items {
		if h, ok := row["integrity_hash"].(string); ok {
			digestParts = append(digestParts, h)
		}
	}
	manifest := strings.Join(digestParts, "\n")
	sum := sha256.Sum256([]byte(manifest))
	return map[string]any{
		"exported_at": time.Now().UTC().Format(time.RFC3339),
		"tenant_id":   tenantID, "subtenant_id": subtenantID,
		"count": len(items), "items": items,
		"manifest_sha256": hex.EncodeToString(sum[:]),
	}
}

func (r *AuditRepository) ListByScope(tenantID, subtenantID string, limit int) ([]map[string]any, error) {
	if limit < 1 {
		limit = 100
	}
	rows, err := r.db.Query(
		`SELECT id, event_type, actor_user_id, tenant_id, subtenant_id, payload_json,
		        correlation_id, integrity_hash, created_at
		 FROM maniforge_audit_log
		 WHERE tenant_id = $1 AND subtenant_id = $2
		 ORDER BY id DESC LIMIT $3`,
		tenantID, subtenantID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanAuditRows(rows)
}

func scanAuditRows(rows *sql.Rows) ([]map[string]any, error) {
	var items []map[string]any
	for rows.Next() {
		var (
			rec        AuditRecord
			payloadRaw []byte
		)
		if err := rows.Scan(
			&rec.ID, &rec.EventType, &rec.ActorUserID, &rec.TenantID, &rec.SubtenantID,
			&payloadRaw, &rec.CorrelationID, &rec.IntegrityHash, &rec.CreatedAt,
		); err != nil {
			return nil, err
		}
		item := map[string]any{
			"id": rec.ID, "event_type": rec.EventType, "tenant_id": rec.TenantID,
			"subtenant_id": rec.SubtenantID, "created_at": rec.CreatedAt.UTC().Format("2006-01-02 15:04:05"),
		}
		if rec.ActorUserID.Valid {
			item["actor_user_id"] = rec.ActorUserID.Int64
		}
		if rec.CorrelationID.Valid {
			item["correlation_id"] = rec.CorrelationID.String
		}
		if rec.IntegrityHash.Valid {
			item["integrity_hash"] = rec.IntegrityHash.String
		}
		var payload any
		if len(payloadRaw) > 0 {
			_ = json.Unmarshal(payloadRaw, &payload)
		}
		item["payload_json"] = payload
		items = append(items, item)
	}
	return items, rows.Err()
}

func correlationFromPayload(payload map[string]any) string {
	for _, key := range []string{"correlation_id", "request_id"} {
		if v, ok := payload[key]; ok {
			s := fmt.Sprint(v)
			if s != "" {
				return s
			}
		}
	}
	return ""
}

func actorString(actor sql.NullInt64) string {
	if actor.Valid {
		return fmt.Sprint(actor.Int64)
	}
	return ""
}

func nullString(v string) sql.NullString {
	if v == "" {
		return sql.NullString{}
	}
	return sql.NullString{String: v, Valid: true}
}
