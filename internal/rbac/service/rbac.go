// Файл: rbac.go
// Назначение: проверка ролей и permissions пользователя в workspace.
// См. также: repository/role.go, service/guard.go
package service

import (
	"maniforge/internal/rbac/repository"
)

type RbacService struct {
	roles *repository.RoleRepository
}

func NewRbacService(roles *repository.RoleRepository) *RbacService {
	return &RbacService{roles: roles}
}

func (s *RbacService) RolesForUser(userID int64, tenantID, subtenantID string) ([]string, error) {
	return s.roles.ListRoleCodesForUser(userID, tenantID, subtenantID)
}

func (s *RbacService) PermissionsForUser(userID int64, tenantID, subtenantID string) ([]string, error) {
	return s.roles.ListPermissionCodesForUser(userID, tenantID, subtenantID)
}

func (s *RbacService) HasPermission(userID int64, tenantID, subtenantID, permission string) (bool, error) {
	codes, err := s.PermissionsForUser(userID, tenantID, subtenantID)
	if err != nil {
		return false, err
	}
	for _, c := range codes {
		if c == permission {
			return true, nil
		}
	}
	return false, nil
}

func (s *RbacService) HasAnyRole(userID int64, tenantID, subtenantID string, required []string) (bool, error) {
	return s.roles.HasAnyRole(userID, tenantID, subtenantID, required)
}

func (s *RbacService) EffectiveAccess(userID int64, tenantID, subtenantID string) (map[string]any, error) {
	roles, err := s.RolesForUser(userID, tenantID, subtenantID)
	if err != nil {
		return nil, err
	}
	perms, err := s.PermissionsForUser(userID, tenantID, subtenantID)
	if err != nil {
		return nil, err
	}
	return map[string]any{"roles": roles, "permissions": perms}, nil
}
