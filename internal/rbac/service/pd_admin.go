// Файл: pd_admin.go
// Назначение: PD admin API — operator profile, purposes, subject requests.
// См. также: repository/pd_admin.go, handler/pd_admin.go
package service

import (
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/rbac/repository"
)

type PDAdminService struct {
	pd    *repository.PDRepository
	audit *repository.AuditRepository
}

func NewPDAdminService(pd *repository.PDRepository, audit *repository.AuditRepository) *PDAdminService {
	return &PDAdminService{pd: pd, audit: audit}
}

func (s *PDAdminService) GetOperatorProfile(session *repository.SessionRecord) (map[string]any, int) {
	profile, err := s.pd.FindOperatorProfile(session.TenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "profile": profile}, fiber.StatusOK
}

func (s *PDAdminService) PutOperatorProfile(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	name := strings.TrimSpace(stringVal(input["operator_name"]))
	if name == "" {
		return map[string]any{"ok": false, "error": "operator_name обязателен"}, fiber.StatusUnprocessableEntity
	}
	profile, err := s.pd.UpsertOperatorProfile(session.TenantID, input)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.audit.Write("pd.operator_profile.updated", &actor, session.TenantID, session.SubtenantID, map[string]any{})
	return map[string]any{"ok": true, "profile": profile}, fiber.StatusOK
}

func (s *PDAdminService) ComplianceStatus(session *repository.SessionRecord) (map[string]any, int) {
	return map[string]any{
		"ok": true, "compliance": s.pd.BuildComplianceStatus(session.TenantID),
	}, fiber.StatusOK
}

func (s *PDAdminService) ListPurposes(session *repository.SessionRecord) (map[string]any, int) {
	items, err := s.pd.ListAllPurposes(session.TenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *PDAdminService) CreatePurpose(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	purpose, err := s.pd.CreatePurpose(session.TenantID, input)
	if err != nil {
		if isUniqueViolation(err) {
			return map[string]any{"ok": false, "error": "Цель с таким code уже существует"}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "purpose": purpose}, fiber.StatusCreated
}

func (s *PDAdminService) PatchPurpose(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	code := strings.TrimSpace(stringVal(input["code"]))
	if code == "" {
		return map[string]any{"ok": false, "error": "code обязателен"}, fiber.StatusUnprocessableEntity
	}
	purpose, err := s.pd.UpdatePurpose(session.TenantID, code, input)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if purpose == nil {
		return map[string]any{"ok": false, "error": "Цель не найдена"}, fiber.StatusNotFound
	}
	return map[string]any{"ok": true, "purpose": purpose}, fiber.StatusOK
}

func (s *PDAdminService) ListSubjectRequests(session *repository.SessionRecord, status string) (map[string]any, int) {
	items, err := s.pd.ListSubjectRequestsForScope(session.TenantID, session.SubtenantID, status, 100)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *PDAdminService) ResolveSubjectRequest(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	requestID := int64Val(input["request_id"])
	status := strings.ToLower(strings.TrimSpace(stringVal(input["status"])))
	note := strings.TrimSpace(stringVal(input["handler_note"]))
	if requestID <= 0 || status == "" {
		return map[string]any{"ok": false, "error": "request_id и status обязательны"}, fiber.StatusUnprocessableEntity
	}
	var notePtr *string
	if note != "" {
		notePtr = &note
	}
	row, err := s.pd.ResolveSubjectRequest(requestID, session.TenantID, session.SubtenantID, status, session.UserID, notePtr)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if row == nil {
		return map[string]any{"ok": false, "error": "Запрос не найден"}, fiber.StatusNotFound
	}
	actor := session.UserID
	_ = s.audit.Write("pd.subject_request.resolved", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"request_id": requestID, "status": status,
	})
	return map[string]any{"ok": true, "request": row}, fiber.StatusOK
}
