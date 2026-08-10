// Файл: admin.go
// Назначение: admin API — users, roles, policies, ops-summary, invites.
// См. также: repository/audit.go, handler/admin.go
package service

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net/mail"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/licensingclient"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/security"
	"maniforge/internal/versioning"
)

type AdminService struct {
	cfg        config.Config
	invites    *repository.InviteRepository
	licensing  *licensingclient.Client
	versioning *versioning.Recorder
	users      *repository.UserRepository
	roles      *repository.RoleRepository
	sessions   *repository.SessionRepository
	audit      *repository.AuditRepository
	security   *repository.SecurityEventRepository
	policies   *PolicyService
	policyRepo *repository.PolicyRuleRepository
	rbac       *RbacService
	userAdmin  *UserAdminService
	roleAdmin  *RoleAdminService
}

func NewAdminService(
	cfg config.Config,
	invites *repository.InviteRepository,
	licensing *licensingclient.Client,
	rec *versioning.Recorder,
	users *repository.UserRepository,
	roles *repository.RoleRepository,
	sessions *repository.SessionRepository,
	audit *repository.AuditRepository,
	securityEvents *repository.SecurityEventRepository,
	policies *PolicyService,
	policyRepo *repository.PolicyRuleRepository,
	rbac *RbacService,
	userAdmin *UserAdminService,
	roleAdmin *RoleAdminService,
) *AdminService {
	return &AdminService{
		cfg: cfg, invites: invites, licensing: licensing, versioning: rec,
		users: users, roles: roles, sessions: sessions, audit: audit, security: securityEvents,
		policies: policies, policyRepo: policyRepo, rbac: rbac, userAdmin: userAdmin, roleAdmin: roleAdmin,
	}
}

func (s *AdminService) CreateRegistrationInvite(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	inviteType := strings.ToLower(strings.TrimSpace(stringVal(input["invite_type"])))
	if inviteType == "" {
		inviteType = strings.ToLower(strings.TrimSpace(stringVal(input["type"])))
	}
	if inviteType == "" {
		inviteType = "user"
	}
	if inviteType != "user" {
		return map[string]any{"ok": false, "error": "subtenant invite пока не портирован"}, fiber.StatusNotImplemented
	}

	decision := s.licensing.AssertAccess(session.TenantID, "main", session.SubtenantID)
	if !decision.OK {
		status := decision.Status
		if status == 0 {
			status = fiber.StatusForbidden
		}
		return map[string]any{"ok": false, "error": decision.Error}, status
	}

	roleCode := strings.TrimSpace(stringVal(input["role_code"]))
	if roleCode == "" {
		roleCode = s.cfg.RBACRegistrationDefaultRole
	}
	if roleCode == "" {
		roleCode = "user"
	}

	ttlHours := s.cfg.RBACRegistrationInviteTTLHours
	if ttlHours < 1 {
		ttlHours = 168
	}
	rawToken, err := randomHex(24)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	expiresAt := time.Now().UTC().Add(time.Duration(ttlHours) * time.Hour)

	invite, err := s.invites.CreateUserInvite(
		session.TenantID, session.SubtenantID, roleCode,
		rawToken, expiresAt, session.UserID,
		map[string]any{"flow": "user_invite"},
	)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	registerURL := strings.TrimRight(s.cfg.AppURL, "/") + "/register?invite=" + rawToken
	invitePayload := map[string]any{
		"id": invite.ID, "tenant_id": invite.TenantID, "subtenant_id": invite.SubtenantID,
		"role_code": invite.RoleCode, "expires_at": expiresAt.Format("2006-01-02 15:04:05"),
	}
	s.recordVersion(session, "maniforge_registration_invites", fmt.Sprint(invite.ID), "insert", nil, invitePayload, rawToken[:8])

	return map[string]any{
		"ok": true, "status": fiber.StatusCreated,
		"invite_type": "user", "invite_token": rawToken, "register_url": registerURL,
		"invite": invitePayload,
	}, fiber.StatusCreated
}

func (s *AdminService) ListUsers(session *repository.SessionRecord, limit int) (map[string]any, int) {
	if limit < 1 {
		limit = 50
	}
	users, err := s.users.ListUsers(session.TenantID, session.SubtenantID, limit)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	items := make([]map[string]any, 0, len(users))
	for _, u := range users {
		items = append(items, repository.AdminUser(u))
	}
	return map[string]any{"ok": true, "items": items}, fiber.StatusOK
}

func (s *AdminService) CreateUser(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	login := normalizeLogin(stringVal(input["login"]))
	email := strings.TrimSpace(stringVal(input["email"]))
	phone := strings.TrimSpace(stringVal(input["phone"]))
	password := stringVal(input["password"])
	status := strings.TrimSpace(stringVal(input["status"]))
	reason := strings.TrimSpace(stringVal(input["reason"]))
	mfaRequired := boolVal(input["mfa_required"])

	if login == "" || phone == "" || password == "" || reason == "" {
		return map[string]any{"ok": false, "error": "login, phone, password и reason обязательны"}, fiber.StatusUnprocessableEntity
	}
	if status == "" {
		status = "active"
	}
	if email != "" {
		if _, err := mail.ParseAddress(email); err != nil {
			return map[string]any{"ok": false, "error": "Некорректный email"}, fiber.StatusUnprocessableEntity
		}
	}
	if !repository.ValidatePhone(phone) {
		return map[string]any{"ok": false, "error": "Некорректный phone"}, fiber.StatusUnprocessableEntity
	}
	if !s.userAdmin.IsAllowedStatus(status) {
		return map[string]any{"ok": false, "error": "Некорректный status"}, fiber.StatusUnprocessableEntity
	}
	if status == "active" {
		if payload, statusCode := s.guardUserActivationQuota(session.TenantID, session.SubtenantID); statusCode != 0 {
			return payload, statusCode
		}
	}

	hash, err := security.HashPassword(password)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	user, err := s.users.CreateUser(repository.CreateUserInput{
		TenantID: session.TenantID, SubtenantID: session.SubtenantID,
		Login: login, Email: email, Phone: phone, PasswordHash: hash,
		MFARequired: mfaRequired, Status: status,
	})
	if err != nil {
		if isUniqueViolation(err) {
			return map[string]any{"ok": false, "error": "Пользователь с таким login/email уже существует в scope"}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": "Ошибка создания пользователя"}, fiber.StatusInternalServerError
	}

	actor := session.UserID
	_ = s.audit.Write("admin.users.create", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"target_user_id": user.ID, "login": login, "status": status, "reason": reason,
	})
	s.recordVersion(session, "maniforge_users", fmt.Sprint(user.ID), "insert", nil, repository.AdminUser(*user), login)

	return map[string]any{"ok": true, "user": repository.AdminUser(*user)}, fiber.StatusCreated
}

func (s *AdminService) AssignUserRole(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	targetUserID := int64Val(input["user_id"])
	roleCode := strings.TrimSpace(stringVal(input["role_code"]))
	reason := strings.TrimSpace(stringVal(input["reason"]))
	if targetUserID <= 0 || roleCode == "" || reason == "" {
		return map[string]any{"ok": false, "error": "user_id, role_code и reason обязательны"}, fiber.StatusUnprocessableEntity
	}
	if !s.targetUserExistsInScope(targetUserID, session) {
		return map[string]any{"ok": false, "error": "Пользователь не найден в текущем контуре"}, fiber.StatusNotFound
	}
	guard := s.roleAdmin.GuardRoleMutation(session.UserID, targetUserID, roleCode, "assign", session.TenantID, session.SubtenantID)
	if guard["ok"] == false {
		return map[string]any{"ok": false, "error": guard["error"]}, fiber.StatusForbidden
	}
	if !s.roles.AssignRoleByCode(targetUserID, session.TenantID, session.SubtenantID, roleCode, session.UserID) {
		return map[string]any{"ok": false, "error": "Роль не найдена"}, fiber.StatusNotFound
	}
	actor := session.UserID
	_ = s.audit.Write("admin.user_roles.assign", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"target_user_id": targetUserID, "role_code": roleCode, "reason": reason,
	})
	s.recordVersion(session, "maniforge_user_roles", fmt.Sprintf("%d:%s", targetUserID, roleCode), "insert", nil,
		map[string]any{"user_id": targetUserID, "role_code": roleCode}, roleCode)
	_ = s.security.Write("admin.user_role.assigned", &actor, session.TenantID, session.SubtenantID, "warning", map[string]any{
		"target_user_id": targetUserID, "role_code": roleCode, "reason": reason,
	})
	return map[string]any{"ok": true, "assigned": true}, fiber.StatusOK
}

func (s *AdminService) ListUserRoles(session *repository.SessionRecord, targetUserID int64) (map[string]any, int) {
	if targetUserID <= 0 {
		return map[string]any{"ok": false, "error": "user_id обязателен"}, fiber.StatusUnprocessableEntity
	}
	items, err := s.roles.ListUserRoles(targetUserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "user_id": targetUserID, "items": items}, fiber.StatusOK
}

func (s *AdminService) EffectiveAccess(session *repository.SessionRecord, targetUserID int64) (map[string]any, int) {
	if targetUserID <= 0 {
		return map[string]any{"ok": false, "error": "user_id обязателен"}, fiber.StatusUnprocessableEntity
	}
	access, err := s.rbac.EffectiveAccess(targetUserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "user_id": targetUserID, "access": access}, fiber.StatusOK
}

func (s *AdminService) GetPolicies(session *repository.SessionRecord) (map[string]any, int) {
	rules := s.policies.GetEffectiveAdminRules(session.TenantID, session.SubtenantID)
	actor := session.UserID
	_ = s.audit.Write("admin.policies.read", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"source": rules["source"],
	})
	return map[string]any{"ok": true, "rules": rules}, fiber.StatusOK
}

func (s *AdminService) UpdatePolicies(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	reason := strings.TrimSpace(stringVal(input["reason"]))
	allowedIPsRaw := input["allowed_ips"]
	hourStart := intVal(input["allowed_hour_start_utc"], -1)
	hourEnd := intVal(input["allowed_hour_end_utc"], -1)
	requireStepUp := boolValDefault(input["require_step_up"], true)
	requireMFA := false
	if _, hasRequireMFA := input["require_mfa_enrollment"]; hasRequireMFA {
		requireMFA = boolValDefault(input["require_mfa_enrollment"], false)
	} else {
		effective := s.policies.GetEffectiveAdminRules(session.TenantID, session.SubtenantID)
		requireMFA, _ = effective["require_mfa_enrollment"].(bool)
	}

	if reason == "" {
		return map[string]any{"ok": false, "error": "reason обязателен"}, fiber.StatusUnprocessableEntity
	}
	allowedIPs, ok := parseStringSlice(allowedIPsRaw)
	if !ok {
		return map[string]any{"ok": false, "error": "allowed_ips должен быть массивом"}, fiber.StatusUnprocessableEntity
	}
	if hourStart < 0 || hourStart > 23 || hourEnd < 0 || hourEnd > 23 || hourStart > hourEnd {
		return map[string]any{"ok": false, "error": "Некорректное окно allowed_hour_start_utc/allowed_hour_end_utc"}, fiber.StatusUnprocessableEntity
	}
	if !requireStepUp {
		isSuper, _ := s.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{"super_admin"})
		if !isSuper {
			return map[string]any{"ok": false, "error": "Отключать step-up может только super_admin"}, fiber.StatusForbidden
		}
	}

	var normalized []string
	seen := map[string]struct{}{}
	for _, ip := range allowedIPs {
		v := strings.TrimSpace(ip)
		if v == "" {
			continue
		}
		if !isValidIP(v) {
			return map[string]any{"ok": false, "error": fmt.Sprintf("Некорректный IP: %s", v)}, fiber.StatusUnprocessableEntity
		}
		if _, exists := seen[v]; exists {
			continue
		}
		seen[v] = struct{}{}
		normalized = append(normalized, v)
	}

	before := s.policies.GetEffectiveAdminRules(session.TenantID, session.SubtenantID)
	if err := s.policyRepo.UpsertForScope(
		session.TenantID, session.SubtenantID, normalized, hourStart, hourEnd, requireStepUp, requireMFA, session.UserID,
	); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	effective := s.policies.GetEffectiveAdminRules(session.TenantID, session.SubtenantID)
	s.recordVersion(session, "maniforge_policy_rules", session.TenantID+":"+session.SubtenantID, "update", before, effective, "admin-policy")

	actor := session.UserID
	_ = s.audit.Write("admin.policies.update", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"reason": reason,
		"rules": map[string]any{
			"allowed_ips": effective["allowed_ips"], "allowed_hour_start_utc": effective["allowed_hour_start_utc"],
			"allowed_hour_end_utc": effective["allowed_hour_end_utc"], "require_step_up": effective["require_step_up"],
			"require_mfa_enrollment": effective["require_mfa_enrollment"],
		},
	})
	_ = s.security.Write("admin.policies.updated", &actor, session.TenantID, session.SubtenantID, "warning", map[string]any{
		"reason": reason, "source": effective["source"],
	})
	return map[string]any{"ok": true, "rules": effective}, fiber.StatusOK
}

func (s *AdminService) OpsSummary(session *repository.SessionRecord) (map[string]any, int) {
	users, err := s.users.ListUsers(session.TenantID, session.SubtenantID, 500)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	activeUsers := 0
	for _, u := range users {
		if u.Status == "active" {
			activeUsers++
		}
	}
	auditItems, _ := s.audit.ListByScope(session.TenantID, session.SubtenantID, 50)
	securityItems, _ := s.security.ListByScope(session.TenantID, session.SubtenantID, 50)
	sessionsActive, _ := s.sessions.CountActiveInScope(session.TenantID, session.SubtenantID)

	return map[string]any{
		"ok": true,
		"summary": map[string]any{
			"tenant_id": session.TenantID, "subtenant_id": session.SubtenantID,
			"users_total": len(users), "users_active": activeUsers,
			"sessions_active": sessionsActive,
			"audit_recent": len(auditItems), "security_events_recent": len(securityItems),
			"step_up_required": s.policies.RequiresStepUp(session.TenantID, session.SubtenantID),
			"action_token_configured": strings.TrimSpace(os.Getenv("RBAC_ACTION_TOKEN_TTL_SEC")) != "" || s.cfg.RBACActionTokenTTLSec > 0,
			"checked_at": time.Now().UTC().Format("2006-01-02 15:04:05"),
		},
	}, fiber.StatusOK
}

func (s *AdminService) BatchUserStatus(session *repository.SessionRecord, input map[string]any) (map[string]any, int) {
	reason := strings.TrimSpace(stringVal(input["reason"]))
	itemsRaw, ok := input["items"].([]any)
	dryRun := boolVal(input["dry_run"])
	if reason == "" || !ok || len(itemsRaw) == 0 {
		return map[string]any{"ok": false, "error": "reason и непустой items[] обязательны"}, fiber.StatusUnprocessableEntity
	}
	maxItems := envIntLocal("RBAC_BATCH_MAX_ITEMS", 100)
	if len(itemsRaw) > maxItems {
		return map[string]any{"ok": false, "error": fmt.Sprintf("Слишком большой batch, максимум %d", maxItems)}, fiber.StatusUnprocessableEntity
	}

	items, itemErr := parseStatusBatchItems(itemsRaw)
	if itemErr.msg != "" {
		return map[string]any{"ok": false, "error": itemErr.msg, "item_index": itemErr.index}, fiber.StatusUnprocessableEntity
	}

	if dryRun {
		summary := s.userAdmin.SimulateStatusBatchSummary(session.TenantID, session.SubtenantID, items)
		actor := session.UserID
		_ = s.audit.Write("admin.users.batch_status.dry_run", &actor, session.TenantID, session.SubtenantID, map[string]any{
			"reason": reason, "summary": summary,
		})
		return map[string]any{"ok": true, "dry_run": true, "summary": summary}, fiber.StatusOK
	}

	summary, err := s.users.ApplyStatusBatchInScope(session.TenantID, session.SubtenantID, items)
	if err != nil {
		return map[string]any{"ok": false, "error": "Ошибка batch user status update"}, fiber.StatusInternalServerError
	}
	revokedSessions := 0
	for _, item := range items {
		if item.Status != "locked" && item.Status != "disabled" {
			continue
		}
		if s.targetUserExistsInScope(item.UserID, session) {
			n, _ := s.sessions.RevokeAllForUser(item.UserID, "user_status_changed:"+item.Status)
			revokedSessions += n
		}
	}
	summary.RevokedSessions = revokedSessions

	actor := session.UserID
	_ = s.audit.Write("admin.users.batch_status", &actor, session.TenantID, session.SubtenantID, map[string]any{
		"reason": reason, "summary": summary,
	})
	_ = s.security.Write("admin.users.batch_status.updated", &actor, session.TenantID, session.SubtenantID, "warning", map[string]any{
		"reason": reason, "summary": summary,
	})
	return map[string]any{"ok": true, "summary": summary}, fiber.StatusOK
}

func (s *AdminService) guardUserActivationQuota(tenantID, subtenantID string) (map[string]any, int) {
	activeUsers, err := s.users.CountActiveUsers(tenantID, subtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	decision := s.licensing.AssertUserActivationAllowed(tenantID, subtenantID, activeUsers)
	if decision.OK {
		return nil, 0
	}
	status := decision.Status
	if status == 0 {
		status = fiber.StatusPaymentRequired
	}
	return map[string]any{
		"ok": false, "error": decision.Error, "deny_reason": decision.DenyReason,
	}, status
}

func (s *AdminService) targetUserExistsInScope(userID int64, session *repository.SessionRecord) bool {
	status, err := s.users.FindStatusInScope(userID, session.TenantID, session.SubtenantID)
	return err == nil && status != nil
}

func (s *AdminService) recordVersion(session *repository.SessionRecord, table, entityID, action string, before, after any, label string) {
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
	}, table, entityID, action, asVersionMap(before), asVersionMap(after), label)
}

func asVersionMap(v any) map[string]any {
	if v == nil {
		return nil
	}
	if m, ok := v.(map[string]any); ok {
		return m
	}
	return map[string]any{"value": v}
}

func normalizeLogin(login string) string {
	return strings.ToLower(strings.TrimSpace(login))
}

func randomHex(n int) (string, error) {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

func boolVal(v any) bool {
	switch t := v.(type) {
	case bool:
		return t
	case string:
		return envBoolValue(t)
	default:
		return false
	}
}

func boolValDefault(v any, def bool) bool {
	if v == nil {
		return def
	}
	switch t := v.(type) {
	case bool:
		return t
	case string:
		if t == "" {
			return def
		}
		return envBoolValue(t)
	default:
		return def
	}
}

func envBoolValue(v string) bool {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "1", "true", "yes", "on":
		return true
	default:
		return false
	}
}

func intVal(v any, def int) int {
	switch t := v.(type) {
	case float64:
		return int(t)
	case int:
		return t
	case int64:
		return int(t)
	case string:
		n, err := strconv.Atoi(strings.TrimSpace(t))
		if err != nil {
			return def
		}
		return n
	default:
		return def
	}
}

func int64Val(v any) int64 {
	switch t := v.(type) {
	case float64:
		return int64(t)
	case int:
		return int64(t)
	case int64:
		return t
	default:
		return 0
	}
}

func parseStringSlice(v any) ([]string, bool) {
	raw, ok := v.([]any)
	if !ok {
		return nil, false
	}
	out := make([]string, 0, len(raw))
	for _, item := range raw {
		out = append(out, stringVal(item))
	}
	return out, true
}

type batchItemError struct {
	msg   string
	index int
}

func (e batchItemError) Error() string { return e.msg }

func parseStatusBatchItems(raw []any) ([]repository.StatusBatchItem, batchItemError) {
	items := make([]repository.StatusBatchItem, 0, len(raw))
	svc := NewUserAdminService(nil)
	for index, entry := range raw {
		m, ok := entry.(map[string]any)
		if !ok {
			return nil, batchItemError{msg: "Неверный элемент batch", index: index}
		}
		userID := int64Val(m["user_id"])
		status := strings.TrimSpace(stringVal(m["status"]))
		if userID <= 0 || !svc.IsAllowedStatus(status) {
			return nil, batchItemError{msg: "Неверный элемент batch", index: index}
		}
		items = append(items, repository.StatusBatchItem{UserID: userID, Status: status})
	}
	return items, batchItemError{}
}

func isUniqueViolation(err error) bool {
	if err == nil {
		return false
	}
	msg := strings.ToLower(err.Error())
	return strings.Contains(msg, "duplicate key") || strings.Contains(msg, "unique constraint")
}

func envIntLocal(key string, def int) int {
	return envInt(key, def)
}
