// Файл: policy.go
// Назначение: PolicyEngine — IP/time window/step-up для admin API.
// См. также: repository/policy.go, service/guard.go
package service

import (
	"net"
	"os"
	"strconv"
	"strings"
	"time"

	"maniforge/internal/rbac/repository"
)

type PolicyService struct {
	rules *repository.PolicyRuleRepository
}

func NewPolicyService(rules *repository.PolicyRuleRepository) *PolicyService {
	return &PolicyService{rules: rules}
}

func (s *PolicyService) AllowsAdminAction(clientIP, tenantID, subtenantID string) map[string]any {
	effective := s.GetEffectiveAdminRules(tenantID, subtenantID)
	allowedIPs, _ := effective["allowed_ips"].([]string)
	if len(allowedIPs) > 0 && !containsString(allowedIPs, clientIP) {
		return map[string]any{"ok": false, "error": "IP не разрешен для admin-операций"}
	}
	hour := time.Now().UTC().Hour()
	start, _ := effective["allowed_hour_start_utc"].(int)
	end, _ := effective["allowed_hour_end_utc"].(int)
	if hour < start || hour > end {
		return map[string]any{"ok": false, "error": "Вне разрешенного временного окна для admin-операций"}
	}
	return map[string]any{"ok": true}
}

func (s *PolicyService) RequiresStepUp(tenantID, subtenantID string) bool {
	effective := s.GetEffectiveAdminRules(tenantID, subtenantID)
	v, _ := effective["require_step_up"].(bool)
	return v
}

func (s *PolicyService) GetEffectiveAdminRules(tenantID, subtenantID string) map[string]any {
	dbRule, err := s.rules.GetForScope(tenantID, subtenantID)
	if err == nil && dbRule != nil {
		return map[string]any{
			"tenant_id": dbRule.TenantID, "subtenant_id": dbRule.SubtenantID,
			"allowed_ips": dbRule.AllowedIPs,
			"allowed_hour_start_utc": dbRule.AllowedHourStartUTC,
			"allowed_hour_end_utc":   dbRule.AllowedHourEndUTC,
			"require_step_up":           dbRule.RequireStepUp,
			"require_mfa_enrollment":    dbRule.RequireMFAEnrollment,
			"source":                    "db",
		}
	}
	allowedIPs := parseEnvIPs(os.Getenv("RBAC_ADMIN_ALLOWED_IPS"))
	return map[string]any{
		"tenant_id": tenantID, "subtenant_id": subtenantID,
		"allowed_ips":               allowedIPs,
		"allowed_hour_start_utc":    envInt("RBAC_ADMIN_ALLOWED_HOUR_START_UTC", 0),
		"allowed_hour_end_utc":    envInt("RBAC_ADMIN_ALLOWED_HOUR_END_UTC", 23),
		"require_step_up":           envBool("RBAC_ADMIN_REQUIRE_STEP_UP", true),
		"require_mfa_enrollment":    envBool("RBAC_ADMIN_REQUIRE_MFA_ENROLLMENT", false),
		"source":                    "env",
	}
}

func (s *PolicyService) RequiresMFAEnrollment(tenantID, subtenantID string) bool {
	effective := s.GetEffectiveAdminRules(tenantID, subtenantID)
	v, _ := effective["require_mfa_enrollment"].(bool)
	return v
}

func parseEnvIPs(raw string) []string {
	parts := strings.Split(raw, ",")
	var ips []string
	for _, p := range parts {
		v := strings.TrimSpace(p)
		if v != "" {
			ips = append(ips, v)
		}
	}
	return ips
}

func envInt(key string, def int) int {
	v := strings.TrimSpace(os.Getenv(key))
	if v == "" {
		return def
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return def
	}
	return n
}

func envBool(key string, def bool) bool {
	v := strings.TrimSpace(strings.ToLower(os.Getenv(key)))
	if v == "" {
		return def
	}
	switch v {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return def
	}
}

func containsString(items []string, value string) bool {
	for _, item := range items {
		if item == value {
			return true
		}
	}
	return false
}

func isValidIP(value string) bool {
	return net.ParseIP(strings.TrimSpace(value)) != nil
}
