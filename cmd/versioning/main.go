// Файл: main.go
// Назначение: entrypoint HTTP-сервиса versioning (:8096).
package main

import (
	"log"

	"maniforge/internal/config"
	"maniforge/internal/db"
	verhttp "maniforge/internal/versioninghttp"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatal(err)
	}
	sqlDB, err := db.OpenOptional(cfg)
	if err != nil {
		log.Fatal(err)
	}
	if sqlDB == nil {
		log.Fatal("database unavailable")
	}
	defer sqlDB.Close()

	app := verhttp.NewApp(cfg, sqlDB)
	if err := verhttp.Listen(cfg, app); err != nil {
		log.Fatal(err)
	}
}
