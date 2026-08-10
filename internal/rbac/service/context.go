// Файл: context.go
// Назначение: /me/contexts и POST /auth/switch-context — home/delegated контексты.
// См. также: app/Maniforge/Rbac/Security/ContextService.php
package service

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/code"
	"maniforge/internal/rbac/repository"
)

type ContextService struct {
	users    *repository.UserRepository
	sessions *repository.SessionRepository
	grants   *repository.DelegationRepository
	audit    *repository.AuditRepository
	db       *sql.DB
}

func NewContextService(cfg config.Config, db *sql.DB) *ContextService {
	return &ContextService{
		users:    repository.NewUserRepository(db, cfg),
		sessions: repository.NewSessionRepository(db),
		grants:   repository.NewDelegationRepository(db),
		audit:    repository.NewAuditRepository(db),
		db:       db,
	}
}

func (s *ContextService) ContextsForSession(session *repository.SessionRecord) (map[string]any, int) {
	user, err := s.resolveUserForSession(session)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if user == nil {
		return map[string]any{"ok": false, "error": "Пользователь не найден"}, fiber.StatusNotFound
	}
	if user.Phone == "" {
		return map[string]any{"ok": false, "error": "У пользователя не задан телефон"}, fiber.StatusUnprocessableEntity
	}

	home, err := s.homeContexts(user.Phone)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	delegated, err := s.delegatedContextsForPhone(user.Phone)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	currentTenant := session.TenantID
	currentSubtenant := session.SubtenantID
	kind := s.contextKind(currentTenant, currentSubtenant, home, delegated)

	var projectID any
	if session.ProjectID.Valid {
		projectID = session.ProjectID.Int64
	}
	current := map[string]any{
		"tenant_id": currentTenant, "subtenant_id": currentSubtenant,
		"project_id": projectID, "kind": kind,
	}

	principalTenant := s.resolvePrincipalTenantID(user.Phone, currentTenant, currentSubtenant, home, delegated)
	if delegation := s.delegationForScope(user.Phone, principalTenant, currentTenant, currentSubtenant, home, delegated); delegation != nil {
		for k, v := range delegation {
			current[k] = v
		}
	}

	orgs := buildOrganizations(home, delegated, currentTenant, currentSubtenant)

	return map[string]any{
		"ok": true, "status": fiber.StatusOK,
		"current": current, "home": home, "delegated": delegated,
		"organizations": orgs, "projects": []any{}, "project_options": []any{},
	}, fiber.StatusOK
}

func (s *ContextService) SwitchContext(session *repository.SessionRecord, tenantID, subtenantID string) (map[string]any, int) {
	tenantID = code.Normalize(tenantID)
	subtenantID = code.Normalize(subtenantID)
	if tenantID == "" || subtenantID == "" {
		return map[string]any{"ok": false, "error": "tenant_id и subtenant_id обязательны"}, fiber.StatusUnprocessableEntity
	}

	if session.TenantID == tenantID && session.SubtenantID == subtenantID {
		return map[string]any{
			"ok": true, "status": fiber.StatusOK,
			"session": map[string]any{
				"tenant_id": tenantID, "subtenant_id": subtenantID, "unchanged": true,
			},
		}, fiber.StatusOK
	}

	user, err := s.resolveUserForSession(session)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if user == nil {
		return map[string]any{"ok": false, "error": "Пользователь не найден"}, fiber.StatusNotFound
	}
	if user.Phone == "" {
		return map[string]any{"ok": false, "error": "У пользователя не задан телефон"}, fiber.StatusUnprocessableEntity
	}

	home, err := s.homeContexts(user.Phone)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	delegated, err := s.delegatedContextsForPhone(user.Phone)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	allowed := s.isContextAllowed(user.Phone, tenantID, subtenantID, home, delegated)
	if !allowed["ok"].(bool) {
		msg, _ := allowed["error"].(string)
		if msg == "" {
			msg = "Контекст недоступен"
		}
		return map[string]any{"ok": false, "error": msg}, fiber.StatusForbidden
	}

	previousTenant := session.TenantID
	previousSubtenant := session.SubtenantID
	rebound, err := s.sessions.RebindScope(session.ID, tenantID, subtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if !rebound {
		return map[string]any{"ok": false, "error": "Не удалось переключить контекст сессии"}, fiber.StatusInternalServerError
	}

	defaultProjectID, err := repository.DefaultProjectID(s.db, tenantID, subtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if defaultProjectID.Valid {
		_, _ = s.sessions.RebindProject(session.ID, defaultProjectID)
	}

	principalTenant := s.resolvePrincipalTenantID(user.Phone, tenantID, subtenantID, home, delegated)
	delegation := s.delegationForScope(user.Phone, principalTenant, tenantID, subtenantID, home, delegated)

	actor := session.UserID
	_ = s.audit.Write("auth.context_switch", &actor, tenantID, subtenantID, map[string]any{
		"previous_tenant_id":    previousTenant,
		"previous_subtenant_id": previousSubtenant,
		"kind":                  allowed["kind"],
		"grant_level":           allowed["grant_level"],
		"principal_tenant_id":   principalTenant,
		"delegated":             delegation != nil && delegation["delegated"] == true,
	})

	sessionPayload := map[string]any{
		"tenant_id": tenantID, "subtenant_id": subtenantID,
		"kind": allowed["kind"], "delegated": false,
		"principal_tenant_id": principalTenant,
	}
	if defaultProjectID.Valid {
		sessionPayload["project_id"] = defaultProjectID.Int64
		sessionPayload["project_code"] = repository.ProjectCodeForSession(s.db, tenantID, defaultProjectID)
	} else {
		sessionPayload["project_id"] = nil
		sessionPayload["project_code"] = nil
	}
	if allowed["grant_level"] != nil {
		sessionPayload["grant_level"] = allowed["grant_level"]
	}
	if delegation != nil {
		for k, v := range delegation {
			sessionPayload[k] = v
		}
	}

	return map[string]any{"ok": true, "status": fiber.StatusOK, "session": sessionPayload}, fiber.StatusOK
}

func (s *ContextService) IsContextAllowed(phone, tenantID, subtenantID string) map[string]any {
	home, err := s.homeContexts(phone)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}
	}
	delegated, err := s.delegatedContextsForPhone(phone)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}
	}
	return s.isContextAllowed(phone, tenantID, subtenantID, home, delegated)
}

func (s *ContextService) resolveUserForSession(session *repository.SessionRecord) (*repository.User, error) {
	user, err := s.users.FindByIDInScope(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return nil, err
	}
	if user != nil {
		return user, nil
	}
	return s.users.FindByID(session.UserID)
}

func (s *ContextService) homeContexts(phone string) ([]map[string]any, error) {
	matches, err := s.users.FindAllByPhone(phone)
	if err != nil {
		return nil, err
	}
	matches = repository.FilterActiveUsers(matches)
	seen := map[string]struct{}{}
	var home []map[string]any
	for _, u := range matches {
		key := u.TenantID + ":" + u.SubtenantID
		if _, ok := seen[key]; ok {
			continue
		}
		seen[key] = struct{}{}
		home = append(home, map[string]any{
			"tenant_id": u.TenantID, "subtenant_id": u.SubtenantID, "kind": "home",
		})
	}
	return home, nil
}

func (s *ContextService) delegatedContextsForPhone(phone string) ([]map[string]any, error) {
	items, err := s.grants.DelegatedContextsForPhone(phone)
	if err != nil {
		return nil, err
	}
	var delegated []map[string]any
	for _, ctx := range items {
		delegated = append(delegated, map[string]any{
			"tenant_id": ctx.TenantID, "subtenant_id": ctx.SubtenantID, "kind": "delegated",
			"grant_level": ctx.GrantLevel, "principal_tenant_id": ctx.PrincipalTenantID,
		})
	}
	return delegated, nil
}

func (s *ContextService) isContextAllowed(phone, tenantID, subtenantID string, home, delegated []map[string]any) map[string]any {
	_ = phone
	for _, ctx := range home {
		if ctx["tenant_id"] == tenantID && ctx["subtenant_id"] == subtenantID {
			return map[string]any{"ok": true, "kind": "home"}
		}
	}
	for _, ctx := range delegated {
		if ctx["tenant_id"] == tenantID && ctx["subtenant_id"] == subtenantID {
			return map[string]any{
				"ok": true, "kind": "delegated", "grant_level": ctx["grant_level"],
			}
		}
	}
	return map[string]any{"ok": false, "error": "Контекст не входит в home/delegated для пользователя"}
}

func (s *ContextService) delegationForScope(
	phone, principalTenant, tenantID, subtenantID string,
	home, delegated []map[string]any,
) map[string]any {
	_ = phone
	for _, ctx := range home {
		if ctx["tenant_id"] == tenantID && ctx["subtenant_id"] == subtenantID {
			return map[string]any{"delegated": false, "principal_tenant_id": principalTenant}
		}
	}
	for _, ctx := range delegated {
		if ctx["tenant_id"] == tenantID && ctx["subtenant_id"] == subtenantID {
			level, _ := ctx["grant_level"].(string)
			principal, _ := ctx["principal_tenant_id"].(string)
			if principal == "" {
				principal = principalTenant
			}
			return map[string]any{
				"delegated": true, "grant_level": level, "principal_tenant_id": principal,
			}
		}
	}
	return nil
}

func (s *ContextService) resolvePrincipalTenantID(
	phone, tenantID, subtenantID string,
	home, delegated []map[string]any,
) string {
	for _, ctx := range delegated {
		if ctx["tenant_id"] == tenantID && ctx["subtenant_id"] == subtenantID {
			principal, _ := ctx["principal_tenant_id"].(string)
			return principal
		}
	}
	for _, ctx := range home {
		if ctx["tenant_id"] == tenantID && ctx["subtenant_id"] == subtenantID {
			tid, _ := ctx["tenant_id"].(string)
			return tid
		}
	}
	principals, err := s.grants.PrincipalTenantsForPhone(phone)
	if err != nil || len(principals) == 0 {
		return tenantID
	}
	return principals[0]
}

func (s *ContextService) contextKind(tenantID, subtenantID string, home, delegated []map[string]any) string {
	for _, ctx := range home {
		if ctx["tenant_id"] == tenantID && ctx["subtenant_id"] == subtenantID {
			return "home"
		}
	}
	for _, ctx := range delegated {
		if ctx["tenant_id"] == tenantID && ctx["subtenant_id"] == subtenantID {
			return "delegated"
		}
	}
	return "session"
}

func buildOrganizations(home []map[string]any, delegated []map[string]any, currentTenant, currentSubtenant string) []map[string]any {
	type org struct {
		tenantID string
		name     string
	}
	byTenant := map[string]*org{}
	for _, ctx := range append(home, delegated...) {
		tid, _ := ctx["tenant_id"].(string)
		if tid == "" {
			continue
		}
		if _, ok := byTenant[tid]; !ok {
			byTenant[tid] = &org{tenantID: tid, name: tid}
		}
	}
	var orgs []map[string]any
	for _, o := range byTenant {
		workspaces := []map[string]any{}
		for _, ctx := range append(home, delegated...) {
			tid, _ := ctx["tenant_id"].(string)
			sid, _ := ctx["subtenant_id"].(string)
			if tid != o.tenantID {
				continue
			}
			workspaces = append(workspaces, map[string]any{
				"subtenant_id": sid,
				"label":        sid,
				"is_current":   tid == currentTenant && sid == currentSubtenant,
			})
		}
		orgs = append(orgs, map[string]any{
			"tenant_id": o.tenantID, "name": o.name, "workspaces": workspaces,
		})
	}
	return orgs
}
