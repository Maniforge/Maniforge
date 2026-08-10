// Файл: main.go (cmd/manifest-engine)
// Назначение: точка входа Manifest Engine (:8095, /api/data).
// См. также: internal/manifestengine/app.go, docs/MANIFORGE_MANIFEST_ENGINE.md
package main

import (
	"log"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/manifestengine"
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
		log.Printf("db: %v (health only)", err)
	}
	if sqlDB != nil {
		defer sqlDB.Close()
	}

	app := manifestengine.NewApp(cfg, sqlDB)
	if err := manifestengine.Listen(cfg, app); err != nil {
		log.Fatal(err)
	}
}
