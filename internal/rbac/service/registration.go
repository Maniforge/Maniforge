// Файл: registration.go
// Назначение: self-reg нового tenant, invite-flow, bootstrap TL + projects + roles.
// Зависимости: users, invites, pd, projects, tenantlicensing write.
// См. также: handler/auth.go, repository/user.go
package service

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"fmt"
	"net/mail"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/licensingclient"
	"maniforge/internal/platform/code"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/security"
	tlrepo "maniforge/internal/tenantlicensing/repository"
	"maniforge/internal/versioning"
)

var loginPattern = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{2,63}$`)

type RegistrationService struct {
	cfg       config.Config
	users     *repository.UserRepository
	roles     *repository.RoleRepository
	licensing *licensingclient.Client
	tl        *tlrepo.Repository
	invites   *repository.InviteRepository
	pd         *repository.PDRepository
	projects   *repository.ProjectRepository
	versioning *versioning.Recorder
}

func NewRegistrationService(cfg config.Config, db *sql.DB) *RegistrationService {
	return &RegistrationService{
		cfg:       cfg,
		users:     repository.NewUserRepository(db, cfg),
		roles:     repository.NewRoleRepository(db),
		licensing: licensingclient.New(cfg, db),
		tl:        tlrepo.New(db),
		invites:   repository.NewInviteRepository(db),
		pd:         repository.NewPDRepository(db, cfg),
		projects:   repository.NewProjectRepository(db),
		versioning: versioning.NewRecorder(cfg, db),
	}
}

type RegisterInput struct {
	Phone                string                   `json:"phone"`
	PhonePrefix          string                   `json:"phone_prefix"`
	PhoneNumber          string                   `json:"phone_number"`
	PhoneLocal           string                   `json:"phone_local"`
	Email                string                   `json:"email"`
	Password             string                   `json:"password"`
	InviteToken          string                   `json:"invite_token"`
	TenantID             string                   `json:"tenant_id"`
	SubtenantID          string                   `json:"subtenant_id"`
	Flow                 string                   `json:"flow"`
	OrganizationName     string                   `json:"organization_name"`
	Organization         string                   `json:"organization"`
	PlatformDpaAccepted  bool                     `json:"platform_dpa_accepted"`
	Consents             []repository.ConsentItem `json:"consents"`
}

func (s *RegistrationService) Register(c *fiber.Ctx, input RegisterInput) (map[string]any, int) {
	if !s.cfg.RBACRegistrationEnabled {
		return map[string]any{"ok": false, "error": "Самостоятельная регистрация отключена"}, fiber.StatusForbidden
	}

	phone := s.resolvePhone(input)
	email := s.normalizeEmail(input.Email)
	password := input.Password
	inviteToken := strings.TrimSpace(input.InviteToken)

	if fieldErr := s.validateCommonFields(phone, password, email); fieldErr != nil {
		return fieldErr, int(fieldErr["status"].(int))
	}

	if inviteToken != "" {
		return s.registerViaInvite(c, inviteToken, email, phone, password, input)
	}

	if s.attemptedManualScopeJoin(input) {
		return map[string]any{
			"ok":    false,
			"error": "Подключение к существующему workspace доступно только по ссылке-приглашению",
		}, fiber.StatusForbidden
	}

	return s.registerNewTenant(c, input, email, phone, password)
}

func (s *RegistrationService) registerNewTenant(c *fiber.Ctx, input RegisterInput, email, phone, password string) (map[string]any, int) {
	if conflict := s.rejectIfPhoneAlreadyRegistered(phone); conflict != nil {
		return conflict, int(conflict["status"].(int))
	}

	tenantID, err := randomCode("t-")
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	orgName := strings.TrimSpace(input.OrganizationName)
	if orgName == "" {
		orgName = strings.TrimSpace(input.Organization)
	}
	tenantName := orgName
	if tenantName == "" {
		digits := digitsOnly(phone)
		if digits == "" {
			tenantName = "Workspace user"
		} else {
			tenantName = "Workspace " + digits
		}
	}

	subtenantCode := code.Normalize(s.cfg.RBACRegistrationDefaultSubtenantID)
	subtenantName := s.cfg.RBACRegistrationDefaultSubtenantName
	actor := "self_registration"

	if res := s.tl.CreateTenant(tenantID, tenantName, actor, map[string]any{"source": "self_registration"}); !res.OK {
		return map[string]any{"ok": false, "error": res.Error}, res.Status
	}
	if err := s.pd.SeedTenant(tenantID, tenantName); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	if res := s.tl.CreateSubtenant(tenantID, subtenantCode, subtenantName, actor, map[string]any{"source": "self_registration"}); !res.OK {
		return map[string]any{"ok": false, "error": res.Error}, res.Status
	}

	if err := s.projects.EnsureDefaultTenant(tenantID); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if err := s.projects.EnsureDefaultSubtenant(tenantID, subtenantCode); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	planCode := code.Normalize(s.cfg.RBACRegistrationPlan)
	expires := tlrepo.LicenseExpiresInDays(365)
	if res := s.tl.AssignLicense(tenantID, planCode, actor, expires, nil); !res.OK {
		return map[string]any{"ok": false, "error": res.Error}, res.Status
	}

	if input.PlatformDpaAccepted || s.cfg.RBACPDDpaSelfSignOnRegister {
		source := "registration_trial"
		if input.PlatformDpaAccepted {
			source = "registration_acceptance"
		}
		_ = s.tl.MergeTenantMetadata(tenantID, map[string]any{
			"dpa_signed_at": timeNowRFC3339(),
			"dpa_source":    source,
		}, actor)
	}

	roleCode := strings.TrimSpace(s.cfg.RBACRegistrationBootstrapRole)
	if roleCode == "" {
		roleCode = "tenant_admin"
	}

	result, status := s.createUserInScope(c, tenantID, subtenantCode, email, phone, password, roleCode, input.Consents)
	if status != fiber.StatusCreated {
		return result, status
	}

	result["tenant"] = map[string]any{
		"tenant_id":    tenantID,
		"subtenant_id": subtenantCode,
		"plan_code":    planCode,
	}
	return result, status
}

func (s *RegistrationService) registerViaInvite(c *fiber.Ctx, inviteToken, email, phone, password string, input RegisterInput) (map[string]any, int) {
	if s.invites.IsConsumedToken(inviteToken) {
		return map[string]any{
			"ok": false, "code": "invite_already_used",
			"error": "Ссылка регистрации уже использована",
		}, fiber.StatusConflict
	}

	invite, err := s.invites.FindPendingByToken(inviteToken)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if invite == nil {
		return map[string]any{"ok": false, "error": "Ссылка регистрации недействительна или истекла"}, fiber.StatusNotFound
	}

	tenantID := invite.TenantID
	roleCode := invite.RoleCode
	if roleCode == "" {
		roleCode = "user"
	}
	flow := repository.InviteFlow(*invite)

	var subtenantCode string
	if flow == "user_invite" {
		if !invite.SubtenantCode.Valid || invite.SubtenantCode.String == "" {
			return map[string]any{"ok": false, "error": "Приглашение пользователя некорректно"}, fiber.StatusUnprocessableEntity
		}
		subtenantCode = code.Normalize(invite.SubtenantCode.String)
		if err := s.projects.EnsureDefaultSubtenant(tenantID, subtenantCode); err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
	} else {
		subtenantCode, err = randomCode("st-")
		if err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
		actor := "registration_invite"
		if err := s.pd.SeedTenant(tenantID, "Organization "+tenantID); err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
		if res := s.tl.CreateSubtenant(tenantID, subtenantCode, invite.SubtenantName, actor, map[string]any{
			"source": "registration_invite", "invite_id": invite.ID,
		}); !res.OK {
			return map[string]any{"ok": false, "error": res.Error}, res.Status
		}
		if err := s.projects.EnsureDefaultSubtenant(tenantID, subtenantCode); err != nil {
			return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
		}
	}

	claimed, err := s.invites.ClaimPendingByToken(inviteToken, subtenantCode)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if claimed == nil {
		return map[string]any{
			"ok": false, "code": "invite_already_used",
			"error": "Ссылка регистрации уже использована",
		}, fiber.StatusConflict
	}

	result, status := s.createUserInScope(c, tenantID, subtenantCode, email, phone, password, roleCode, input.Consents)
	if status != fiber.StatusCreated {
		return result, status
	}
	result["tenant"] = map[string]any{
		"tenant_id":    tenantID,
		"subtenant_id": subtenantCode,
		"invite_id":    claimed.ID,
	}
	return result, status
}

func (s *RegistrationService) createUserInScope(
	c *fiber.Ctx,
	tenantID, subtenantID, email, phone, password, roleCode string,
	consents []repository.ConsentItem,
) (map[string]any, int) {
	if conflict := s.rejectIfPhoneAlreadyRegistered(phone); conflict != nil {
		return conflict, int(conflict["status"].(int))
	}

	login, err := s.allocateLoginInScope(phone, tenantID, subtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	if consentErr := s.pd.ValidateRegistrationConsents(tenantID, consents, s.cfg.RBACPDRegisterConsentRequired); consentErr != nil {
		return consentErr, int(consentErr["status"].(int))
	}

	decision := s.licensing.AssertAccess(tenantID, "main", subtenantID)
	if !decision.OK {
		status := decision.Status
		if status == 0 {
			status = fiber.StatusForbidden
		}
		payload := map[string]any{"ok": false, "error": decision.Error}
		if decision.DenyReason != "" {
			payload["deny_reason"] = decision.DenyReason
		}
		return payload, status
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
		payload := map[string]any{"ok": false, "error": quota.Error}
		if quota.DenyReason != "" {
			payload["deny_reason"] = quota.DenyReason
		}
		return payload, status
	}

	if existing, _ := s.users.FindByLogin(tenantID, subtenantID, login); existing != nil {
		return map[string]any{"ok": false, "error": "Пользователь с таким login уже существует"}, fiber.StatusConflict
	}

	passwordHash, err := security.HashPassword(password)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	user, err := s.users.CreateUser(repository.CreateUserInput{
		TenantID: tenantID, SubtenantID: subtenantID, Login: login,
		Email: email, Phone: phone, PasswordHash: passwordHash,
		MFARequired: false, Status: "active",
	})
	if err != nil {
		if strings.Contains(err.Error(), "duplicate key") {
			return map[string]any{
				"ok": false,
				"error": "Пользователь с таким login, email или телефоном уже существует",
			}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": "Ошибка создания пользователя"}, fiber.StatusInternalServerError
	}

	_ = s.roles.AssignRoleByCode(user.ID, tenantID, subtenantID, roleCode, user.ID)
	if len(consents) > 0 {
		_ = s.pd.RecordRegistrationConsents(user.ID, tenantID, subtenantID, consents, c.IP(), string(c.Request().Header.UserAgent()))
	}

	s.recordUserVersion(tenantID, subtenantID, user)

	return map[string]any{
		"ok":        true,
		"status":    fiber.StatusCreated,
		"user":      repository.PublicUser(*user),
		"role_code": roleCode,
	}, fiber.StatusCreated
}

func (s *RegistrationService) rejectIfPhoneAlreadyRegistered(phone string) map[string]any {
	active, err := s.users.HasActivePhone(phone)
	if err != nil || active {
		return phoneAlreadyRegisteredError()
	}
	return nil
}

func phoneAlreadyRegisteredError() map[string]any {
	return map[string]any{
		"ok": false, "status": fiber.StatusConflict,
		"code": "phone_already_registered",
		"error": "Аккаунт с этим телефоном уже есть. Войдите и примите приглашение (accept-invite) или зарегистрируйтесь по ссылке invite с тем же паролем.",
	}
}

func (s *RegistrationService) validateCommonFields(phone, password, email string) map[string]any {
	if phone == "" || password == "" {
		return map[string]any{"ok": false, "status": fiber.StatusUnprocessableEntity, "error": "phone и password обязательны"}
	}
	if !repository.ValidatePhone(phone) {
		return map[string]any{
			"ok": false, "status": fiber.StatusUnprocessableEntity,
			"error": "Телефон: укажите код страны и номер (10–15 цифр в международном формате)",
		}
	}
	if email != "" {
		if _, err := mail.ParseAddress(email); err != nil {
			return map[string]any{"ok": false, "status": fiber.StatusUnprocessableEntity, "error": "Некорректный email"}
		}
	}
	if utf8.RuneCountInString(password) < s.cfg.RBACPasswordMinLength {
		return map[string]any{
			"ok": false, "status": fiber.StatusUnprocessableEntity,
			"error": fmt.Sprintf("Пароль должен быть не короче %d символов", s.cfg.RBACPasswordMinLength),
		}
	}
	return nil
}

func (s *RegistrationService) resolvePhone(input RegisterInput) string {
	phone := strings.TrimSpace(input.Phone)
	if phone != "" {
		return normalizePhone(phone)
	}
	prefix := strings.TrimSpace(input.PhonePrefix)
	number := strings.TrimSpace(input.PhoneNumber)
	if number == "" {
		number = strings.TrimSpace(input.PhoneLocal)
	}
	if prefix == "" && number == "" {
		return ""
	}
	return normalizePhone(prefix + number)
}

func (s *RegistrationService) normalizeEmail(email string) string {
	email = strings.ToLower(strings.TrimSpace(email))
	if email == "" {
		return ""
	}
	return email
}

func (s *RegistrationService) attemptedManualScopeJoin(input RegisterInput) bool {
	if input.Flow == "existing_scope" {
		return true
	}
	return strings.TrimSpace(input.TenantID) != "" || strings.TrimSpace(input.SubtenantID) != ""
}

func (s *RegistrationService) allocateLoginInScope(phone, tenantID, subtenantID string) (string, error) {
	base := loginFromPhone(phone)
	candidate := base
	suffix := 2
	for {
		existing, err := s.users.FindByLogin(tenantID, subtenantID, candidate)
		if err != nil {
			return "", err
		}
		if existing == nil {
			return candidate, nil
		}
		tail := fmt.Sprintf("_%d", suffix)
		maxBase := 64 - len(tail)
		if maxBase < 3 {
			maxBase = 3
		}
		if len(base) > maxBase {
			candidate = base[:maxBase] + tail
		} else {
			candidate = base + tail
		}
		suffix++
	}
}

func normalizePhone(phone string) string {
	digits := digitsOnly(phone)
	if digits == "" {
		return ""
	}
	return "+" + digits
}

func digitsOnly(v string) string {
	var b strings.Builder
	for _, r := range v {
		if r >= '0' && r <= '9' {
			b.WriteRune(r)
		}
	}
	return b.String()
}

func loginFromPhone(phone string) string {
	digits := digitsOnly(phone)
	if digits == "" {
		code, _ := randomCode("u")
		return code
	}
	login := "u" + digits
	if len(login) > 64 {
		login = login[:64]
	}
	if loginPattern.MatchString(login) {
		return login
	}
	if len(digits) > 62 {
		digits = digits[:62]
	}
	return "u" + digits
}

func randomCode(prefix string) (string, error) {
	b := make([]byte, 4)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return prefix + hex.EncodeToString(b), nil
}

func timeNowRFC3339() string {
	return time.Now().UTC().Format("2006-01-02 15:04:05")
}

func (s *RegistrationService) recordUserVersion(tenantID, subtenantID string, user *repository.User) {
	if s.versioning == nil || user == nil {
		return
	}
	s.versioning.Record(versioning.Scope{
		TenantID: tenantID, SubtenantID: subtenantID, ActorUserID: user.ID,
	}, "maniforge_users", fmt.Sprint(user.ID), "insert", nil, repository.PublicUser(*user), user.Login)
}
