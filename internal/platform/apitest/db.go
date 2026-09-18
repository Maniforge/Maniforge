package apitest

import (
	"context"
	"database/sql"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/db"
)

func OpenDB(t *testing.T) (*sql.DB, config.Config) {
	t.Helper()
	var last error
	for attempt := 0; attempt < 8; attempt++ {
		for _, cfg := range dbCandidates() {
			sqlDB, err := openMigrated(cfg)
			if err == nil {
				t.Cleanup(func() { _ = sqlDB.Close() })
				return sqlDB, cfg
			}
			last = err
			if bootErr := bootstrapDatabase(cfg); bootErr != nil {
				last = fmt.Errorf("%v; bootstrap: %w", err, bootErr)
				continue
			}
			sqlDB, err = openMigrated(cfg)
			if err == nil {
				t.Cleanup(func() { _ = sqlDB.Close() })
				return sqlDB, cfg
			}
			last = err
		}
		time.Sleep(250 * time.Millisecond)
	}
	t.Skipf("PostgreSQL недоступна для HTTP API-тестов: %v", last)
	return nil, config.Config{}
}

func dbCandidates() []config.Config {
	base := TestConfig()
	out := []config.Config{base}

	local := base
	local.GoDBHost = "127.0.0.1"
	local.GoDBPort = 5432
	local.GoDBUser = "postgres"
	local.GoDBPass = "postgres"
	local.GoDBName = "maniforge"
	local.GoDBSSLMode = "disable"
	out = append(out, local)

	ci := base
	ci.GoDBHost = "127.0.0.1"
	ci.GoDBPort = 5433
	ci.GoDBUser = "maniforge"
	ci.GoDBPass = "maniforge"
	ci.GoDBName = "maniforge"
	ci.GoDBSSLMode = "disable"
	out = append(out, ci)
	return out
}

func openMigrated(cfg config.Config) (*sql.DB, error) {
	sqlDB, err := db.OpenOptional(cfg)
	if err != nil || sqlDB == nil {
		if err == nil {
			err = fmt.Errorf("db nil")
		}
		return nil, err
	}
	if err := applyMigrations(sqlDB); err != nil {
		_ = sqlDB.Close()
		return nil, err
	}
	return sqlDB, nil
}

func bootstrapDatabase(cfg config.Config) error {
	admin := cfg
	admin.GoDBName = "postgres"
	sqlDB, err := db.OpenOptional(admin)
	if err != nil || sqlDB == nil {
		if err == nil {
			err = fmt.Errorf("admin db nil")
		}
		return err
	}
	defer sqlDB.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	var exists bool
	if err := sqlDB.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM pg_database WHERE datname = $1)`, cfg.GoDBName).Scan(&exists); err != nil {
		return err
	}
	if !exists {
		if _, err := sqlDB.ExecContext(ctx, `CREATE DATABASE `+pqIdent(cfg.GoDBName)); err != nil {
			return fmt.Errorf("create database: %w", err)
		}
	}
	if cfg.GoDBUser != "" && cfg.GoDBUser != "postgres" {
		_, _ = sqlDB.ExecContext(ctx, fmt.Sprintf(
			`DO $$ BEGIN CREATE ROLE %s LOGIN PASSWORD %s; EXCEPTION WHEN duplicate_object THEN NULL; END $$`,
			pqIdent(cfg.GoDBUser), pqLiteral(cfg.GoDBPass),
		))
		_, _ = sqlDB.ExecContext(ctx, fmt.Sprintf(`GRANT ALL PRIVILEGES ON DATABASE %s TO %s`, pqIdent(cfg.GoDBName), pqIdent(cfg.GoDBUser)))
	}
	return nil
}

func applyMigrations(sqlDB *sql.DB) error {
	root := repoRoot()
	dir := filepath.Join(root, "migrations", "pg")
	entries, err := os.ReadDir(dir)
	if err != nil {
		return err
	}
	var files []string
	for _, e := range entries {
		if !e.IsDir() && strings.HasSuffix(e.Name(), ".sql") {
			files = append(files, e.Name())
		}
	}
	sort.Strings(files)
	for _, name := range files {
		var exists bool
		err := sqlDB.QueryRow(`SELECT EXISTS(SELECT 1 FROM maniforge_migrations WHERE version = $1)`, name).Scan(&exists)
		if err != nil {
			exists = false
		}
		if exists {
			continue
		}
		body, err := os.ReadFile(filepath.Join(dir, name))
		if err != nil {
			return err
		}
		if _, err := sqlDB.Exec(string(body)); err != nil {
			return fmt.Errorf("apply %s: %w", name, err)
		}
		if _, err := sqlDB.Exec(`INSERT INTO maniforge_migrations (version) VALUES ($1) ON CONFLICT (version) DO NOTHING`, name); err != nil {
			return fmt.Errorf("track %s: %w", name, err)
		}
	}
	return nil
}

func repoRoot() string {
	wd, err := os.Getwd()
	if err != nil {
		return "."
	}
	dir := wd
	for i := 0; i < 8; i++ {
		if _, err := os.Stat(filepath.Join(dir, "go.mod")); err == nil {
			return dir
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			break
		}
		dir = parent
	}
	return wd
}

func pqIdent(name string) string {
	return `"` + strings.ReplaceAll(name, `"`, `""`) + `"`
}

func pqLiteral(v string) string {
	return `'` + strings.ReplaceAll(v, `'`, `''`) + `'`
}
