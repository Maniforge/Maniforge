// Файл: scope.go
// Назначение: контур сессии для Realtime API.
package service

import (
	"fmt"

	"maniforge/internal/rbac/repository"
)

type Scope struct {
	TenantID    string
	SubtenantID string
	ProjectID   int64
	UserID      int64
}

func ScopeFromSession(session *repository.SessionRecord) (Scope, error) {
	if session == nil {
		return Scope{}, fmt.Errorf("нет сессии")
	}
	if !session.ProjectID.Valid {
		return Scope{}, fmt.Errorf("project_id обязателен в сессии")
	}
	return Scope{
		TenantID:    session.TenantID,
		SubtenantID: session.SubtenantID,
		ProjectID:   session.ProjectID.Int64,
		UserID:      session.UserID,
	}, nil
}

func (s Scope) requireProject() error {
	if s.ProjectID <= 0 {
		return fmt.Errorf("project_id обязателен в сессии")
	}
	return nil
}
