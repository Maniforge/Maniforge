// Файл: main.go (cmd/backup-drill)
// Назначение: drill готовности backup — снимок счётчиков критичных таблиц RBAC/TL.
// См. также: docs/MANIFORGE_ENTERPRISE_HARDENING.md
package main

import (
	"fmt"
	"os"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/db"
)

var criticalTables = []string{
	"maniforge_users",
	"maniforge_sessions",
	"maniforge_roles",
	"maniforge_permissions",
	"maniforge_user_roles",
	"maniforge_audit_log",
	"maniforge_security_events",
	"maniforge_tl_tenants",
	"maniforge_tl_tenant_licenses",
	"maniforge_tl_events",
	"maniforge_mfa_factors",
	"maniforge_siem_outbox",
}

func main() {
	cfg, err := config.Load()
	if err != nil {
		fmt.Fprintf(os.Stderr, "config: %v\n", err)
		os.Exit(2)
	}
	sqlDB, err := db.Open(cfg)
	if err != nil {
		fmt.Fprintf(os.Stderr, "db: %v\n", err)
		os.Exit(2)
	}
	defer sqlDB.Close()

	fmt.Printf("backup-drill %s\n", time.Now().UTC().Format(time.RFC3339))
	fmt.Printf("database: %s@%s:%d/%s\n", cfg.GoDBUser, cfg.GoDBHost, cfg.GoDBPort, cfg.GoDBName)

	failed := 0
	for _, table := range criticalTables {
		var count int64
		err := sqlDB.QueryRow(fmt.Sprintf("SELECT COUNT(*) FROM %s", table)).Scan(&count)
		if err != nil {
			fmt.Printf("[FAIL] %s: %v\n", table, err)
			failed++
			continue
		}
		fmt.Printf("[OK] %s rows=%d\n", table, count)
	}

	pending := int64(0)
	_ = sqlDB.QueryRow(
		`SELECT COUNT(*) FROM maniforge_siem_outbox WHERE delivered_at IS NULL`,
	).Scan(&pending)
	fmt.Printf("[INFO] siem_outbox pending=%d\n", pending)

	fmt.Println("\nРекомендация: pg_dump -Fc maniforge > backup_$(date +" + "%Y-%m-" + "d).dump")
	if failed > 0 {
		os.Exit(1)
	}
}
