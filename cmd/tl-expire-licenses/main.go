// Файл: main.go (cmd/tl-expire-licenses)
// Назначение: перевод просроченных лицензий active → expired (systemd timer).
package main

import (
	"fmt"
	"log"
	"os"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/tenantlicensing/repository"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}
	sqlDB, err := db.Open(cfg)
	if err != nil {
		log.Fatalf("db: %v", err)
	}
	defer sqlDB.Close()

	repo := repository.New(sqlDB)
	expired, scanned, err := repo.ExpireDueLicenses("license-expiry-job")
	if err != nil {
		log.Fatalf("expire: %v", err)
	}
	fmt.Fprintf(os.Stdout, "tl-expire-licenses: expired=%d scanned=%d\n", expired, scanned)
}
