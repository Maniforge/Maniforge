// Файл: delegated_access.go
// Назначение: политика мутаций для delegated grant (read_only / operator).
// См. также: middleware/delegated.go, app/Maniforge/Rbac/Security/DelegatedAccessPolicy.php
package service

import (
	"database/sql"
	"strings"

	"maniforge/internal/config"
	"maniforge/internal/rbac/repository"
)

type DelegatedAccessService struct {
	contexts *ContextService
}

func NewDelegatedAccessService(cfg config.Config, db *sql.DB) *DelegatedAccessService {
	return &DelegatedAccessService{contexts: NewContextService(cfg, db)}
}

var readOnlyMutationPaths = map[string]struct{}{
	"/api/v1/auth/logout":          {},
	"/api/v1/auth/logout-all":      {},
	"/api/v1/auth/refresh":         {},
	"/api/v1/auth/reauth":          {},
	"/api/v1/auth/switch-context":  {},
	"/api/v1/auth/switch-project":  {},
}

var operatorBlockedPrefixes = []string{
	"/api/v1/admin/users",
	"/api/v1/admin/user-roles",
	"/api/v1/admin/sessions/revoke",
	"/api/v1/admin/sessions/batch-revoke",
	"/api/v1/admin/registration-invites",
	"/api/v1/admin/policies",
	"/api/v1/admin/personal-data/operator-profile",
	"/api/v1/admin/personal-data/purposes",
	"/api/v1/admin/personal-data/subject-requests/resolve",
	"/api/v1/admin/roles",
	"/api/v1/admin/role-permissions",
}

// AllowsHTTPMutation проверяет delegated grant для mutating HTTP-запросов.
func (s *DelegatedAccessService) AllowsHTTPMutation(
	session *repository.SessionRecord, method, normalizedPath string,
) map[string]any {
	method = strings.ToUpper(strings.TrimSpace(method))
	switch method {
	case "POST", "PUT", "PATCH", "DELETE":
	default:
		return map[string]any{"ok": true}
	}

	grantLevel := s.resolveGrantLevel(session)
	if grantLevel == "" {
		return map[string]any{"ok": true}
	}

	path := "/" + strings.TrimPrefix(normalizedPath, "/")
	if _, ok := readOnlyMutationPaths[path]; ok {
		return map[string]any{"ok": true}
	}

	if grantLevel == "read_only" {
		return map[string]any{
			"ok": false,
			"error": "Делегированный доступ read_only: изменение данных запрещено",
			"code": "delegated_read_only",
			"grant_level": grantLevel,
		}
	}

	if grantLevel == "operator" && matchesOperatorBlockedPrefix(path) {
		return map[string]any{
			"ok": false,
			"error": "Делегированный operator: эта операция доступна только с grant_level admin",
			"code": "delegated_operator_restricted",
			"grant_level": grantLevel,
		}
	}

	return map[string]any{"ok": true}
}

func (s *DelegatedAccessService) resolveGrantLevel(session *repository.SessionRecord) string {
	if session == nil {
		return ""
	}
	ctx, status := s.contexts.ContextsForSession(session)
	if status != 200 {
		return ""
	}
	if ctx["ok"] != true {
		return ""
	}
	current, _ := ctx["current"].(map[string]any)
	if current == nil {
		return ""
	}
	delegated, _ := current["delegated"].(bool)
	kind, _ := current["kind"].(string)
	if !delegated && kind != "delegated" {
		return ""
	}
	level := strings.ToLower(strings.TrimSpace(stringVal(current["grant_level"])))
	return level
}

func matchesOperatorBlockedPrefix(path string) bool {
	for _, prefix := range operatorBlockedPrefixes {
		if path == prefix || strings.HasPrefix(path, prefix+"/") {
			return true
		}
	}
	return false
}
