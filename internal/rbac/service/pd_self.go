// Файл: pd_self.go
// Назначение: self-service ПДн — экспорт, согласия, subject-requests.
// См. также: handler/pd_self.go, repository/pd_admin.go
package service

import (
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/rbac/repository"
)

var pdSubjectRequestTypes = map[string]struct{}{
	"access": {}, "rectification": {}, "erasure": {}, "restriction": {}, "withdraw_consent": {},
}

type PDSelfService struct {
	pd       *repository.PDRepository
	users    *repository.UserRepository
	profiles *repository.UserProfileRepository
	sessions *repository.SessionRepository
	audit    *repository.AuditRepository
}

func NewPDSelfService(
	pd *repository.PDRepository,
	users *repository.UserRepository,
	profiles *repository.UserProfileRepository,
	sessions *repository.SessionRepository,
	audit *repository.AuditRepository,
) *PDSelfService {
	return &PDSelfService{pd: pd, users: users, profiles: profiles, sessions: sessions, audit: audit}
}

func (s *PDSelfService) ExportMe(session *repository.SessionRecord) (map[string]any, int) {
	user, err := s.users.FindByIDInScope(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if user == nil {
		return map[string]any{"ok": false, "error": "Пользователь не найден"}, fiber.StatusNotFound
	}
	profile, _ := s.profiles.FindByUserID(session.UserID)
	consents, err := s.pd.ListConsentsForUser(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	requests, err := s.pd.ListSubjectRequestsForUser(session.UserID, session.TenantID, session.SubtenantID, 50)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	sessionItems, err := s.sessions.ListActiveForUser(session.UserID, session.TenantID, session.SubtenantID, 20)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	auditItems, _ := s.audit.ListForActor(session.UserID, session.TenantID, session.SubtenantID, 30)
	actor := session.UserID
	_ = s.audit.Write("pd.export", &actor, session.TenantID, session.SubtenantID, map[string]any{})
	return map[string]any{
		"ok": true, "status": fiber.StatusOK,
		"exported_at": time.Now().UTC().Format(time.RFC3339),
		"data": map[string]any{
			"user":             repository.PublicUser(*user),
			"profile":          repository.PublicProfile(profile),
			"consents":         consents,
			"subject_requests": requests,
			"sessions_summary": map[string]any{"active_count": len(sessionItems), "items": sessionItems},
			"audit_recent":     auditItems,
		},
	}, fiber.StatusOK
}

func (s *PDSelfService) ListConsents(session *repository.SessionRecord) (map[string]any, int) {
	items, err := s.pd.ListConsentsForUser(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *PDSelfService) GrantConsent(session *repository.SessionRecord, purposeCode, policyVersion, ip, userAgent string) (map[string]any, int) {
	purposeCode = strings.TrimSpace(purposeCode)
	if purposeCode == "" {
		return map[string]any{"ok": false, "error": "purpose_code обязателен"}, fiber.StatusUnprocessableEntity
	}
	purpose, err := s.pd.FindActivePurpose(session.TenantID, purposeCode)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if purpose == nil {
		return map[string]any{"ok": false, "error": "Цель обработки не найдена"}, fiber.StatusNotFound
	}
	if strings.TrimSpace(policyVersion) == "" {
		policyVersion = stringVal(purpose["policy_version"])
		if policyVersion == "" {
			policyVersion = "1.0"
		}
	}
	row, err := s.pd.GrantConsent(
		session.UserID, session.TenantID, session.SubtenantID,
		purposeCode, policyVersion, "api",
		repository.HashCredentialToken(ip), repository.HashCredentialToken(userAgent),
	)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "status": fiber.StatusCreated, "consent": row}, fiber.StatusCreated
}

func (s *PDSelfService) RevokeConsent(session *repository.SessionRecord, purposeCode string) (map[string]any, int) {
	purposeCode = strings.TrimSpace(purposeCode)
	if purposeCode == "" {
		return map[string]any{"ok": false, "error": "purpose_code обязателен"}, fiber.StatusUnprocessableEntity
	}
	if err := s.pd.RevokeActiveConsent(session.UserID, session.TenantID, session.SubtenantID, purposeCode); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK}, fiber.StatusOK
}

func (s *PDSelfService) ListSubjectRequests(session *repository.SessionRecord) (map[string]any, int) {
	items, err := s.pd.ListSubjectRequestsForUser(session.UserID, session.TenantID, session.SubtenantID, 50)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *PDSelfService) CreateSubjectRequest(session *repository.SessionRecord, requestType string, payload map[string]any) (map[string]any, int) {
	requestType = strings.ToLower(strings.TrimSpace(requestType))
	if _, ok := pdSubjectRequestTypes[requestType]; !ok {
		return map[string]any{"ok": false, "error": "Некорректный request_type"}, fiber.StatusUnprocessableEntity
	}
	slaDays := 30
	if v := strings.TrimSpace(os.Getenv("RBAC_PD_REQUEST_SLA_DAYS")); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			slaDays = n
		}
	}
	dueAt := time.Now().UTC().Add(time.Duration(slaDays) * 24 * time.Hour)
	row, err := s.pd.CreateSubjectRequest(session.UserID, session.TenantID, session.SubtenantID, requestType, payload, dueAt)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.audit.Write("pd.subject_request.created", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"request_type": requestType,
	})
	return map[string]any{"ok": true, "status": fiber.StatusCreated, "request": row}, fiber.StatusCreated
}
