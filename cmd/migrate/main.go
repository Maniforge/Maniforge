// Файл: main.go (cmd/migrate)
// Назначение: последовательное применение SQL-миграций из migrations/pg/.
// Зависимости: maniforge_migrations, internal/config, internal/db.
// См. также: migrations/pg/*.sql, docs/MANIFORGE_GO_CODEMAP.md
package main

import (
	"fmt"
	"log"
	"os"
	"path/filepath"
	"sort"
	"strings"

	"maniforge/internal/config"
	"maniforge/internal/db"
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

	migrationsDir := filepath.Join(projectRoot(), "migrations", "pg")
	entries, err := os.ReadDir(migrationsDir)
	if err != nil {
		log.Fatalf("read migrations: %v", err)
	}

	var files []string
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		files = append(files, e.Name())
	}
	sort.Strings(files)
	if len(files) == 0 {
		log.Fatal("no migration files found")
	}

	for _, name := range files {
		var exists bool
		err := sqlDB.QueryRow(
			`SELECT EXISTS(SELECT 1 FROM maniforge_migrations WHERE version = $1)`,
			name,
		).Scan(&exists)
		if err != nil {
			// maniforge_migrations may not exist yet — first file creates it.
			exists = false
		}
		if exists {
			log.Printf("skip %s", name)
			continue
		}

		body, err := os.ReadFile(filepath.Join(migrationsDir, name))
		if err != nil {
			log.Fatalf("read %s: %v", name, err)
		}

		if _, err := sqlDB.Exec(string(body)); err != nil {
			log.Fatalf("apply %s: %v", name, err)
		}

		if _, err := sqlDB.Exec(
			`INSERT INTO maniforge_migrations (version) VALUES ($1) ON CONFLICT (version) DO NOTHING`,
			name,
		); err != nil {
			log.Fatalf("track %s: %v", name, err)
		}

		log.Printf("applied %s", name)
	}

	fmt.Println("migrations ok")
}

func projectRoot() string {
	wd, err := os.Getwd()
	if err != nil {
		return "."
	}
	return wd
}
