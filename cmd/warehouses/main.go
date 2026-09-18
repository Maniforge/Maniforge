package main

import (
	"log"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/warehouses"
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
	app := warehouses.NewApp(cfg, sqlDB)
	if err := warehouses.Listen(cfg, app); err != nil {
		log.Fatal(err)
	}
}
