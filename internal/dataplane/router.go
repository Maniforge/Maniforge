// Package dataplane — маршрутизация подключений к data plane tenant.
//
// Файл: router.go
// Назначение: shared (одна БД) сейчас; dedicated (БД tenant) — через metadata TL.
// См. также: docs/adr/0009-control-data-plane-manifest-origin.md
package dataplane

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"sync"

	"maniforge/internal/config"

	_ "github.com/jackc/pgx/v5/stdlib"
)

const (
	ModeShared    = "shared"
	ModeDedicated = "dedicated"
)

// Resolver возвращает *sql.DB для data plane tenant.
type Resolver struct {
	cfg    config.Config
	shared *sql.DB
	mu     sync.Mutex
	pool   map[string]*sql.DB
}

func NewResolver(cfg config.Config, shared *sql.DB) *Resolver {
	return &Resolver{cfg: cfg, shared: shared, pool: make(map[string]*sql.DB)}
}

// DB — подключение к data plane. Сейчас всегда shared; dedicated — по metadata TL.
func (r *Resolver) DB(ctx context.Context, tenantID string) (*sql.DB, error) {
	if r.shared == nil {
		return nil, fmt.Errorf("data plane: shared db not configured")
	}
	mode, dsn, err := r.lookupMode(ctx, tenantID)
	if err != nil {
		return r.shared, nil
	}
	if mode != ModeDedicated || dsn == "" {
		return r.shared, nil
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	if db, ok := r.pool[tenantID]; ok {
		return db, nil
	}
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return nil, err
	}
	r.pool[tenantID] = db
	return db, nil
}

func (r *Resolver) lookupMode(ctx context.Context, tenantCode string) (mode, dsn string, err error) {
	if tenantCode == "" {
		return ModeShared, "", nil
	}
	var meta []byte
	err = r.shared.QueryRowContext(ctx,
		`SELECT metadata_json FROM maniforge_tl_tenants WHERE code = $1 LIMIT 1`,
		tenantCode,
	).Scan(&meta)
	if err == sql.ErrNoRows {
		return ModeShared, "", nil
	}
	if err != nil {
		return "", "", err
	}
	var m map[string]any
	_ = json.Unmarshal(meta, &m)
	if m == nil {
		return ModeShared, "", nil
	}
	mode, _ = m["data_plane_mode"].(string)
	dsn, _ = m["data_plane_dsn"].(string)
	if mode == "" {
		mode = ModeShared
	}
	return mode, dsn, nil
}

// Shared возвращает общее подключение (control + data в MVP).
func (r *Resolver) Shared() *sql.DB {
	return r.shared
}
