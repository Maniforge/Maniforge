// Файл: main.go (cmd/preflight)
// Назначение: production preflight для Go-контура (PostgreSQL + env guards).
// См. также: maniforge/rbac/tools/preflight.php, docs/MANIFORGE_ENTERPRISE_HARDENING.md
package main

import (
	"database/sql"
	"fmt"
	"os"

	"maniforge/internal/config"
	"maniforge/internal/db"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		fail("config: %v", err)
	}

	ok, warn, failN := 0, 0, 0
	pass := func(msg string) {
		ok++
		fmt.Printf("[OK] %s\n", msg)
	}
	failf := func(msg string) {
		failN++
		fmt.Fprintf(os.Stderr, "[FAIL] %s\n", msg)
	}

	sqlDB, err := db.OpenOptional(cfg)
	if err != nil {
		failf("DB connection failed: " + err.Error())
	} else if sqlDB == nil {
		failf("database unavailable")
	} else {
		defer sqlDB.Close()
		pass("PostgreSQL connection established")
		checkTables(sqlDB, pass, failf)
	}

	if err := cfg.ValidateProduction(); err != nil {
		if cfg.IsProductionEnv() {
			failf(err.Error())
		} else {
			pass(fmt.Sprintf("Production guard skipped for APP_ENV=%s", cfg.AppEnv))
		}
	} else if cfg.IsProductionEnv() {
		pass("Production profile validated")
	} else {
		pass(fmt.Sprintf("Production guard skipped for APP_ENV=%s", cfg.AppEnv))
	}

	if cfg.RBACPIIEncryptionEnabled && cfg.HasValidPIIKey() {
		pass("PII encryption configured")
	} else if cfg.IsProductionEnv() {
		failf("Production guard: enable RBAC_PII_ENCRYPTION_ENABLED and RBAC_PII_ENCRYPTION_KEY")
	}

	fmt.Printf("\nSummary: ok=%d, warn=%d, fail=%d\n", ok, warn, failN)
	if failN > 0 {
		os.Exit(1)
	}
}

func checkTables(db *sql.DB, pass func(string), failf func(string)) {
	required := []string{
		"maniforge_users",
		"maniforge_sessions",
		"maniforge_login_attempts",
		"maniforge_rate_limits",
		"maniforge_mfa_factors",
		"maniforge_siem_outbox",
		"maniforge_tl_tenants",
		"maniforge_tl_tenant_grants",
	}
	for _, table := range required {
		var exists bool
		err := db.QueryRow(
			`SELECT EXISTS (
				SELECT 1 FROM information_schema.tables
				WHERE table_schema = current_schema() AND table_name = $1
			)`,
			table,
		).Scan(&exists)
		if err != nil {
			failf("Failed to check table " + table + ": " + err.Error())
			continue
		}
		if exists {
			pass("Table exists: " + table)
		} else {
			failf("Table missing: " + table)
		}
	}
}

func fail(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "[FAIL] "+format+"\n", args...)
	os.Exit(2)
}
