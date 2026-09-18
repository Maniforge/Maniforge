// Файл: main.go (cmd/bootstrap)
// Назначение: при разворачивании создаёт demo-админа и tenantId (SHA-256 от UUID).
package main

import (
	"fmt"
	"log"
	"os"
	"path/filepath"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/rbac/service"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}
	login := os.Getenv("MANIFORGE_ADMIN_LOGIN")
	password := os.Getenv("MANIFORGE_ADMIN_PASSWORD")
	org := os.Getenv("MANIFORGE_ADMIN_ORG")
	if login == "" || password == "" {
		log.Fatal("задайте MANIFORGE_ADMIN_LOGIN (телефон) и MANIFORGE_ADMIN_PASSWORD")
	}

	sqlDB, err := db.Open(cfg)
	if err != nil {
		log.Fatalf("db: %v", err)
	}
	defer sqlDB.Close()

	reg := service.NewRegistrationService(cfg, sqlDB)
	access, err := reg.BootstrapDemo(login, password, org)
	if err != nil {
		log.Fatalf("bootstrap: %v", err)
	}

	root := projectRoot()
	paths := []string{
		filepath.Join(root, "deploy", "www-desk", "assets", "bootstrap.json"),
		filepath.Join(root, "public", "assets", "bootstrap.json"),
	}
	if extra := os.Getenv("MANIFORGE_BOOTSTRAP_JSON"); extra != "" {
		paths = append(paths, extra)
	}
	for _, p := range paths {
		if err := access.WritePublicManifest(p); err != nil {
			log.Printf("warning: write %s: %v", p, err)
		}
	}

	state := "existing"
	if access.Created {
		state = "created"
	}
	fmt.Printf("demo access %s\n", state)
	fmt.Printf("tenant_id    %s\n", access.TenantID)
	fmt.Printf("subtenant_id %s\n", access.SubtenantID)
	fmt.Printf("login        %s\n", access.Phone)
	fmt.Printf("role         %s\n", access.Role)
}

func projectRoot() string {
	if v := os.Getenv("MANIFORGE_ROOT"); v != "" {
		return v
	}
	wd, _ := os.Getwd()
	return wd
}
