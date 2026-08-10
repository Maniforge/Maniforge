// Файл: tenant_lifecycle.go
// Назначение: обработка internal tenant-events (revoke sessions).
// См. также: handler/internal.go, app/Maniforge/Rbac/Controllers/TenantLifecycleEventController.php
package service

import (
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/code"
	"maniforge/internal/rbac/repository"
)

var revokingTenantEvents = map[string]struct{}{
	"tenant.suspended": {}, "tenant.disabled": {},
	"subtenant.suspended": {}, "subtenant.disabled": {},
	"license.revoked": {}, "license.expired": {},
}

type TenantLifecycleService struct {
	sessions *repository.SessionRepository
	audit    *repository.AuditRepository
	security *repository.SecurityEventRepository
}

func NewTenantLifecycleService(
	sessions *repository.SessionRepository,
	audit *repository.AuditRepository,
	security *repository.SecurityEventRepository,
) *TenantLifecycleService {
	return &TenantLifecycleService{sessions: sessions, audit: audit, security: security}
}

func (s *TenantLifecycleService) Receive(input map[string]any) (map[string]any, int) {
	eventType := strings.TrimSpace(stringVal(input["event_type"]))
	tenantCode := code.Normalize(stringVal(input["tenant_code"]))
	subtenantCode := code.Normalize(stringVal(input["subtenant_code"]))
	payload, _ := input["payload"].(map[string]any)
	if payload == nil {
		payload = map[string]any{}
	}
	if eventType == "" || tenantCode == "" {
		return map[string]any{"ok": false, "error": "event_type и tenant_code обязательны"}, fiber.StatusUnprocessableEntity
	}

	revokedSessions := 0
	revokedRefresh := 0
	if _, revoke := revokingTenantEvents[eventType]; revoke {
		var scopeSubtenant *string
		if strings.HasPrefix(eventType, "subtenant.") && subtenantCode != "" {
			scopeSubtenant = &subtenantCode
		}
		reason := "tenant_lifecycle:" + eventType
		var err error
		revokedSessions, err = s.sessions.RevokeAllInTenant(tenantCode, scopeSubtenant, reason)
		if err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
		revokedRefresh, err = s.sessions.RevokeAllRefreshInTenant(tenantCode, scopeSubtenant, reason)
		if err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
	}

	auditSubtenant := subtenantCode
	if auditSubtenant == "" {
		auditSubtenant = "all"
	}
	auditPayload := map[string]any{
		"event_type": eventType, "source_payload": payload,
		"revoked_sessions": revokedSessions, "revoked_refresh_tokens": revokedRefresh,
	}
	_ = s.audit.Write("tenant_lifecycle.event.received", nil, tenantCode, auditSubtenant, auditPayload)
	severity := "info"
	if revokedSessions > 0 {
		severity = "warning"
	}
	_ = s.security.Write("tenant_lifecycle.event.processed", nil, tenantCode, auditSubtenant, severity, auditPayload)

	return map[string]any{
		"ok": true, "event_type": eventType,
		"revoked_sessions": revokedSessions, "revoked_refresh_tokens": revokedRefresh,
	}, fiber.StatusOK
}
