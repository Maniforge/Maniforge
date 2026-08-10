// Package db — подключение к PostgreSQL (основная БД Go-контура).
//
// Файл: postgres.go
// Назначение: Open, OpenOptional (с ping), Ping для health-check.
// Зависимости: pgx stdlib, internal/config.
// См. также: cmd/migrate/main.go, docs/MANIFORGE_GO_CODEMAP.md
package db

import (
	"context"
	"database/sql"
	"fmt"
	"time"

	"maniforge/internal/config"

	_ "github.com/jackc/pgx/v5/stdlib"
)

// Open открывает пул PostgreSQL; вызывающий обязан Ping при старте сервиса.
func Open(cfg config.Config) (*sql.DB, error) {
	dsn := fmt.Sprintf(
		"host=%s port=%d user=%s password=%s dbname=%s sslmode=%s",
		cfg.GoDBHost,
		cfg.GoDBPort,
		cfg.GoDBUser,
		cfg.GoDBPass,
		cfg.GoDBName,
		cfg.GoDBSSLMode,
	)

	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return nil, err
	}

	db.SetMaxOpenConns(25)
	db.SetMaxIdleConns(5)
	db.SetConnMaxLifetime(30 * time.Minute)

	return db, nil
}

// OpenOptional — Open + Ping; при ошибке возвращает nil (сервис стартует в degraded).
func OpenOptional(cfg config.Config) (*sql.DB, error) {
	db, err := Open(cfg)
	if err != nil {
		return nil, err
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := db.PingContext(ctx); err != nil {
		_ = db.Close()
		return nil, err
	}
	return db, nil
}

// Ping проверяет доступность БД (health-check).
func Ping(ctx context.Context, db *sql.DB) error {
	return db.PingContext(ctx)
}
