// Файл: main.go (cmd/realtime)
// Назначение: точка входа WebSocket Realtime (:8097).
// См. также: internal/realtime/app.go, docs/MANIFORGE_REALTIME.md
package main

import (
	"log"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/realtime"
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

	app := realtime.NewApp(cfg, sqlDB)
	if err := realtime.Listen(cfg, app); err != nil {
		log.Fatal(err)
	}
}
