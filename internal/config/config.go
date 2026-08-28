// Package config загружает переменные окружения (.env) для Go-сервисов Maniforge.
//
// Файл: config.go
// Назначение: единая структура Config (PostgreSQL, RBAC, Tenant Licensing, PII).
// Зависимости: github.com/joho/godotenv, .env.example.
// См. также: internal/db/postgres.go, docs/MANIFORGE_GO_CODEMAP.md
package config

import (
	"fmt"
	"net"
	"net/url"
	"os"
	"strconv"
	"strings"

	"github.com/joho/godotenv"
)

// Config — переменные окружения Go-сервисов (PostgreSQL, RBAC, licensing).
type Config struct {
	AppName  string
	AppEnv   string
	AppDebug bool
	AppURL   string
	Timezone string

	// Go-контур — PostgreSQL (основной).
	GoDBHost    string
	GoDBPort    int
	GoDBName    string
	GoDBUser    string
	GoDBPass    string
	GoDBSSLMode string

	TenancyMode            string
	DefaultTenantID        string
	DefaultSubtenantID     string
	TenancyHeadersRequired bool

	RBACAddr            string
	TLAddr              string
	ManifestEngineAddr  string
	WarehousesAddr      string
	ProductsAddr        string
	InventoryAddr       string
	RealtimeAddr        string
	RealtimeInternalURL string

	TenantLicensingEnforcement   string
	TenantLicensingInternalURL   string
	TenantLicensingInternalToken string
	TenantLicensingAdminToken    string
	TenantLicensingCacheTTL      int
	TenantLicensingTimeoutSec    int

	// RBACInternalURL is optional HTTP base (…/rbac). Empty = do not call RBAC over HTTP.
	RBACInternalURL string

	RBACSessionTTLMinutes    int
	RBACRefreshTTLDays       int
	RBACPIIEncryptionEnabled bool
	RBACPIIEncryptionKey     string
	RBACPIIBlindIndexKey     string

	RBACRegistrationEnabled              bool
	RBACRegistrationPlan                 string
	RBACRegistrationDefaultSubtenantID   string
	RBACRegistrationDefaultSubtenantName string
	RBACRegistrationBootstrapRole        string
	RBACPasswordMinLength                int
	RBACPDRegisterConsentRequired        bool
	RBACPDDpaSelfSignOnRegister          bool

	RBACActionTokenTTLSec          int
	RBACMFAStepUpMaxAgeSec         int
	RBACRegistrationInviteTTLHours int
	RBACRegistrationDefaultRole    string

	RBACInternalToken        string
	RBACLoginMaxFails        int
	RBACLoginLockMinutes     int
	RBACRateLimitWindowSec   int
	RBACRateLimitMax         int
	RBACRateLimitLoginMax    int
	RBACRateLimitRegisterMax int
	RBACRateLimitAdminMax    int

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
		AppURL:   joinPublicOrigin(strings.TrimRight(env("APP_URL", "http://127.0.0.1:8092"), "/"), env("MANIFORGE_GATEWAY_PORT", "")),
		Timezone: env("APP_TIMEZONE", "Europe/Moscow"),

		GoDBHost:    env("MANIFORGE_DB_HOST", "127.0.0.1"),
		GoDBPort:    envInt("MANIFORGE_DB_PORT", 5432),
		GoDBName:    env("MANIFORGE_DB_NAME", "maniforge"),
		GoDBUser:    env("MANIFORGE_DB_USER", "postgres"),
		GoDBPass:    env("MANIFORGE_DB_PASS", ""),
		GoDBSSLMode: env("MANIFORGE_DB_SSLMODE", "disable"),

		TenancyMode:            strings.ToLower(env("TENANCY_MODE", "single")),
		DefaultTenantID:        strings.ToLower(env("DEFAULT_TENANT_ID", "default")),
		DefaultSubtenantID:     strings.ToLower(env("DEFAULT_SUBTENANT_ID", "default")),
		TenancyHeadersRequired: envBool("TENANCY_HEADERS_REQUIRED", false),

		RBACAddr:            env("MANIFORGE_RBAC_ADDR", ":8093"),
		TLAddr:              env("MANIFORGE_TENANT_LICENSING_ADDR", ":8094"),
		ManifestEngineAddr:  env("MANIFORGE_MANIFEST_ENGINE_ADDR", ":8095"),
		WarehousesAddr:      env("MANIFORGE_WAREHOUSES_ADDR", ":8098"),
		ProductsAddr:        env("MANIFORGE_PRODUCTS_ADDR", ":8099"),
		InventoryAddr:       env("MANIFORGE_INVENTORY_ADDR", ":8100"),
		RealtimeAddr:        env("MANIFORGE_REALTIME_ADDR", ":8097"),
		RealtimeInternalURL: strings.TrimRight(strings.TrimSpace(os.Getenv("MANIFORGE_REALTIME_INTERNAL_URL")), "/"),

		TenantLicensingEnforcement: strings.ToLower(env("TENANT_LICENSING_ENFORCEMENT", "optional")),
		// Unset and explicit empty both mean in-process SQL (shared Postgres).
		// Do not derive from MANIFORGE_TENANT_LICENSING_ADDR — that would force an HTTP hop.
		// Set TENANT_LICENSING_INTERNAL_URL only when TL is on another host.
		TenantLicensingInternalURL:   strings.TrimRight(strings.TrimSpace(os.Getenv("TENANT_LICENSING_INTERNAL_URL")), "/"),
		TenantLicensingInternalToken: env("TENANT_LICENSING_INTERNAL_TOKEN", ""),
		TenantLicensingAdminToken:    env("TENANT_LICENSING_ADMIN_TOKEN", ""),
		TenantLicensingCacheTTL:      envInt("TENANT_LICENSING_CACHE_TTL_SEC", 60),
		TenantLicensingTimeoutSec:    envInt("TENANT_LICENSING_TIMEOUT_SEC", 2),

		RBACInternalURL: strings.TrimRight(strings.TrimSpace(os.Getenv("RBAC_INTERNAL_URL")), "/"),

		RBACSessionTTLMinutes:    envInt("RBAC_SESSION_TTL_MINUTES", 720),
		RBACRefreshTTLDays:       envInt("RBAC_REFRESH_TTL_DAYS", 30),
		RBACPIIEncryptionEnabled: envBool("RBAC_PII_ENCRYPTION_ENABLED", false),
		RBACPIIEncryptionKey:     env("RBAC_PII_ENCRYPTION_KEY", ""),
		RBACPIIBlindIndexKey:     env("RBAC_PII_BLIND_INDEX_KEY", ""),

		RBACRegistrationEnabled:              envRegistrationEnabled(),
		RBACRegistrationPlan:                 strings.ToLower(env("RBAC_REGISTRATION_PLAN", "starter")),
		RBACRegistrationDefaultSubtenantID:   strings.ToLower(env("RBAC_REGISTRATION_DEFAULT_SUBTENANT_ID", "main")),
		RBACRegistrationDefaultSubtenantName: env("RBAC_REGISTRATION_DEFAULT_SUBTENANT_NAME", "Main workspace"),
		RBACRegistrationBootstrapRole:        env("RBAC_REGISTRATION_BOOTSTRAP_ROLE", "tenant_admin"),
		RBACPasswordMinLength:                envInt("RBAC_PASSWORD_MIN_LENGTH", 12),
		RBACPDRegisterConsentRequired:        envBool("RBAC_PD_REGISTER_CONSENT_REQUIRED", false),
		RBACPDDpaSelfSignOnRegister:          envBool("RBAC_PD_DPA_SELF_SIGN_ON_REGISTER", true),

		RBACActionTokenTTLSec:          envInt("RBAC_ACTION_TOKEN_TTL_SEC", 900),
		RBACMFAStepUpMaxAgeSec:         envInt("RBAC_MFA_STEPUP_MAX_AGE_SEC", 900),
		RBACRegistrationInviteTTLHours: envInt("RBAC_REGISTRATION_INVITE_TTL_HOURS", 168),
		RBACRegistrationDefaultRole:    env("RBAC_REGISTRATION_DEFAULT_ROLE", "user"),

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

	if strings.TrimSpace(cfg.RealtimeInternalURL) == "" {
		cfg.RealtimeInternalURL = HTTPOriginFromListenAddr(cfg.RealtimeAddr)
	}

	return cfg, nil
}

// HTTPOriginFromListenAddr turns a Fiber listen bind (host:port, :port, 0.0.0.0:port)
// into an http:// origin for same-host clients. ADDR is not a URL.
func HTTPOriginFromListenAddr(addr string) string {
	addr = strings.TrimSpace(addr)
	if addr == "" {
		return ""
	}
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return ""
	}
	switch host {
	case "", "0.0.0.0", "::", "[::]":
		host = "127.0.0.1"
	}
	return "http://" + net.JoinHostPort(host, port)
}

// JoinInternalHTTP builds http://<listen-addr>/<path> for optional service-to-service calls.
func JoinInternalHTTP(listenAddr, path string) string {
	origin := HTTPOriginFromListenAddr(listenAddr)
	if origin == "" {
		return ""
	}
	path = "/" + strings.Trim(path, "/")
	if path == "/" {
		return origin
	}
	return origin + path
}

// RBACInternalHTTPURL is the explicit RBAC_INTERNAL_URL, or http:// + MANIFORGE_RBAC_ADDR + /rbac.
// Callers that must not HTTP should check RBACInternalURL == "" first (empty = no HTTP).
func (c Config) RBACInternalHTTPURL() string {
	if u := strings.TrimRight(strings.TrimSpace(c.RBACInternalURL), "/"); u != "" {
		return u
	}
	return JoinInternalHTTP(c.RBACAddr, "/rbac")
}

// joinPublicOrigin builds the public origin Go uses for redirects, invite links, and OpenAPI servers.
// APP_URL in env is scheme+host only (no :port). The gateway port lives in MANIFORGE_GATEWAY_PORT.
// If APP_URL already has an explicit host port, it is kept (local compose :8080, PHP :8092).
func joinPublicOrigin(appURL, gatewayPort string) string {
	appURL = strings.TrimRight(strings.TrimSpace(appURL), "/")
	gatewayPort = strings.TrimSpace(gatewayPort)
	if appURL == "" {
		return appURL
	}
	u, err := url.Parse(appURL)
	if err != nil || u.Host == "" {
		return appURL
	}
	if u.Port() != "" {
		return appURL
	}
	if gatewayPort == "" {
		return appURL
	}
	if (u.Scheme == "http" && gatewayPort == "80") || (u.Scheme == "https" && gatewayPort == "443") {
		return appURL
	}
	u.Host = net.JoinHostPort(u.Hostname(), gatewayPort)
	return strings.TrimRight(u.String(), "/")
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
