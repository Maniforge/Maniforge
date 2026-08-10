// Файл: main.go (cmd/products)
// Назначение: точка входа Products HTTP-сервиса (:8099).
package main

import (
	"log"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/products"
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
	app := products.NewApp(cfg, sqlDB)
	if err := products.Listen(cfg, app); err != nil {
		log.Fatal(err)
	}
}
