// Файл: main.go (cmd/manifest-presets-seed)
// Назначение: установка supply chain presets (product, stock) в project scope через БД.
package main

import (
	"database/sql"
	"fmt"
	"log"
	"os"
	"strings"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/manifestengine/model"
	"maniforge/internal/manifestengine/presets"
	mrepo "maniforge/internal/manifestengine/repository"
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

	tenantID := env("MANIFEST_PRESETS_TENANT", cfg.DefaultTenantID)
	subtenantID := env("MANIFEST_PRESETS_SUBTENANT", cfg.DefaultSubtenantID)
	projectCode := env("MANIFEST_PRESETS_PROJECT", "main")

	projectID, err := resolveProjectID(sqlDB, tenantID, subtenantID, projectCode)
	if err != nil {
		log.Fatalf("project: %v", err)
	}

	repo := mrepo.New(sqlDB)
	scope := model.Scope{TenantID: tenantID, SubtenantID: subtenantID, ProjectID: projectID, UserID: 0}
	installed := 0

	for _, def := range presets.All() {
		existing, err := repo.GetManifestByCode(scope, def.Code)
		if err != nil {
			log.Fatalf("get %s: %v", def.Code, err)
		}
		if existing != nil {
			log.Printf("skip %s (already exists)", def.Code)
			continue
		}
		if _, err := repo.CreateManifest(scope, def.Code, def.Name, model.OriginPlatform, def.Fields, def.Metadata, 0); err != nil {
			log.Fatalf("create %s: %v", def.Code, err)
		}
		installed++
		log.Printf("installed preset %s", def.Code)
	}

	fmt.Printf("manifest presets seed ok: tenant=%s project=%s installed=%d\n", tenantID, projectCode, installed)
}

func resolveProjectID(db *sql.DB, tenantID, subtenantID, projectCode string) (int64, error) {
	var id int64
	err := db.QueryRow(
		`SELECT id FROM maniforge_projects
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND code = $3 AND status = 'active' LIMIT 1`,
		tenantID, subtenantID, projectCode,
	).Scan(&id)
	if err == sql.ErrNoRows {
		return 0, fmt.Errorf("проект %s/%s/%s не найден", tenantID, subtenantID, projectCode)
	}
	return id, err
}

func env(k, def string) string {
	if v := strings.TrimSpace(os.Getenv(k)); v != "" {
		return v
	}
	return def
}
