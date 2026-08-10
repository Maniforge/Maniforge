// Файл: security_event.go
// Назначение: maniforge_security_events — запись, чтение, SIEM outbox.
// См. также: repository/siem_outbox.go, platform/siem/notifier.go
package repository

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/platform/siem"
)

type SecurityEventRepository struct {
	db       *sql.DB
	cfg      config.Config
	outbox   *SiemOutboxRepository
	notifier *siem.Notifier
}

func NewSecurityEventRepository(db *sql.DB, cfg config.Config) *SecurityEventRepository {
	return &SecurityEventRepository{
		db:       db,
		cfg:      cfg,
		outbox:   NewSiemOutboxRepository(db),
		notifier: siem.NewNotifier(cfg),
	}
}

func (r *SecurityEventRepository) Write(
	eventType string, userID *int64, tenantID, subtenantID, severity string, payload map[string]any,
) error {
	payloadJSON, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	correlationID := correlationFromPayload(payload)
	var uid sql.NullInt64
	if userID != nil {
		uid = sql.NullInt64{Int64: *userID, Valid: true}
	}
	if severity == "" {
		severity = "info"
	}
	integrity := sha256.Sum256([]byte(fmt.Sprintf("%s|%s|%s|%s|%s|%s|%s",
		eventType, actorString(uid), tenantID, subtenantID, severity, string(payloadJSON), correlationID)))
	integrityHex := hex.EncodeToString(integrity[:])
	_, err = r.db.Exec(
		`INSERT INTO maniforge_security_events (
			event_type, user_id, tenant_id, subtenant_id, severity, payload_json, correlation_id, integrity_hash
		) VALUES ($1, $2, $3, $4, $5, $6::jsonb, $7, $8)`,
		eventType, uid, tenantID, subtenantID, severity, string(payloadJSON), nullString(correlationID), integrityHex)
	if err != nil {
		return err
	}
	if r.cfg.RBACSIEMWebhookEnabled {
		r.enqueueSIEM(eventType, tenantID, subtenantID, severity, payload, integrityHex)
	}
	return nil
}

func (r *SecurityEventRepository) enqueueSIEM(
	eventType, tenantID, subtenantID, severity string, payload map[string]any, integrityHex string,
) {
	outID, err := r.outbox.Enqueue(eventType, tenantID, subtenantID, severity, payload, integrityHex)
	if err != nil || !r.notifier.Enabled() {
		return
	}
	ev := siem.Event{
		ID: outID, EventType: eventType, TenantID: tenantID, SubtenantID: subtenantID,
		Severity: severity, Payload: payload, IntegrityHash: integrityHex,
		CreatedAt: time.Now().UTC().Format(time.RFC3339),
	}
	go func() {
		if err := r.notifier.Deliver(ev); err != nil {
			_ = r.outbox.MarkFailed(outID, err.Error())
			return
		}
		_ = r.outbox.MarkDelivered(outID)
	}()
}

func (r *SecurityEventRepository) ListByScope(tenantID, subtenantID string, limit int) ([]map[string]any, error) {
	if limit < 1 {
		limit = 100
	}
	rows, err := r.db.Query(
		`SELECT id, event_type, user_id, tenant_id, subtenant_id, severity, payload_json,
		        correlation_id, integrity_hash, created_at
		 FROM maniforge_security_events
		 WHERE tenant_id = $1 AND subtenant_id = $2
		 ORDER BY id DESC LIMIT $3`,
		tenantID, subtenantID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []map[string]any
	for rows.Next() {
		var (
			id        int64
			eventType string
			userID    sql.NullInt64
			tenant    string
			subtenant string
			severity  string
			payload   []byte
			corr      sql.NullString
			hash      sql.NullString
			createdAt time.Time
		)
		if err := rows.Scan(&id, &eventType, &userID, &tenant, &subtenant, &severity, &payload, &corr, &hash, &createdAt); err != nil {
			return nil, err
		}
		item := map[string]any{
			"id": id, "event_type": eventType, "tenant_id": tenant, "subtenant_id": subtenant,
			"severity": severity, "created_at": createdAt.UTC().Format("2006-01-02 15:04:05"),
		}
		if userID.Valid {
			item["user_id"] = userID.Int64
		}
		if corr.Valid {
			item["correlation_id"] = corr.String
		}
		if hash.Valid {
			item["integrity_hash"] = hash.String
		}
		var payloadObj any
		if len(payload) > 0 {
			_ = json.Unmarshal(payload, &payloadObj)
		}
		item["payload_json"] = payloadObj
		items = append(items, item)
	}
	return items, rows.Err()
}
