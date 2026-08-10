// Файл: versioning.go
// Назначение: снимки manifest record для maniforge_ver_changes.
package service

import (
	"strconv"

	"maniforge/internal/manifestengine/model"
	"maniforge/internal/versioning"
)

func versionScope(scope model.Scope) versioning.Scope {
	return versioning.Scope{
		TenantID:    scope.TenantID,
		SubtenantID: scope.SubtenantID,
		ProjectID:   scope.ProjectID,
		ActorUserID: scope.UserID,
	}
}

func recordSnapshot(rec *model.Record, manifestCode string) map[string]any {
	if rec == nil {
		return nil
	}
	return map[string]any{
		"id":            rec.ID,
		"manifest_id":   rec.ManifestID,
		"manifest_code": manifestCode,
		"tenant_id":     rec.TenantID,
		"project_id":    rec.ProjectID,
		"data":          rec.Data,
	}
}

func (s *Service) recordVersion(scope model.Scope, manifestCode, op string, before, after *model.Record) {
	if s.versioning == nil {
		return
	}
	var beforeMap, afterMap map[string]any
	var entityID string
	if before != nil {
		beforeMap = recordSnapshot(before, manifestCode)
		entityID = strconv.FormatInt(before.ID, 10)
	}
	if after != nil {
		afterMap = recordSnapshot(after, manifestCode)
		entityID = strconv.FormatInt(after.ID, 10)
	}
	if entityID == "" {
		return
	}
	s.versioning.Record(versionScope(scope), versioning.TableManifestRecords, entityID, op, beforeMap, afterMap, manifestCode)
}
