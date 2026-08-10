// Файл: main.go (cmd/inventory)
// Назначение: точка входа Inventory HTTP-сервиса (:8100).
package main

import (
	"log"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/inventory"
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
		log.Printf("db: %v", err)
	}
	if sqlDB != nil {
		defer sqlDB.Close()
	}
	app := inventory.NewApp(cfg, sqlDB)
	if err := inventory.Listen(cfg, app); err != nil {
		log.Fatal(err)
	}
}
