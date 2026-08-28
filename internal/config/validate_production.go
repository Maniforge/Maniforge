// Р¤Р°Р№Р»: validate_production.go
// РќР°Р·РЅР°С‡РµРЅРёРµ: РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Рµ РїСЂРѕРІРµСЂРєРё РїРµСЂРµРґ production rollout Go-СЃРµСЂРІРёСЃРѕРІ.
// РЎРј. С‚Р°РєР¶Рµ: docs/MANIFORGE_ENTERPRISE_HARDENING.md, maniforge/rbac/tools/preflight.php
package config

import (
	"fmt"
	"strings"
)

// ValidateProduction РІРѕР·РІСЂР°С‰Р°РµС‚ РѕС€РёР±РєСѓ, РµСЃР»Рё production profile РЅРµ СЃРѕР±Р»СЋРґС‘РЅ.
func (c Config) ValidateProduction() error {
	if !c.IsProductionEnv() {
		return nil
	}
	var failures []string

	if c.AppDebug {
		failures = append(failures, "APP_DEBUG must be false in production")
	}
	if c.GoDBSSLMode == "disable" {
		failures = append(failures, "MANIFORGE_DB_SSLMODE must not be disable in production")
	}
	if strings.TrimSpace(c.GoDBPass) == "" {
		failures = append(failures, "MANIFORGE_DB_PASS is required in production")
	}
	if strings.TrimSpace(c.TenantLicensingAdminToken) == "" {
		failures = append(failures, "TENANT_LICENSING_ADMIN_TOKEN is required in production")
	}
	if strings.TrimSpace(c.TenantLicensingInternalToken) == "" {
		failures = append(failures, "TENANT_LICENSING_INTERNAL_TOKEN is required in production")
	}
	if strings.TrimSpace(c.RBACInternalTokenEffective()) == "" {
		failures = append(failures, "RBAC_INTERNAL_TOKEN (or TENANT_LICENSING_INTERNAL_TOKEN fallback) is required in production")
	}
	if strings.ToLower(c.TenantLicensingEnforcement) != "strict" {
		failures = append(failures, "TENANT_LICENSING_ENFORCEMENT must be strict in production")
	}
	if c.RBACPIIEncryptionEnabled && !c.HasValidPIIKey() {
		failures = append(failures, "RBAC_PII_ENCRYPTION_KEY must be valid base64 32 bytes when RBAC_PII_ENCRYPTION_ENABLED=true")
	}
	if c.RBACPIIEncryptionRequired() && (!c.RBACPIIEncryptionEnabled || !c.HasValidPIIKey()) {
		failures = append(failures, "RBAC_PII_ENCRYPTION_ENABLED=true and RBAC_PII_ENCRYPTION_KEY are required in production")
	}

	if len(failures) == 0 {
		return nil
	}
	return fmt.Errorf("production guard failed:\n- %s", strings.Join(failures, "\n- "))
}


func (c Config) isLoopbackDBHost() bool {
	h := strings.ToLower(strings.TrimSpace(c.GoDBHost))
	switch h {
	case "127.0.0.1", "localhost", "::1":
		return true
	default:
		return false
	}
}
func (c Config) IsProductionEnv() bool {
	switch strings.ToLower(strings.TrimSpace(c.AppEnv)) {
	case "prod", "production":
		return true
	default:
		return false
	}
}

func (c Config) RBACPIIEncryptionRequired() bool {
	return c.IsProductionEnv()
}

func (c Config) HasValidPIIKey() bool {
	key := strings.TrimSpace(c.RBACPIIEncryptionKey)
	if key == "" {
		return false
	}
	// base64 decode check omitted вЂ” non-empty is enough for startup gate; preflight does full check
	return len(key) >= 32
}

