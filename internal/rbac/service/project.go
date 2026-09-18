// Файл: project.go
// Назначение: projects и scope variables API.
// См. также: repository/project.go, handler/projects.go
package service

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/code"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/versioning"
)

type ProjectService struct {
	projects   *repository.ProjectRepository
	variables  *repository.ScopeVariableRepository
	users      *repository.UserRepository
	rbac       *RbacService
	versioning *versioning.Recorder
}

func NewProjectService(
	projects *repository.ProjectRepository,
	vars *repository.ScopeVariableRepository,
	users *repository.UserRepository,
	rbac *RbacService,
	rec *versioning.Recorder,
) *ProjectService {
	return &ProjectService{projects: projects, variables: vars, users: users, rbac: rbac, versioning: rec}
}

func (s *ProjectService) ListProjects(session *repository.SessionRecord) (map[string]any, int) {
	includeTenant, _ := s.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{
		"super_admin", "tenant_admin", "subtenant_admin",
	})
	items, err := s.projects.ListInScope(session.TenantID, session.SubtenantID, includeTenant)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	out := make([]map[string]any, 0, len(items))
	for _, p := range items {
		out = append(out, p.ToMap())
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "items": out}, fiber.StatusOK
}

func (s *ProjectService) ListGlobalVariables(session *repository.SessionRecord) (map[string]any, int) {
	items, err := s.variables.ListVisible(session.TenantID, session.SubtenantID, session.ProjectID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	out := make([]map[string]any, 0, len(items))
	for _, v := range items {
		out = append(out, v.ToMap())
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "items": out}, fiber.StatusOK
}

func (s *ProjectService) SwitchProject(session *repository.SessionRecord, projectID *int64) (map[string]any, int) {
	var current *int64
	if session.ProjectID.Valid {
		cur := session.ProjectID.Int64
		current = &cur
	}
	if projectID == nil || *projectID == 0 {
		if current == nil {
			return map[string]any{"ok": true, "status": fiber.StatusOK, "session": map[string]any{
				"project_id": nil, "unchanged": true,
			}}, fiber.StatusOK
		}
		return map[string]any{"ok": true, "status": fiber.StatusOK, "session": map[string]any{
			"project_id": nil,
		}}, fiber.StatusOK
	}
	project, err := s.projects.FindByID(*projectID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if project == nil {
		return map[string]any{"ok": false, "error": "Проект не найден"}, fiber.StatusNotFound
	}
	if project.TenantID != session.TenantID {
		return map[string]any{"ok": false, "error": "Проект вне tenant сессии"}, fiber.StatusForbidden
	}
	if project.SubtenantID != "" && project.SubtenantID != session.SubtenantID {
		return map[string]any{"ok": false, "error": "Проект вне subtenant сессии"}, fiber.StatusForbidden
	}
	okAccess, err := s.userCanAccessProject(session, project)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if !okAccess {
		return map[string]any{"ok": false, "error": "Нет членства в проекте"}, fiber.StatusForbidden
	}
	payload := map[string]any{
		"project_id": project.ID, "project_code": project.Code,
		"project_scope": project.ToMap()["project_scope"],
	}
	if project.WarehouseID.Valid {
		payload["warehouse_id"] = project.WarehouseID.Int64
	}
	if current != nil && *current == project.ID {
		payload["unchanged"] = true
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "session": payload}, fiber.StatusOK
}

func (s *ProjectService) userCanAccessProject(session *repository.SessionRecord, project *repository.ProjectRow) (bool, error) {
	ok, err := s.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{
		"super_admin", "tenant_admin", "subtenant_admin",
	})
	if err != nil || ok {
		return ok, err
	}
	return s.projects.UserHasMembership(session.UserID, project.ID)
}

func (s *ProjectService) AssignUserToProject(session *repository.SessionRecord, userID int64, projectCode string) (map[string]any, int) {
	projectCode = strings.TrimSpace(projectCode)
	if userID <= 0 || projectCode == "" {
		return map[string]any{"ok": false, "error": "user_id и project_code обязательны"}, fiber.StatusUnprocessableEntity
	}
	project, err := s.projects.FindByCodeInScope(session.TenantID, session.SubtenantID, projectCode, true)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if project == nil {
		return map[string]any{"ok": false, "error": "Проект не найден"}, fiber.StatusNotFound
	}
	target, err := s.users.FindByIDInScope(userID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if target == nil {
		return map[string]any{"ok": false, "error": "Пользователь не найден в scope"}, fiber.StatusNotFound
	}
	if err := s.projects.AssignUser(userID, project.ID, session.TenantID, session.SubtenantID); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	label := fmt.Sprintf("%d@%s", userID, project.Code)
	if target.Login != "" {
		label = target.Login + "@" + project.Code
	}
	s.recordVersion(session, "maniforge_user_project_memberships", userID, "insert", nil, map[string]any{
		"user_id": userID, "project_id": project.ID, "project_code": project.Code,
	}, label)
	return map[string]any{
		"ok": true, "status": fiber.StatusCreated,
		"membership": map[string]any{"user_id": userID, "project_id": project.ID, "project_code": project.Code},
	}, fiber.StatusCreated
}

func (s *ProjectService) CreateProject(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	codeVal := code.Normalize(strings.TrimSpace(stringVal(input["code"])))
	name := strings.TrimSpace(stringVal(input["name"]))
	if codeVal == "" || name == "" {
		return map[string]any{"ok": false, "error": "code и name обязательны"}, fiber.StatusUnprocessableEntity
	}
	meta, _ := input["metadata"].(map[string]any)
	var warehouseID sql.NullInt64
	if raw, ok := input["warehouse_id"]; ok && raw != nil && raw != "" {
		wid := parseInt64Input(raw)
		if wid <= 0 {
			return map[string]any{"ok": false, "error": "warehouse_id должен быть положительным числом"}, fiber.StatusUnprocessableEntity
		}
		if err := s.validateWarehouse(session, wid); err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusUnprocessableEntity
		}
		warehouseID = sql.NullInt64{Int64: wid, Valid: true}
	}
	project, err := s.projects.CreateProject(session.TenantID, session.SubtenantID, codeVal, name, meta, warehouseID)
	if err != nil {
		if strings.Contains(err.Error(), "duplicate key") {
			return map[string]any{"ok": false, "error": "Проект с таким code уже существует"}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	s.recordVersion(session, "maniforge_projects", project.ID, "insert", nil, project.ToMap(), project.Code)
	return map[string]any{"ok": true, "status": fiber.StatusCreated, "project": project.ToMap()}, fiber.StatusCreated
}

func (s *ProjectService) GetProject(session *repository.SessionRecord, projectCode string) (map[string]any, int) {
	projectCode = code.Normalize(strings.TrimSpace(projectCode))
	if projectCode == "" {
		return map[string]any{"ok": false, "error": "code обязателен"}, fiber.StatusUnprocessableEntity
	}
	includeTenant, _ := s.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{
		"super_admin", "tenant_admin", "subtenant_admin",
	})
	project, err := s.projects.FindByCodeInScope(session.TenantID, session.SubtenantID, projectCode, includeTenant)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if project == nil {
		return map[string]any{"ok": false, "error": "Проект не найден"}, fiber.StatusNotFound
	}
	okAccess, err := s.userCanAccessProject(session, project)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if !okAccess {
		return map[string]any{"ok": false, "error": "Нет доступа к проекту"}, fiber.StatusForbidden
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "project": project.ToMap()}, fiber.StatusOK
}

func (s *ProjectService) UpdateProject(session *repository.SessionRecord, projectCode string, input map[string]any) (map[string]any, int) {
	projectCode = code.Normalize(strings.TrimSpace(projectCode))
	if projectCode == "" {
		return map[string]any{"ok": false, "error": "code обязателен"}, fiber.StatusUnprocessableEntity
	}
	includeTenant, _ := s.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{
		"super_admin", "tenant_admin", "subtenant_admin",
	})
	project, err := s.projects.FindByCodeInScope(session.TenantID, session.SubtenantID, projectCode, includeTenant)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if project == nil {
		return map[string]any{"ok": false, "error": "Проект не найден"}, fiber.StatusNotFound
	}
	okAccess, err := s.userCanAccessProject(session, project)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if !okAccess {
		return map[string]any{"ok": false, "error": "Нет доступа к проекту"}, fiber.StatusForbidden
	}

	before := project.ToMap()
	changed := false
	if _, ok := input["name"]; ok {
		name := strings.TrimSpace(stringVal(input["name"]))
		if name == "" {
			return map[string]any{"ok": false, "error": "name не может быть пустым"}, fiber.StatusUnprocessableEntity
		}
		project.Name = name
		changed = true
	}
	if _, ok := input["status"]; ok {
		status := strings.TrimSpace(stringVal(input["status"]))
		if status != "active" && status != "archived" && status != "suspended" {
			return map[string]any{"ok": false, "error": "status: active|archived|suspended"}, fiber.StatusUnprocessableEntity
		}
		project.Status = status
		changed = true
	}
	if _, ok := input["metadata"]; ok {
		meta, isMap := input["metadata"].(map[string]any)
		if input["metadata"] != nil && !isMap {
			return map[string]any{"ok": false, "error": "metadata должен быть объектом"}, fiber.StatusUnprocessableEntity
		}
		raw, _ := json.Marshal(meta)
		project.Metadata = raw
		changed = true
	}
	if raw, ok := input["warehouse_id"]; ok {
		if raw == nil || raw == "" {
			project.WarehouseID = sql.NullInt64{}
		} else {
			wid := parseInt64Input(raw)
			if wid <= 0 {
				return map[string]any{"ok": false, "error": "warehouse_id должен быть положительным числом"}, fiber.StatusUnprocessableEntity
			}
			if err := s.validateWarehouse(session, wid); err != nil {
				return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusUnprocessableEntity
			}
			project.WarehouseID = sql.NullInt64{Int64: wid, Valid: true}
		}
		changed = true
	}
	updated := project
	if changed {
		updated, err = s.projects.UpdateByID(project)
		if err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
		s.recordVersion(session, "maniforge_projects", updated.ID, "update", before, updated.ToMap(), updated.Code)
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "project": updated.ToMap()}, fiber.StatusOK
}

func (s *ProjectService) CreateGlobalVariable(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	key := strings.TrimSpace(stringVal(input["key"]))
	value := stringVal(input["value"])
	valueType := strings.TrimSpace(stringVal(input["value_type"]))
	if valueType == "" {
		valueType = "string"
	}
	scope := strings.ToLower(strings.TrimSpace(stringVal(input["scope_level"])))
	if scope == "" {
		scope = "subtenant"
	}
	if key == "" {
		return map[string]any{"ok": false, "error": "key обязателен"}, fiber.StatusUnprocessableEntity
	}

	varSubtenant := ""
	var projectID sql.NullInt64
	switch scope {
	case "subtenant":
		varSubtenant = session.SubtenantID
		ok, _ := s.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{
			"super_admin", "tenant_admin", "subtenant_admin",
		})
		if !ok {
			return map[string]any{"ok": false, "error": "Переменные subtenant-level требуют admin-роль"}, fiber.StatusForbidden
		}
	case "tenant":
		ok, _ := s.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{
			"super_admin", "tenant_admin",
		})
		if !ok {
			return map[string]any{"ok": false, "error": "Глобальные tenant-level переменные требуют tenant_admin"}, fiber.StatusForbidden
		}
	default:
		return map[string]any{"ok": false, "error": "scope_level: tenant|subtenant|project"}, fiber.StatusUnprocessableEntity
	}

	existing, _ := s.variables.FindByKey(session.TenantID, varSubtenant, projectID, key)
	item, err := s.variables.Upsert(session.TenantID, varSubtenant, projectID, scope, key, value, valueType)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	op := "update"
	if existing == nil {
		op = "insert"
	}
	var before map[string]any
	if existing != nil {
		before = existing.ToMap()
	}
	s.recordVersion(session, "maniforge_scope_variables", item.ID, op, before, item.ToMap(), item.Key)
	return map[string]any{"ok": true, "status": fiber.StatusCreated, "item": item.ToMap()}, fiber.StatusCreated
}

func (s *ProjectService) recordVersion(session *repository.SessionRecord, table string, entityID int64, op string, before, after map[string]any, label string) {
	if s.versioning == nil {
		return
	}
	var pid int64
	if session.ProjectID.Valid {
		pid = session.ProjectID.Int64
	}
	s.versioning.Record(versioning.Scope{
		TenantID: session.TenantID, SubtenantID: session.SubtenantID,
		ProjectID: pid, ActorUserID: session.UserID,
	}, table, strconv.FormatInt(entityID, 10), op, before, after, label)
}

func (s *ProjectService) validateWarehouse(session *repository.SessionRecord, warehouseID int64) error {
	stockType, status, err := s.projects.LookupWarehouseNode(session.TenantID, warehouseID)
	if err == sql.ErrNoRows {
		return fmt.Errorf("Склад не найден в scope проекта")
	}
	if err != nil {
		return err
	}
	if stockType != "warehouse" {
		return fmt.Errorf("warehouse_id должен указывать на узел типа warehouse")
	}
	if status != "active" {
		return fmt.Errorf("Склад должен быть в статусе active")
	}
	return nil
}

func parseInt64Input(v any) int64 {
	switch t := v.(type) {
	case float64:
		return int64(t)
	case int:
		return int64(t)
	case int64:
		return t
	default:
		var n int64
		fmt.Sscan(stringVal(v), &n)
		return n
	}
}

func stringVal(v any) string {
	if v == nil {
		return ""
	}
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}
