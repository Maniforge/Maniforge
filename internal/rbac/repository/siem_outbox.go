// Файл: siem_outbox.go
// Назначение: очередь доставки security events в SIEM (maniforge_siem_outbox).
// См. также: platform/siem/notifier.go, cmd/siem-forward/main.go
package repository

import (
	"database/sql"
	"encoding/json"
	"time"

	"maniforge/internal/platform/siem"
)

type SiemOutboxRepository struct {
	db *sql.DB
}

func NewSiemOutboxRepository(db *sql.DB) *SiemOutboxRepository {
	return &SiemOutboxRepository{db: db}
}

func (r *SiemOutboxRepository) Enqueue(
	eventType, tenantID, subtenantID, severity string, payload map[string]any, integrityHash string,
) (int64, error) {
	payloadJSON, err := json.Marshal(payload)
	if err != nil {
		return 0, err
	}
	var id int64
	err = r.db.QueryRow(
		`INSERT INTO maniforge_siem_outbox (
			event_type, tenant_id, subtenant_id, severity, payload_json, integrity_hash
		) VALUES ($1, $2, $3, $4, $5::jsonb, $6)
		RETURNING id`,
		eventType, tenantID, subtenantID, severity, string(payloadJSON), nullString(integrityHash),
	).Scan(&id)
	return id, err
}

func (r *SiemOutboxRepository) Pending(limit int) ([]siem.Event, error) {
	if limit < 1 {
		limit = 100
	}
	rows, err := r.db.Query(
		`SELECT id, event_type, tenant_id, subtenant_id, severity, payload_json, integrity_hash, created_at
		 FROM maniforge_siem_outbox
		 WHERE delivered_at IS NULL
		 ORDER BY id ASC
		 LIMIT $1`,
		limit,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []siem.Event
	for rows.Next() {
		var (
			ev        siem.Event
			payload   []byte
			hash      sql.NullString
			createdAt time.Time
		)
		if err := rows.Scan(&ev.ID, &ev.EventType, &ev.TenantID, &ev.SubtenantID, &ev.Severity, &payload, &hash, &createdAt); err != nil {
			return nil, err
		}
		ev.CreatedAt = createdAt.UTC().Format(time.RFC3339)
		if hash.Valid {
			ev.IntegrityHash = hash.String
		}
		_ = json.Unmarshal(payload, &ev.Payload)
		if ev.Payload == nil {
			ev.Payload = map[string]any{}
		}
		items = append(items, ev)
	}
	return items, rows.Err()
}

func (r *SiemOutboxRepository) MarkDelivered(id int64) error {
	_, err := r.db.Exec(
		`UPDATE maniforge_siem_outbox SET delivered_at = NOW(), last_error = NULL WHERE id = $1`,
		id,
	)
	return err
}

func (r *SiemOutboxRepository) MarkFailed(id int64, errMsg string) error {
	_, err := r.db.Exec(
		`UPDATE maniforge_siem_outbox
		 SET delivery_attempts = delivery_attempts + 1, last_error = $2
		 WHERE id = $1`,
		id, errMsg,
	)
	return err
}
