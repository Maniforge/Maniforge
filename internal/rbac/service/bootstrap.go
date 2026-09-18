// Файл: bootstrap.go
// Назначение: demo-доступ при разворачивании — логин/пароль админки + tenantId из хеша UUID.
package service

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/code"
	"maniforge/internal/platform/tenantid"
	"maniforge/internal/rbac/repository"
	tlrepo "maniforge/internal/tenantlicensing/repository"
)

const demoBootstrapSource = "demo_bootstrap"

// DemoAccess — результат bootstrap (без пароля).
type DemoAccess struct {
	Created     bool   `json:"created"`
	TenantID    string `json:"tenant_id"`
	SubtenantID string `json:"subtenant_id"`
	Phone       string `json:"phone"`
	Login       string `json:"login"`
	Role        string `json:"role"`
}

func parseAdminLogin(raw string) (string, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return "", fmt.Errorf("логин обязателен (телефон в международном формате)")
	}
	phone := normalizePhone(raw)
	if !repository.ValidatePhone(phone) {
		return "", fmt.Errorf("логин должен быть телефоном (+79991234567)")
	}
	return phone, nil
}

func validateBootstrapInput(login, password string) error {
	if _, err := parseAdminLogin(login); err != nil {
		return err
	}
	if strings.TrimSpace(password) == "" {
		return fmt.Errorf("пароль обязателен")
	}
	if utf8.RuneCountInString(password) < 12 {
		return fmt.Errorf("пароль должен быть не короче 12 символов")
	}
	return nil
}

// BootstrapDemo создаёт demo-tenant и tenant_admin. Идемпотентно: повтор с тем же телефоном
// возвращает существующий tenant.
func (s *RegistrationService) BootstrapDemo(login, password, orgName string) (DemoAccess, error) {
	if err := validateBootstrapInput(login, password); err != nil {
		return DemoAccess{}, err
	}
	phone, _ := parseAdminLogin(login)
	if utf8.RuneCountInString(password) < s.cfg.RBACPasswordMinLength {
		return DemoAccess{}, fmt.Errorf("пароль должен быть не короче %d символов", s.cfg.RBACPasswordMinLength)
	}

	subtenant := code.Normalize(s.cfg.RBACRegistrationDefaultSubtenantID)
	if subtenant == "" {
		subtenant = "main"
	}
	subName := s.cfg.RBACRegistrationDefaultSubtenantName
	if subName == "" {
		subName = "Main workspace"
	}
	role := strings.TrimSpace(s.cfg.RBACRegistrationBootstrapRole)
	if role == "" {
		role = "tenant_admin"
	}

	if existing, _ := s.users.FindAllByPhone(phone); len(existing) > 0 {
		u := existing[0]
		return DemoAccess{
			Created:     false,
			TenantID:    u.TenantID,
			SubtenantID: u.SubtenantID,
			Phone:       phone,
			Login:       u.Login,
			Role:        role,
		}, nil
	}

	if existingCode, err := s.tl.FindCodeBySource(demoBootstrapSource); err == nil && existingCode != "" {
		if u, _ := s.users.FindByLogin(existingCode, subtenant, loginFromPhone(phone)); u != nil {
			return DemoAccess{
				Created: false, TenantID: existingCode, SubtenantID: subtenant,
				Phone: phone, Login: u.Login, Role: role,
			}, nil
		}
	}

	tenantID, err := tenantid.New()
	if err != nil {
		return DemoAccess{}, err
	}
	if orgName = strings.TrimSpace(orgName); orgName == "" {
		orgName = "Demo"
	}

	actor := "demo_bootstrap"
	meta := map[string]any{"source": demoBootstrapSource, "kind": "demo"}
	if res := s.tl.CreateTenant(tenantID, orgName, actor, meta); !res.OK {
		return DemoAccess{}, fmt.Errorf("%s", res.Error)
	}
	if err := s.pd.SeedTenant(tenantID, orgName); err != nil {
		return DemoAccess{}, err
	}
	if res := s.tl.CreateSubtenant(tenantID, subtenant, subName, actor, meta); !res.OK {
		return DemoAccess{}, fmt.Errorf("%s", res.Error)
	}
	if err := s.projects.EnsureDefaultTenant(tenantID); err != nil {
		return DemoAccess{}, err
	}
	if err := s.projects.EnsureDefaultSubtenant(tenantID, subtenant); err != nil {
		return DemoAccess{}, err
	}
	plan := code.Normalize(s.cfg.RBACRegistrationPlan)
	expires := tlrepo.LicenseExpiresInDays(365)
	if res := s.tl.AssignLicense(tenantID, plan, actor, expires, nil); !res.OK {
		return DemoAccess{}, fmt.Errorf("%s", res.Error)
	}

	result, status := s.createUserInScope(nil, tenantID, subtenant, "", phone, password, role, nil)
	if status != fiber.StatusCreated {
		errMsg, _ := result["error"].(string)
		if errMsg == "" {
			errMsg = "не удалось создать пользователя"
		}
		return DemoAccess{}, fmt.Errorf("%s", errMsg)
	}
	loginName, _ := result["user"].(map[string]any)
	loginStr := loginFromPhone(phone)
	if loginName != nil {
		if v, ok := loginName["login"].(string); ok && v != "" {
			loginStr = v
		}
	}

	return DemoAccess{
		Created:     true,
		TenantID:    tenantID,
		SubtenantID: subtenant,
		Phone:       phone,
		Login:       loginStr,
		Role:        role,
	}, nil
}

// WritePublicManifest writes tenant_id for Desk/Admin (no password).
func (a DemoAccess) WritePublicManifest(path string) error {
	if strings.TrimSpace(path) == "" {
		return nil
	}
	body, err := json.MarshalIndent(map[string]any{
		"tenant_id":    a.TenantID,
		"subtenant_id": a.SubtenantID,
		"phone":        a.Phone,
		"source":       demoBootstrapSource,
		"written_at":   time.Now().UTC().Format(time.RFC3339),
	}, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	return os.WriteFile(path, append(body, '\n'), 0o644)
}
