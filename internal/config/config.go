// Package config загружает переменные окружения (.env) для Go-сервисов Maniforge.
//
// Файл: config.go
// Назначение: единая структура Config (PostgreSQL, RBAC, Tenant Licensing, PII).
// Зависимости: github.com/joho/godotenv, .env.example.
// См. также: internal/db/postgres.go, docs/MANIFORGE_GO_CODEMAP.md
package config

import (
	"fmt"
	"os"
	"strconv"
	"strings"

	"github.com/joho/godotenv"
)

// Config mirrors PHP bootstrap (.env) for Go services.
type Config struct {
	AppName  string
	AppEnv   string
	AppDebug bool
	AppURL   string
	Timezone string

	// PHP-референс (MySQL) — не используется Go-сервисами напрямую.
	DBHost    string
	DBPort    int
	DBName    string
	DBUser    string
	DBPass    string
	DBCharset string

	// Go-контур — PostgreSQL (основной).
	GoDBHost    string
	GoDBPort    int
	GoDBName    string
	GoDBUser    string
	GoDBPass    string
	GoDBSSLMode string

	TenancyMode          string
	DefaultTenantID      string
	DefaultSubtenantID   string
	TenancyHeadersRequired bool

	RBACAddr            string
	TLAddr              string
	ManifestEngineAddr  string
	WarehousesAddr      string
	ProductsAddr        string
	InventoryAddr       string
	RealtimeAddr         string
	RealtimeInternalURL  string

	TenantLicensingEnforcement   string
	TenantLicensingInternalURL   string
	TenantLicensingInternalToken string
	TenantLicensingAdminToken    string
	TenantLicensingCacheTTL    int
	TenantLicensingTimeoutSec  int

	RBACSessionTTLMinutes int
	RBACRefreshTTLDays    int
	RBACPIIEncryptionEnabled bool
	RBACPIIEncryptionKey     string
	RBACPIIBlindIndexKey     string

	RBACRegistrationEnabled           bool
	RBACRegistrationPlan              string
	RBACRegistrationDefaultSubtenantID   string
	RBACRegistrationDefaultSubtenantName string
	RBACRegistrationBootstrapRole     string
	RBACPasswordMinLength             int
	RBACPDRegisterConsentRequired     bool
	RBACPDDpaSelfSignOnRegister       bool

	RBACActionTokenTTLSec        int
	RBACMFAStepUpMaxAgeSec       int
	RBACRegistrationInviteTTLHours int
	RBACRegistrationDefaultRole  string

	RBACInternalToken       string
	RBACLoginMaxFails       int
	RBACLoginLockMinutes    int
	RBACRateLimitWindowSec  int
	RBACRateLimitMax        int
	RBACRateLimitLoginMax   int
	RBACRateLimitRegisterMax int
	RBACRateLimitAdminMax   int

	RBACHSTSEnabled   bool
	RBACHSTSMaxAgeSec int

	TLRateLimitWindowSec int
	TLRateLimitMax       int

	RBACSIEMWebhookURL     string
	RBACSIEMWebhookSecret  string
	RBACSIEMWebhookEnabled bool

	VersioningEnabled bool
	VersioningAddr    string
}

// HSTSEnabled — HSTS в production или при RBAC_HSTS_ENABLED=true.
func (c Config) HSTSEnabled() bool {
	if c.RBACHSTSEnabled {
		return true
	}
	return c.IsProductionEnv()
}

// RBACInternalTokenEffective — токен для POST /internal/v1/tenant-events.
// RBAC_INTERNAL_TOKEN приоритетнее; fallback на TENANT_LICENSING_INTERNAL_TOKEN для совместимости.
func (c Config) RBACInternalTokenEffective() string {
	if t := strings.TrimSpace(c.RBACInternalToken); t != "" {
		return t
	}
	return strings.TrimSpace(c.TenantLicensingInternalToken)
}

// Load читает .env (если есть) и переменные окружения MANIFORGE_* / RBAC_*.
func Load() (Config, error) {
	_ = godotenv.Load()

	cfg := Config{
		AppName:  env("APP_NAME", "maniforge"),
		AppEnv:   env("APP_ENV", "local"),
		AppDebug: envBool("APP_DEBUG", true),
		AppURL:   strings.TrimRight(env("APP_URL", "http://127.0.0.1:8092"), "/"),
		Timezone: env("APP_TIMEZONE", "Europe/Moscow"),

		DBHost:    env("DB_HOST", "127.0.0.1"),
		DBPort:    envInt("DB_PORT", 3306),
		DBName:    env("DB_NAME", "test_calculation"),
		DBUser:    env("DB_USER", "root"),
		DBPass:    env("DB_PASS", ""),
		DBCharset: env("DB_CHARSET", "utf8mb4"),

		GoDBHost:    env("MANIFORGE_DB_HOST", "127.0.0.1"),
		GoDBPort:    envInt("MANIFORGE_DB_PORT", 5432),
		GoDBName:    env("MANIFORGE_DB_NAME", "maniforge"),
		GoDBUser:    env("MANIFORGE_DB_USER", "postgres"),
		GoDBPass:    env("MANIFORGE_DB_PASS", ""),
		GoDBSSLMode: env("MANIFORGE_DB_SSLMODE", "disable"),

		TenancyMode:        strings.ToLower(env("TENANCY_MODE", "single")),
		DefaultTenantID:    strings.ToLower(env("DEFAULT_TENANT_ID", "default")),
		DefaultSubtenantID: strings.ToLower(env("DEFAULT_SUBTENANT_ID", "default")),
		TenancyHeadersRequired: envBool("TENANCY_HEADERS_REQUIRED", false),

		RBACAddr:           env("MANIFORGE_RBAC_ADDR", ":8093"),
		TLAddr:             env("MANIFORGE_TENANT_LICENSING_ADDR", ":8094"),
		ManifestEngineAddr: env("MANIFORGE_MANIFEST_ENGINE_ADDR", ":8095"),
		WarehousesAddr:     env("MANIFORGE_WAREHOUSES_ADDR", ":8098"),
		ProductsAddr:       env("MANIFORGE_PRODUCTS_ADDR", ":8099"),
		InventoryAddr:      env("MANIFORGE_INVENTORY_ADDR", ":8100"),
		RealtimeAddr:        env("MANIFORGE_REALTIME_ADDR", ":8097"),
		RealtimeInternalURL: strings.TrimRight(env("MANIFORGE_REALTIME_INTERNAL_URL", "http://127.0.0.1:8097"), "/"),

		TenantLicensingEnforcement:   strings.ToLower(env("TENANT_LICENSING_ENFORCEMENT", "optional")),
		TenantLicensingInternalURL:   strings.TrimRight(env("TENANT_LICENSING_INTERNAL_URL", ""), "/"),
		TenantLicensingInternalToken: env("TENANT_LICENSING_INTERNAL_TOKEN", ""),
		TenantLicensingAdminToken:    env("TENANT_LICENSING_ADMIN_TOKEN", ""),
		TenantLicensingCacheTTL:      envInt("TENANT_LICENSING_CACHE_TTL_SEC", 60),
		TenantLicensingTimeoutSec:    envInt("TENANT_LICENSING_TIMEOUT_SEC", 2),

		RBACSessionTTLMinutes: envInt("RBAC_SESSION_TTL_MINUTES", 720),
		RBACRefreshTTLDays:    envInt("RBAC_REFRESH_TTL_DAYS", 30),
		RBACPIIEncryptionEnabled: envBool("RBAC_PII_ENCRYPTION_ENABLED", false),
		RBACPIIEncryptionKey:     env("RBAC_PII_ENCRYPTION_KEY", ""),
		RBACPIIBlindIndexKey:     env("RBAC_PII_BLIND_INDEX_KEY", ""),

		RBACRegistrationEnabled:            envRegistrationEnabled(),
		RBACRegistrationPlan:               strings.ToLower(env("RBAC_REGISTRATION_PLAN", "starter")),
		RBACRegistrationDefaultSubtenantID: strings.ToLower(env("RBAC_REGISTRATION_DEFAULT_SUBTENANT_ID", "main")),
		RBACRegistrationDefaultSubtenantName: env("RBAC_REGISTRATION_DEFAULT_SUBTENANT_NAME", "Main workspace"),
		RBACRegistrationBootstrapRole:      env("RBAC_REGISTRATION_BOOTSTRAP_ROLE", "tenant_admin"),
		RBACPasswordMinLength:              envInt("RBAC_PASSWORD_MIN_LENGTH", 12),
		RBACPDRegisterConsentRequired:      envBool("RBAC_PD_REGISTER_CONSENT_REQUIRED", false),
		RBACPDDpaSelfSignOnRegister:        envBool("RBAC_PD_DPA_SELF_SIGN_ON_REGISTER", true),

		RBACActionTokenTTLSec:        envInt("RBAC_ACTION_TOKEN_TTL_SEC", 900),
		RBACMFAStepUpMaxAgeSec:       envInt("RBAC_MFA_STEPUP_MAX_AGE_SEC", 900),
		RBACRegistrationInviteTTLHours: envInt("RBAC_REGISTRATION_INVITE_TTL_HOURS", 168),
		RBACRegistrationDefaultRole:  env("RBAC_REGISTRATION_DEFAULT_ROLE", "user"),

		RBACInternalToken:        env("RBAC_INTERNAL_TOKEN", ""),
		RBACLoginMaxFails:        envInt("RBAC_LOGIN_MAX_FAILS", 5),
		RBACLoginLockMinutes:     envInt("RBAC_LOGIN_LOCK_MINUTES", 15),
		RBACRateLimitWindowSec:   envInt("RBAC_RATE_LIMIT_WINDOW_SEC", 60),
		RBACRateLimitMax:         envInt("RBAC_RATE_LIMIT_MAX", 300),
		RBACRateLimitLoginMax:    envInt("RBAC_RATE_LIMIT_LOGIN_MAX", 300),
		RBACRateLimitRegisterMax: envInt("RBAC_RATE_LIMIT_REGISTER_MAX", 30),
		RBACRateLimitAdminMax:    envInt("RBAC_RATE_LIMIT_ADMIN_MAX", 300),

		RBACHSTSEnabled:   envBool("RBAC_HSTS_ENABLED", false),
		RBACHSTSMaxAgeSec: envInt("RBAC_HSTS_MAX_AGE_SEC", 31536000),

		TLRateLimitWindowSec: envInt("TL_RATE_LIMIT_WINDOW_SEC", 60),
		TLRateLimitMax:       envInt("TL_RATE_LIMIT_MAX", 60),

		RBACSIEMWebhookURL:     env("RBAC_SIEM_WEBHOOK_URL", ""),
		RBACSIEMWebhookSecret:  env("RBAC_SIEM_WEBHOOK_SECRET", ""),
		RBACSIEMWebhookEnabled: envBool("RBAC_SIEM_WEBHOOK_ENABLED", false),

		VersioningEnabled: envBool("VERSIONING_ENABLED", true),
		VersioningAddr:    env("MANIFORGE_VERSIONING_ADDR", ":8096"),
	}

	if cfg.GoDBName == "" {
		return cfg, fmt.Errorf("MANIFORGE_DB_NAME is required")
	}

	return cfg, nil
}

func env(key, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return fallback
}

func envInt(key string, fallback int) int {
	v := strings.TrimSpace(os.Getenv(key))
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return fallback
	}
	return n
}

func envRegistrationEnabled() bool {
	configured := strings.TrimSpace(os.Getenv("RBAC_REGISTRATION_ENABLED"))
	if configured != "" {
		return envBool("RBAC_REGISTRATION_ENABLED", false)
	}
	env := strings.ToLower(strings.TrimSpace(os.Getenv("APP_ENV")))
	return env == "local" || env == "testing" || env == "test"
}

func envBool(key string, fallback bool) bool {
	v := strings.TrimSpace(strings.ToLower(os.Getenv(key)))
	if v == "" {
		return fallback
	}
	switch v {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return fallback
	}
}
