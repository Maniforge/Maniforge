// Файл: organization.go
// Назначение: привязка существующего пользователя (телефон) к другой организации — порт PHP UserOrganizationService.
package service

import (
	"database/sql"
	"fmt"
	"strings"
	"time"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/licensingclient"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/versioning"
)

type OrganizationService struct {
	cfg        config.Config
	users      *repository.UserRepository
	roles      *repository.RoleRepository
	invites    *repository.InviteRepository
	pd         *repository.PDRepository
	licensing  *licensingclient.Client
	audit      *repository.AuditRepository
	security   *repository.SecurityEventRepository
	versioning *versioning.Recorder
}

func NewOrganizationService(cfg config.Config, db *sql.DB) *OrganizationService {
	return &OrganizationService{
		cfg:        cfg,
		users:      repository.NewUserRepository(db, cfg),
		roles:      repository.NewRoleRepository(db),
		invites:    repository.NewInviteRepository(db),
		pd:         repository.NewPDRepository(db, cfg),
		licensing:  licensingclient.New(cfg, db),
		audit:      repository.NewAuditRepository(db),
		security:   repository.NewSecurityEventRepository(db, cfg),
		versioning: versioning.NewRecorder(cfg, db),
	}
}

func (s *OrganizationService) AttachByPhone(session *repository.SessionRecord, phone, roleCode, reason string) (map[string]any, int) {
	phone = normalizeAttachPhone(phone)
	roleCode = strings.TrimSpace(roleCode)
	if roleCode == "" {
		roleCode = "user"
	}
	reason = strings.TrimSpace(reason)
	if phone == "" {
		return map[string]any{"ok": false, "error": "phone обязателен"}, fiber.StatusUnprocessableEntity
	}
	if reason == "" {
		return map[string]any{"ok": false, "error": "reason обязателен"}, fiber.StatusUnprocessableEntity
	}
	sources, err := s.activeUsersWithPhone(phone)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if len(sources) == 0 {
		return map[string]any{
			"ok": false, "status": fiber.StatusNotFound, "code": "user_not_found",
			"error": "Пользователь с этим телефоном не найден. Сначала регистрация или invite.",
		}, fiber.StatusNotFound
	}
	_ = s.pd.SeedTenant(session.TenantID, "Organization "+session.TenantID)
	return s.attachUsingSourceUser(sources[0], session.TenantID, session.SubtenantID, roleCode, session.UserID, map[string]any{
		"reason": reason, "flow": "admin_attach",
	})
}

func (s *OrganizationService) AcceptInvite(session *repository.SessionRecord, inviteToken string) (map[string]any, int) {
	inviteToken = strings.TrimSpace(inviteToken)
	if inviteToken == "" {
		return map[string]any{"ok": false, "error": "invite_token обязателен"}, fiber.StatusUnprocessableEntity
	}
	if s.invites.IsConsumedToken(inviteToken) {
		return map[string]any{
			"ok": false, "code": "invite_already_used", "error": "Приглашение уже использовано",
		}, fiber.StatusConflict
	}
	invite, err := s.invites.FindPendingByToken(inviteToken)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if invite == nil {
		return map[string]any{"ok": false, "error": "Приглашение недействительно или истекло"}, fiber.StatusNotFound
	}
	subtenantCode := strings.ToLower(strings.TrimSpace(invite.SubtenantCode.String))
	if subtenantCode == "" {
		return map[string]any{"ok": false, "error": "Приглашение без subtenant"}, fiber.StatusUnprocessableEntity
	}
	roleCode := strings.TrimSpace(invite.RoleCode)
	if roleCode == "" {
		roleCode = "user"
	}
	source, err := s.users.FindByIDInScope(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if source == nil {
		return map[string]any{"ok": false, "error": "Сессия недействительна"}, fiber.StatusUnauthorized
	}
	if strings.TrimSpace(source.Phone) == "" {
		return map[string]any{"ok": false, "error": "У пользователя не задан телефон"}, fiber.StatusUnprocessableEntity
	}
	existing, err := s.users.FindByPhoneInScope(source.Phone, invite.TenantID, subtenantCode)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if existing != nil {
		return map[string]any{
			"ok": false, "code": "already_member", "error": "Вы уже состоите в этой организации",
		}, fiber.StatusConflict
	}
	claimed, err := s.invites.ClaimPendingByToken(inviteToken, subtenantCode)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if claimed == nil {
		return map[string]any{
			"ok": false, "code": "invite_already_used", "error": "Приглашение уже использовано",
		}, fiber.StatusConflict
	}
	_ = s.pd.SeedTenant(invite.TenantID, "Organization "+invite.TenantID)
	result, status := s.attachUsingSourceUser(*source, invite.TenantID, subtenantCode, roleCode, session.UserID, map[string]any{
		"flow": "accept_invite", "invite_id": claimed.ID,
	})
	if ok, _ := result["ok"].(bool); !ok {
		return result, status
	}
	result["tenant"] = map[string]any{
		"tenant_id": invite.TenantID, "subtenant_id": subtenantCode, "invite_id": claimed.ID,
	}
	return result, status
}

func (s *OrganizationService) attachUsingSourceUser(
	source repository.User, tenantID, subtenantID, roleCode string, actorUserID int64, meta map[string]any,
) (map[string]any, int) {
	phone := strings.TrimSpace(source.Phone)
	if phone == "" {
		return map[string]any{"ok": false, "error": "У исходного пользователя нет телефона"}, fiber.StatusUnprocessableEntity
	}
	existing, err := s.users.FindByPhoneInScope(phone, tenantID, subtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if existing != nil {
		return map[string]any{
			"ok": false, "code": "already_member", "error": "Пользователь уже в этой организации",
		}, fiber.StatusConflict
	}
	access := s.licensing.AssertAccess(tenantID, "main", subtenantID)
	if !access.OK {
		status := access.Status
		if status == 0 {
			status = fiber.StatusForbidden
		}
		return map[string]any{"ok": false, "error": access.Error}, status
	}
	activeUsers, err := s.users.CountActiveUsers(tenantID, subtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	quota := s.licensing.AssertUserActivationAllowed(tenantID, subtenantID, activeUsers)
	if !quota.OK {
		status := quota.Status
		if status == 0 {
			status = fiber.StatusPaymentRequired
		}
		return map[string]any{"ok": false, "error": quota.Error}, status
	}
	login, err := s.allocateLoginInScope(phone, tenantID, subtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if source.PasswordHash == "" {
		return map[string]any{"ok": false, "error": "Не удалось скопировать учётные данные"}, fiber.StatusInternalServerError
	}
	email := ""
	if source.Email.Valid {
		email = source.Email.String
	}
	user, err := s.users.CreateUser(repository.CreateUserInput{
		TenantID: tenantID, SubtenantID: subtenantID, Login: login, Email: email,
		Phone: phone, PasswordHash: source.PasswordHash, MFARequired: source.MFARequired, Status: "active",
	})
	if err != nil {
		if isUniqueViolation(err) {
			return map[string]any{"ok": false, "error": "Конфликт уникальности в scope"}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": "Ошибка привязки к организации"}, fiber.StatusInternalServerError
	}
	assignBy := actorUserID
	if assignBy <= 0 {
		assignBy = user.ID
	}
	_ = s.roles.AssignRoleByCode(user.ID, tenantID, subtenantID, roleCode, assignBy)
	actor := assignBy
	payload := map[string]any{
		"target_user_id": user.ID, "phone": phone, "role_code": roleCode, "source_user_id": source.ID,
	}
	for k, v := range meta {
		payload[k] = v
	}
	_ = s.audit.Write("auth.organization.attached", &actor, tenantID, subtenantID, payload)
	_ = s.security.Write("auth.organization.attached", &user.ID, tenantID, subtenantID, "info", map[string]any{
		"role_code": roleCode,
	})
	if s.versioning != nil {
		s.versioning.Record(versioning.Scope{
			TenantID: tenantID, SubtenantID: subtenantID, ActorUserID: actor,
		}, "maniforge_users", fmt.Sprint(user.ID), "insert", nil, repository.PublicUser(*user), login)
	}
	return map[string]any{
		"ok": true, "status": fiber.StatusCreated,
		"user": repository.PublicUser(*user), "role_code": roleCode,
	}, fiber.StatusCreated
}

func (s *OrganizationService) activeUsersWithPhone(phone string) ([]repository.User, error) {
	all, err := s.users.FindAllByPhone(phone)
	if err != nil {
		return nil, err
	}
	var out []repository.User
	for _, u := range all {
		if u.Status == "active" {
			out = append(out, u)
		}
	}
	return out, nil
}

func (s *OrganizationService) allocateLoginInScope(phone, tenantID, subtenantID string) (string, error) {
	digits := digitsOnly(phone)
	base := "u" + digits
	if digits == "" {
		base = fmt.Sprintf("u%x", time.Now().UnixNano())
	}
	if len(base) > 64 {
		base = base[:64]
	}
	candidate := strings.ToLower(base)
	for suffix := 2; ; suffix++ {
		found, err := s.users.FindByLogin(tenantID, subtenantID, candidate)
		if err != nil {
			return "", err
		}
		if found == nil {
			return candidate, nil
		}
		tail := fmt.Sprintf("_%d", suffix)
		keep := 64 - len(tail)
		if keep < 3 {
			keep = 3
		}
		if keep > len(base) {
			keep = len(base)
		}
		candidate = base[:keep] + tail
	}
}

func normalizeAttachPhone(phone string) string {
	digits := digitsOnly(phone)
	if digits == "" {
		return ""
	}
	return "+" + digits
}
