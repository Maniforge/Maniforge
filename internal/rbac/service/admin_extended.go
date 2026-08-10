// Файл: admin_extended.go
// Назначение: admin API — sessions, audit, roles, security-events.
// См. также: service/admin.go, handler/admin.go
package service

import (
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/rbac/repository"
)

func (s *AdminService) ListSessions(session *repository.SessionRecord) (map[string]any, int) {
	items, err := s.sessions.ListByScope(session.TenantID, session.SubtenantID, 100)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.audit.Write("admin.sessions.list", &actor, session.TenantID, session.SubtenantID, map[string]any{})
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *AdminService) RevokeSession(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	sessionID := strings.TrimSpace(stringVal(input["session_id"]))
	reason := strings.TrimSpace(stringVal(input["reason"]))
	if sessionID == "" {
		return map[string]any{"ok": false, "error": "session_id обязателен"}, fiber.StatusUnprocessableEntity
	}
	if reason == "" {
		return map[string]any{"ok": false, "error": "reason обязателен"}, fiber.StatusUnprocessableEntity
	}
	exists, err := s.sessions.ExistsInScope(sessionID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if !exists {
		return map[string]any{"ok": false, "error": "Сессия не найдена в текущем контуре"}, fiber.StatusNotFound
	}
	revokeReason := "admin_revoke:" + reason
	sessionRevoked, err := s.sessions.RevokeInScope(sessionID, session.TenantID, session.SubtenantID, revokeReason)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	refreshRevoked, _ := s.sessions.RevokeRefreshBySessionID(sessionID, revokeReason)

	actor := session.UserID
	_ = s.audit.Write("admin.sessions.revoke", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"target_session_id": sessionID, "reason": reason,
		"session_revoked": sessionRevoked, "refresh_tokens_revoked": refreshRevoked,
	})
	_ = s.security.Write("admin.session.revoked", &actor, session.TenantID, session.SubtenantID, "warning", map[string]any{
		"target_session_id": sessionID, "reason": reason,
		"session_revoked": sessionRevoked, "refresh_tokens_revoked": refreshRevoked,
	})
	return map[string]any{
		"ok": true, "revoked_session_id": sessionID,
		"session_revoked": sessionRevoked, "refresh_tokens_revoked": refreshRevoked,
	}, fiber.StatusOK
}

func (s *AdminService) BatchRevokeSessions(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	reason := strings.TrimSpace(stringVal(input["reason"]))
	rawIDs, ok := input["session_ids"].([]any)
	dryRun := boolVal(input["dry_run"])
	if reason == "" || !ok || len(rawIDs) == 0 {
		return map[string]any{"ok": false, "error": "reason и непустой session_ids[] обязательны"}, fiber.StatusUnprocessableEntity
	}
	maxItems := envIntLocal("RBAC_BATCH_MAX_ITEMS", 100)
	if len(rawIDs) > maxItems {
		return map[string]any{"ok": false, "error": "Слишком большой batch"}, fiber.StatusUnprocessableEntity
	}

	var sessionIDs []string
	for _, v := range rawIDs {
		sessionIDs = append(sessionIDs, strings.TrimSpace(stringVal(v)))
	}
	summary := map[string]any{"revoked": 0, "skipped": 0, "total": len(sessionIDs)}
	var toRevoke []string
	for _, sessionID := range sessionIDs {
		active, err := s.sessions.ExistsActiveInScope(sessionID, session.TenantID, session.SubtenantID)
		if err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
		if active {
			toRevoke = append(toRevoke, sessionID)
			summary["revoked"] = intVal(summary["revoked"], 0) + 1
		} else {
			summary["skipped"] = intVal(summary["skipped"], 0) + 1
		}
	}

	if dryRun {
		actor := session.UserID
		_ = s.audit.Write("admin.sessions.batch_revoke.dry_run", &actor, session.TenantID, session.SubtenantID, map[string]any{
			"reason": reason, "summary": summary,
		})
		return map[string]any{"ok": true, "dry_run": true, "summary": summary}, fiber.StatusOK
	}

	revokeReason := "admin_batch_revoke:" + reason
	actualRevoked, err := s.sessions.RevokeBatchInScope(sessionIDs, session.TenantID, session.SubtenantID, revokeReason)
	if err != nil {
		return map[string]any{"ok": false, "error": "Ошибка batch revoke sessions"}, fiber.StatusInternalServerError
	}
	refreshRevoked, _ := s.sessions.RevokeRefreshBySessionIDsInScope(sessionIDs, session.TenantID, session.SubtenantID, revokeReason)
	summary["revoked"] = actualRevoked
	summary["skipped"] = len(sessionIDs) - actualRevoked
	summary["refresh_tokens_revoked"] = refreshRevoked

	actor := session.UserID
	_ = s.audit.Write("admin.sessions.batch_revoke", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"reason": reason, "summary": summary,
	})
	_ = s.security.Write("admin.sessions.batch_revoked", &actor, session.TenantID, session.SubtenantID, "warning", map[string]any{
		"reason": reason, "summary": summary,
	})
	return map[string]any{"ok": true, "summary": summary}, fiber.StatusOK
}

func (s *AdminService) ListAudit(session *repository.SessionRecord) (map[string]any, int) {
	items, err := s.audit.ListByScope(session.TenantID, session.SubtenantID, 100)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *AdminService) ExportAudit(session *repository.SessionRecord, limit int) (map[string]any, int) {
	if limit < 1 {
		limit = 5000
	}
	if limit > 20000 {
		limit = 20000
	}
	export := s.audit.ExportForScope(session.TenantID, session.SubtenantID, limit)
	if export["error"] != nil {
		return map[string]any{"ok": false, "error": export["error"]}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.audit.Write("admin.audit.exported", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"count": export["count"], "manifest_sha256": export["manifest_sha256"],
	})
	return map[string]any{"ok": true, "export": export}, fiber.StatusOK
}

func (s *AdminService) ListSecurityEvents(session *repository.SessionRecord) (map[string]any, int) {
	items, err := s.security.ListByScope(session.TenantID, session.SubtenantID, 100)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *AdminService) ListRoles(session *repository.SessionRecord) (map[string]any, int) {
	prefix := RoleScopePrefix(session.TenantID, session.SubtenantID)
	items, err := s.roles.ListRoles(prefix)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "scope_prefix": prefix, "items": items}, fiber.StatusOK
}

func (s *AdminService) CreateRole(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	roleCode := ScopedRoleCode(session.TenantID, session.SubtenantID, stringVal(input["code"]))
	name := strings.TrimSpace(stringVal(input["name"]))
	reason := strings.TrimSpace(stringVal(input["reason"]))
	if roleCode == "" || name == "" || reason == "" {
		return map[string]any{"ok": false, "error": "code, name и reason обязательны"}, fiber.StatusUnprocessableEntity
	}
	role, err := s.roles.CreateRole(roleCode, name)
	if err != nil {
		if isUniqueViolation(err) {
			return map[string]any{"ok": false, "error": "Роль уже существует"}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": "Ошибка создания роли"}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.audit.Write("admin.roles.create", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"role_code": roleCode, "reason": reason,
	})
	s.recordVersion(session, "maniforge_roles", roleCode, "insert", nil, role, roleCode)
	return map[string]any{"ok": true, "role": role}, fiber.StatusCreated
}

func (s *AdminService) UpdateRole(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	roleCode := ScopedRoleCode(session.TenantID, session.SubtenantID, stringVal(input["code"]))
	name := strings.TrimSpace(stringVal(input["name"]))
	reason := strings.TrimSpace(stringVal(input["reason"]))
	if roleCode == "" || name == "" || reason == "" {
		return map[string]any{"ok": false, "error": "code, name и reason обязательны"}, fiber.StatusUnprocessableEntity
	}
	before, _ := s.roles.FindRoleByCode(roleCode)
	if before == nil || !IsMutableRoleInScope(roleCode, session.TenantID, session.SubtenantID, boolValAny(before["is_system"])) {
		return map[string]any{"ok": false, "error": "Можно менять только custom-роли текущего scope"}, fiber.StatusForbidden
	}
	role, err := s.roles.UpdateRole(roleCode, name)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.audit.Write("admin.roles.update", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"role_code": roleCode, "reason": reason,
	})
	if role != nil {
		s.recordVersion(session, "maniforge_roles", roleCode, "update", before, role, roleCode)
	}
	return map[string]any{"ok": true, "role": role}, fiber.StatusOK
}

func (s *AdminService) DeleteRole(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	roleCode := ScopedRoleCode(session.TenantID, session.SubtenantID, stringVal(input["code"]))
	reason := strings.TrimSpace(stringVal(input["reason"]))
	if roleCode == "" || reason == "" {
		return map[string]any{"ok": false, "error": "code и reason обязательны"}, fiber.StatusUnprocessableEntity
	}
	before, _ := s.roles.FindRoleByCode(roleCode)
	if before == nil || !IsMutableRoleInScope(roleCode, session.TenantID, session.SubtenantID, boolValAny(before["is_system"])) {
		return map[string]any{"ok": false, "error": "Можно удалить только custom-роли текущего scope"}, fiber.StatusForbidden
	}
	deleted, err := s.roles.DeleteRole(roleCode)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.audit.Write("admin.roles.delete", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"role_code": roleCode, "reason": reason,
	})
	if deleted && before != nil {
		s.recordVersion(session, "maniforge_roles", roleCode, "delete", before, nil, roleCode)
	}
	return map[string]any{"ok": true, "deleted": deleted}, fiber.StatusOK
}

func (s *AdminService) ListPermissionsCatalog() (map[string]any, int) {
	items, err := s.roles.ListPermissions()
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *AdminService) ListRolePermissions(session *repository.SessionRecord, roleCode string) (map[string]any, int) {
	roleCode = ScopedRoleCode(session.TenantID, session.SubtenantID, roleCode)
	if roleCode == "" {
		return map[string]any{"ok": false, "error": "role_code обязателен"}, fiber.StatusUnprocessableEntity
	}
	items, err := s.roles.ListRolePermissions(roleCode)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "role_code": roleCode, "items": items}, fiber.StatusOK
}

func (s *AdminService) ReplaceRolePermissions(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	roleCode := ScopedRoleCode(session.TenantID, session.SubtenantID, stringVal(input["role_code"]))
	reason := strings.TrimSpace(stringVal(input["reason"]))
	permsRaw, ok := input["permissions"].([]any)
	if roleCode == "" || !ok || reason == "" {
		return map[string]any{"ok": false, "error": "role_code, permissions[] и reason обязательны"}, fiber.StatusUnprocessableEntity
	}
	role, _ := s.roles.FindRoleByCode(roleCode)
	if role == nil || !IsMutableRoleInScope(roleCode, session.TenantID, session.SubtenantID, boolValAny(role["is_system"])) {
		return map[string]any{"ok": false, "error": "Permissions можно менять только у custom-роли текущего scope"}, fiber.StatusForbidden
	}
	var codes []string
	for _, p := range permsRaw {
		codes = append(codes, stringVal(p))
	}
	before, _ := s.roles.ListRolePermissions(roleCode)
	result, err := s.roles.ReplaceRolePermissions(roleCode, codes)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if result["ok"] == false {
		return result, fiber.StatusUnprocessableEntity
	}
	actor := session.UserID
	_ = s.audit.Write("admin.roles.permissions.replace", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"role_code": roleCode, "permissions": codes, "reason": reason,
	})
	s.recordVersion(session, "maniforge_role_permissions", roleCode, "update", map[string]any{"items": before}, result, roleCode)
	return map[string]any{"ok": true, "role_code": roleCode, "items": result["permissions"]}, fiber.StatusOK
}

func boolValAny(v any) bool {
	switch t := v.(type) {
	case bool:
		return t
	default:
		return false
	}
}
