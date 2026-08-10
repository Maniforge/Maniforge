// Файл: role_admin.go
// Назначение: guards для assign/revoke ролей (иерархия, self-escalation).
// См. также: repository/role.go, service/admin.go
package service

import (
	"maniforge/internal/rbac/repository"
)

var (
	privilegedRoles = map[string]struct{}{
		"super_admin": {}, "tenant_admin": {}, "subtenant_admin": {}, "security_auditor": {},
	}
	roleLevels = map[string]int{
		"super_admin": 100, "tenant_admin": 80, "subtenant_admin": 60,
		"security_auditor": 50, "support_operator": 30, "moderator": 20, "user": 10,
	}
)

type RoleAdminService struct {
	roles    *repository.RoleRepository
	security *repository.SecurityEventRepository
}

func NewRoleAdminService(roles *repository.RoleRepository, security *repository.SecurityEventRepository) *RoleAdminService {
	return &RoleAdminService{roles: roles, security: security}
}

func (s *RoleAdminService) GuardRoleMutation(
	actorUserID, targetUserID int64, roleCode, operation, tenantID, subtenantID string,
) map[string]any {
	if guard := s.guardRoleHierarchy(actorUserID, targetUserID, roleCode, operation, tenantID, subtenantID); guard["ok"] == false {
		return guard
	}
	_, isPrivileged := privilegedRoles[roleCode]
	if operation == "assign" && targetUserID == actorUserID && isPrivileged {
		has, _ := s.roles.HasRoleInScope(targetUserID, tenantID, subtenantID, roleCode)
		if !has {
			actor := actorUserID
			_ = s.security.Write("admin.user_role.assign.blocked_self_escalation", &actor, tenantID, subtenantID, "warning", map[string]any{"role_code": roleCode})
			return map[string]any{"ok": false, "error": "Self-escalation запрещен"}
		}
	}
	return map[string]any{"ok": true}
}

func (s *RoleAdminService) guardRoleHierarchy(
	actorUserID, targetUserID int64, roleCode, operation, tenantID, subtenantID string,
) map[string]any {
	actorLevel := s.actorMaxRoleLevel(actorUserID, tenantID, subtenantID)
	targetLevel := roleLevels[roleCode]
	if actorLevel >= roleLevels["super_admin"] {
		return map[string]any{"ok": true}
	}
	if operation == "assign" && targetLevel >= actorLevel {
		actor := actorUserID
		_ = s.security.Write("admin.user_role.assign.blocked_hierarchy", &actor, tenantID, subtenantID, "warning", map[string]any{
			"role_code": roleCode, "target_user_id": targetUserID,
		})
		return map[string]any{"ok": false, "error": "Нельзя назначить роль уровня актера или выше"}
	}
	return map[string]any{"ok": true}
}

func (s *RoleAdminService) actorMaxRoleLevel(actorUserID int64, tenantID, subtenantID string) int {
	codes, err := s.roles.ListRoleCodesForUser(actorUserID, tenantID, subtenantID)
	if err != nil {
		return 0
	}
	max := 0
	for _, code := range codes {
		if lvl := roleLevels[code]; lvl > max {
			max = lvl
		}
	}
	return max
}
