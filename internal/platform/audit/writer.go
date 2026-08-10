// Файл: writer.go
// Назначение: запись событий в maniforge_audit_log (единый контур аудита модулей).
// Зависимости: PostgreSQL maniforge_audit_log.
// См. также: app/Maniforge/Rbac/Repository/AuditLogRepository.php, docs/MANIFORGE_CREDENTIAL_ARCHITECTURE.md
package audit

import (
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"strconv"
	"strings"
)

// Writer пишет события в maniforge_audit_log (best-effort, без panic).
type Writer struct {
	db *sql.DB
}

func NewWriter(db *sql.DB) *Writer {
	return &Writer{db: db}
}

// Write сохраняет audit-событие с integrity_hash и correlation_id.
func (w *Writer) Write(eventType string, actorUserID *int64, tenantID, subtenantID string, payload map[string]any) error {
	if w == nil || w.db == nil {
		return nil
	}
	raw, err := json.Marshal(scrubPayload(payload))
	if err != nil {
		return err
	}
	correlationID := correlationFromPayload(payload)
	integrity := sha256.Sum256([]byte(strings.Join([]string{
		eventType,
		int64Str(actorUserID),
		tenantID,
		subtenantID,
		string(raw),
		correlationID,
	}, "|")))
	_, err = w.db.Exec(
		`INSERT INTO maniforge_audit_log (
			event_type, actor_user_id, tenant_id, subtenant_id, payload_json, correlation_id, integrity_hash
		) VALUES ($1, $2, $3, $4, $5, $6, $7)`,
		eventType, nullInt64(actorUserID), tenantID, subtenantID, raw, correlationID, hex.EncodeToString(integrity[:]),
	)
	return err
}

func scrubPayload(payload map[string]any) map[string]any {
	if payload == nil {
		return map[string]any{}
	}
	out := make(map[string]any, len(payload))
	for k, v := range payload {
		out[k] = v
	}
	for _, key := range []string{"password", "password_hash", "token", "refresh_token", "access_token", "phone", "email"} {
		if _, ok := out[key]; ok {
			out[key] = "[redacted]"
		}
	}
	return out
}

func correlationFromPayload(payload map[string]any) string {
	if v, ok := payload["correlation_id"].(string); ok {
		v = strings.TrimSpace(v)
		if len(v) == 32 {
			return strings.ToLower(v)
		}
	}
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func nullInt64(id *int64) sql.NullInt64 {
	if id == nil || *id <= 0 {
		return sql.NullInt64{}
	}
	return sql.NullInt64{Int64: *id, Valid: true}
}

func int64Str(id *int64) string {
	if id == nil {
		return ""
	}
	return strconv.FormatInt(*id, 10)
}
