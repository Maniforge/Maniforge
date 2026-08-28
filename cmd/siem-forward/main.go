// Файл: main.go (cmd/siem-forward)
// Назначение: доставка pending записей maniforge_siem_outbox в webhook SIEM.
// См. также: internal/platform/siem/notifier.go, docs/MANIFORGE_ENTERPRISE_HARDENING.md
package main

import (
	"flag"
	"fmt"
	"log"
	"os"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/platform/siem"
	"maniforge/internal/rbac/repository"
)

func main() {
	limit := flag.Int("limit", 100, "max events per run")
	flag.Parse()

	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}
	if !cfg.RBACSIEMWebhookEnabled {
		fmt.Println("RBAC_SIEM_WEBHOOK_ENABLED=false — nothing to do")
		return
	}
	sqlDB, err := db.Open(cfg)
	if err != nil {
		log.Fatalf("db: %v", err)
	}
	defer sqlDB.Close()

	outbox := repository.NewSiemOutboxRepository(sqlDB)
	notifier := siem.NewNotifier(cfg)
	if !notifier.Enabled() {
		log.Fatal("RBAC_SIEM_WEBHOOK_URL is required when webhook enabled")
	}

	items, err := outbox.Pending(*limit)
	if err != nil {
		log.Fatalf("pending: %v", err)
	}
	sent, failed := 0, 0
	for _, ev := range items {
		if err := notifier.Deliver(ev); err != nil {
			_ = outbox.MarkFailed(ev.ID, err.Error())
			failed++
			continue
		}
		if err := outbox.MarkDelivered(ev.ID); err != nil {
			log.Printf("mark delivered %d: %v", ev.ID, err)
			failed++
			continue
		}
		sent++
	}
	fmt.Fprintf(os.Stdout, "siem-forward: sent=%d failed=%d pending_processed=%d\n", sent, failed, len(items))
	if failed > 0 {
		os.Exit(1)
	}
}
