// Файл: role_scope.go
// Назначение: scoped role codes для custom-ролей tenant/workspace.
// См. также: service/admin_extended.go, app/Maniforge/Rbac/Controllers/AdminController.php
package service

import (
	"regexp"
	"strings"
)

var safeRolePattern = regexp.MustCompile(`[^a-z0-9_]+`)

func SafeRoleSegment(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	value = safeRolePattern.ReplaceAllString(value, "_")
	return strings.Trim(value, "_")
}

func RoleScopePrefix(tenantID, subtenantID string) string {
	return SafeRoleSegment(tenantID) + "__" + SafeRoleSegment(subtenantID) + "__"
}

func ScopedRoleCode(tenantID, subtenantID, code string) string {
	code = SafeRoleSegment(code)
	if code == "" {
		return ""
	}
	prefix := RoleScopePrefix(tenantID, subtenantID)
	if strings.HasPrefix(code, prefix) {
		return code
	}
	combined := prefix + code
	if len(combined) > 80 {
		return combined[:80]
	}
	return combined
}

func IsMutableRoleInScope(roleCode, tenantID, subtenantID string, isSystem bool) bool {
	if isSystem {
		return false
	}
	return strings.HasPrefix(roleCode, RoleScopePrefix(tenantID, subtenantID))
}
