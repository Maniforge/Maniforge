// Файл: recorder.go
// Назначение: ChangeRecorder — best-effort запись insert/update/delete в ver_changes.
package versioning

import (
	"database/sql"
	"strings"

	"maniforge/internal/config"
)

// Scope — контур для versioning (tenant + workspace + project + актор).
type Scope struct {
	TenantID    string
	SubtenantID string
	ProjectID   int64
	ActorUserID int64
}

// Recorder пишет снимки before/after с redact чувствительных полей.
type Recorder struct {
	cfg  config.Config
	repo *Repository
}

func NewRecorder(cfg config.Config, db *sql.DB) *Recorder {
	return &Recorder{cfg: cfg, repo: NewRepository(db)}
}

// Record сохраняет изменение, если VERSIONING_ENABLED и таблица в реестре.
func (r *Recorder) Record(scope Scope, entityTable, entityID, operation string, before, after map[string]any, entityLabel string) {
	if !r.enabled() {
		return
	}
	op := strings.ToLower(strings.TrimSpace(operation))
	if op != "insert" && op != "update" && op != "delete" {
		return
	}
	tracked, err := r.repo.IsTableTracked(entityTable)
	if err != nil || !tracked {
		return
	}

	var projectID *int64
	if scope.ProjectID > 0 {
		projectID = &scope.ProjectID
	}
	var actor *int64
	if scope.ActorUserID > 0 {
		v := scope.ActorUserID
		actor = &v
	}
	_, _ = r.repo.InsertChange(ChangeInput{
		TenantID:    scope.TenantID,
		SubtenantID: scope.SubtenantID,
		ProjectID:   projectID,
		EntityTable: entityTable,
		EntityID:    entityID,
		EntityLabel: entityLabel,
		Operation:   op,
		ActorUserID: actor,
		Before:      redact(before),
		After:       redact(after),
	})
}

func (r *Recorder) enabled() bool {
	return r.cfg.VersioningEnabled
}

func redact(payload map[string]any) map[string]any {
	if payload == nil {
		return nil
	}
	out := make(map[string]any, len(payload))
	for k, v := range payload {
		out[k] = v
	}
	for _, key := range []string{
		"password_hash", "password", "token", "refresh_token", "access_token",
		"session_secret_hash", "phone", "email",
	} {
		if _, ok := out[key]; ok {
			out[key] = "[redacted]"
		}
	}
	return out
}
