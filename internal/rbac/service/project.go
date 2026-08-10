// Файл: project.go
// Назначение: projects и scope variables API.
// См. также: repository/project.go, handler/projects.go
package service

import (
	"database/sql"
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
	rbac       *RbacService
	versioning *versioning.Recorder
}

func NewProjectService(
	projects *repository.ProjectRepository,
	vars *repository.ScopeVariableRepository,
	rbac *RbacService,
	rec *versioning.Recorder,
) *ProjectService {
	return &ProjectService{projects: projects, variables: vars, rbac: rbac, versioning: rec}
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
