// Файл: main.go (cmd/tenant-licensing)
// Назначение: точка входа Tenant Licensing HTTP-сервиса (:8094).
// Зависимости: internal/config, internal/db, internal/tenantlicensing.
// См. также: internal/tenantlicensing/app.go, docs/MANIFORGE_GO_CODEMAP.md
package main

import (
	"log"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/tenantlicensing"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}
	if err := cfg.ValidateProduction(); err != nil {
		log.Fatalf("production guard: %v", err)
	}

	sqlDB, err := db.OpenOptional(cfg)
	if err != nil {
		log.Printf("db: %v (API will return 503 except /health)", err)
	}
	if sqlDB != nil {
		defer sqlDB.Close()
	}

	app := tenantlicensing.NewApp(cfg, sqlDB)
	if err := tenantlicensing.Listen(cfg, app); err != nil {
		log.Fatal(err)
	}
}
