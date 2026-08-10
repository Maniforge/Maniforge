// Файл: rate_limit.go
// Назначение: скользящее окно rate limit (maniforge_rate_limits).
// См. также: middleware/ratelimit.go, app/Maniforge/Rbac/Repository/RateLimitRepository.php
package repository

import (
	"database/sql"
	"time"
)

type RateLimitRepository struct {
	db *sql.DB
}

// RateLimitState — счётчик скользящего окна для заголовков X-Ratelimit-*.
type RateLimitState struct {
	Count       int
	WindowStart time.Time
	WindowSec   int
}

func (s RateLimitState) Remaining(limit int) int {
	rem := limit - s.Count
	if rem < 0 {
		return 0
	}
	return rem
}

func (s RateLimitState) ResetSec() int {
	left := time.Duration(s.WindowSec)*time.Second - time.Since(s.WindowStart)
	if left <= 0 {
		return 0
	}
	return int(left.Seconds()) + 1
}

func NewRateLimitRepository(db *sql.DB) *RateLimitRepository {
	return &RateLimitRepository{db: db}
}

func (r *RateLimitRepository) Increment(bucketKey string, windowSec int) (RateLimitState, error) {
	if windowSec < 1 {
		windowSec = 60
	}
	tx, err := r.db.Begin()
	if err != nil {
		return RateLimitState{}, err
	}
	defer func() { _ = tx.Rollback() }()

	var windowStarted time.Time
	var requestCount int
	err = tx.QueryRow(
		`SELECT window_started_at, request_count FROM maniforge_rate_limits
		 WHERE bucket_key = $1 FOR UPDATE`,
		bucketKey,
	).Scan(&windowStarted, &requestCount)

	now := time.Now()
	if err == sql.ErrNoRows {
		_, err = tx.Exec(
			`INSERT INTO maniforge_rate_limits (bucket_key, window_started_at, request_count, updated_at)
			 VALUES ($1, $2, 1, $2)`,
			bucketKey, now,
		)
		if err != nil {
			return RateLimitState{}, err
		}
		if err := tx.Commit(); err != nil {
			return RateLimitState{}, err
		}
		return RateLimitState{Count: 1, WindowStart: now, WindowSec: windowSec}, nil
	}
	if err != nil {
		return RateLimitState{}, err
	}

	windowExpired := time.Since(windowStarted) >= time.Duration(windowSec)*time.Second
	count := requestCount + 1
	windowStart := windowStarted
	if windowExpired {
		count = 1
		windowStart = now
	}
	_, err = tx.Exec(
		`UPDATE maniforge_rate_limits
		 SET window_started_at = CASE WHEN $2 THEN $4 ELSE window_started_at END,
		     request_count = $3,
		     updated_at = $4
		 WHERE bucket_key = $1`,
		bucketKey, windowExpired, count, now,
	)
	if err != nil {
		return RateLimitState{}, err
	}
	if err := tx.Commit(); err != nil {
		return RateLimitState{}, err
	}
	return RateLimitState{Count: count, WindowStart: windowStart, WindowSec: windowSec}, nil
}
