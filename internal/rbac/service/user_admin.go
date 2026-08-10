// Файл: user_admin.go
// Назначение: валидация и симуляция batch-операций над статусами пользователей.
// См. также: repository/user.go, service/admin.go
package service

import (
	"maniforge/internal/rbac/repository"
)

var allowedUserStatuses = map[string]struct{}{
	"active": {}, "locked": {}, "disabled": {},
}

type UserAdminService struct {
	users *repository.UserRepository
}

func NewUserAdminService(users *repository.UserRepository) *UserAdminService {
	return &UserAdminService{users: users}
}

func (s *UserAdminService) IsAllowedStatus(status string) bool {
	_, ok := allowedUserStatuses[status]
	return ok
}

func (s *UserAdminService) SimulateStatusBatchSummary(tenantID, subtenantID string, items []repository.StatusBatchItem) repository.StatusBatchSummary {
	summary := repository.StatusBatchSummary{
		Total:    len(items),
		ByStatus: map[string]int{"active": 0, "locked": 0, "disabled": 0},
	}
	for _, item := range items {
		if _, ok := summary.ByStatus[item.Status]; ok {
			summary.ByStatus[item.Status]++
		}
		current, err := s.users.FindStatusInScope(item.UserID, tenantID, subtenantID)
		if err != nil || current == nil {
			summary.NotFound++
			continue
		}
		if *current == item.Status {
			summary.Skipped++
			continue
		}
		summary.Changed++
	}
	return summary
}
