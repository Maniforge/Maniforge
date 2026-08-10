// Файл: main.go (cmd/rbac)
// Назначение: точка входа RBAC HTTP-сервиса Maniforge (:8093).
// Зависимости: internal/config, internal/db, internal/rbac.
// См. также: internal/rbac/app.go, docs/MANIFORGE_GO_CODEMAP.md
package main

import (
	"log"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/rbac"
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
		log.Printf("db: %v (health will report db=down)", err)
	}
	if sqlDB != nil {
		defer sqlDB.Close()
	}

	app := rbac.NewApp(cfg, sqlDB)
	if err := rbac.Listen(cfg, app); err != nil {
		log.Fatal(err)
	}
}
