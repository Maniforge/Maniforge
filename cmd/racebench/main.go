// Файл: main.go (cmd/racebench)
// Назначение: race condition + benchmark PostgreSQL (блокировки, QPS).
// Зависимости: internal/config, internal/db, RBAC/TL repositories.
// См. также: docs/MANIFORGE_RACE_BENCHMARK.md, maniforge/rbac/tools/race_condition_check.php
package main

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"flag"
	"fmt"
	"log"
	"os"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/db"
	rbacrepo "maniforge/internal/rbac/repository"
	tlrepo "maniforge/internal/tenantlicensing/repository"
)

type scenarioResult struct {
	Name       string
	Workers    int
	Duration   time.Duration
	Attempts   int64
	Success    int64
	Errors     int64
	LockWaits  int64
	P50Ms      float64
	P99Ms      float64
	QPS        float64
	Findings   []string
}

func main() {
	workers := flag.Int("workers", 32, "parallel workers per scenario")
	duration := flag.Duration("duration", 3*time.Second, "benchmark duration per scenario")
	scenario := flag.String("scenario", "all", "scenario name or all")
	flag.Parse()

	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}
	sqlDB, err := db.Open(cfg)
	if err != nil {
		log.Fatalf("db: %v", err)
	}
	defer sqlDB.Close()

	ctx := context.Background()
	if err := db.Ping(ctx, sqlDB); err != nil {
		log.Fatalf("db ping: %v", err)
	}

	fix, err := setupFixtures(sqlDB)
	if err != nil {
		log.Fatalf("setup: %v", err)
	}
	defer fix.cleanup(sqlDB)

	all := []func(context.Context, *sql.DB, *fixtures, int, time.Duration) scenarioResult{
		runSelectPing,
		runUserReadSpread,
		runSessionTouchHot,
		runSessionTouchCold,
		runProfileUpsertHot,
		runSecurityBumpHot,
		runAccessStateRead,
		runInviteClaimRace,
		runLicenseAssignRace,
	}

	var results []scenarioResult
	for _, fn := range all {
		res := fn(ctx, sqlDB, fix, *workers, *duration)
		if *scenario != "all" && res.Name != *scenario {
			continue
		}
		results = append(results, res)
	}

	if len(results) == 0 {
		log.Fatalf("unknown scenario: %s", *scenario)
	}

	printReport(results)
	if hasCritical(results) {
		os.Exit(1)
	}
}

func hasCritical(results []scenarioResult) bool {
	for _, r := range results {
		for _, f := range r.Findings {
			if strings.HasPrefix(f, "CRITICAL:") || strings.HasPrefix(f, "RACE:") {
				return true
			}
		}
	}
	return false
}

func printReport(results []scenarioResult) {
	fmt.Println("\n=== Maniforge PG race/benchmark ===")
	fmt.Printf("%-22s %6s %8s %8s %7s %7s %6s %6s %s\n",
		"scenario", "workers", "attempts", "success", "errors", "p50ms", "p99ms", "qps", "lock_waits")
	for _, r := range results {
		fmt.Printf("%-22s %6d %8d %8d %7d %7.2f %7.2f %6.1f %6d\n",
			r.Name, r.Workers, r.Attempts, r.Success, r.Errors, r.P50Ms, r.P99Ms, r.QPS, r.LockWaits)
		for _, f := range r.Findings {
			fmt.Printf("  → %s\n", f)
		}
	}
	fmt.Println("\nПримечания:")
	fmt.Println("- session_touch_hot: одна строка → QPS ≈ 1/latency (сериализация ROW EXCLUSIVE).")
	fmt.Println("- invite_claim_race / license_assign_race: ожидается ровно 1 успех при N воркерах.")
	fmt.Println("- Для HTTP QPS запускайте racebench при поднятых rbac+tl и отдельный wrk/k6 (см. docs).")
}

type fixtures struct {
	tenant       string
	subtenant    string
	userID       int64
	sessionHotID string
	sessionIDs   []string
	inviteToken  string
	licenseTenant string
	licensePlan  string
}

func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func setupFixtures(sqlDB *sql.DB) (*fixtures, error) {
	suffix := randomHex(4)
	f := &fixtures{
		tenant:        "rc_" + suffix,
		subtenant:     "main",
		licenseTenant: "rc_lic_" + suffix,
		licensePlan:   "rc_plan_" + suffix,
		inviteToken:   "invite_" + randomHex(16),
	}

	tl := tlrepo.New(sqlDB)
	if res := tl.CreateTenant(f.licenseTenant, "Race License", "racebench", nil); !res.OK {
		return nil, fmt.Errorf("create license tenant: %s", res.Error)
	}
	if res := tl.CreateSubtenant(f.licenseTenant, "main", "Main", "racebench", nil); !res.OK {
		return nil, fmt.Errorf("create license subtenant: %s", res.Error)
	}
	_, err := sqlDB.Exec(
		`INSERT INTO maniforge_tl_license_plans (code, name, status, features_json, limits_json)
		 VALUES ($1, 'Race Plan', 'active', '{"rbac":true}'::jsonb, '{"max_users":50}'::jsonb)
		 ON CONFLICT (code) DO NOTHING`, f.licensePlan)
	if err != nil {
		return nil, err
	}

	// User + project in default tenant for session tests
	cfg, _ := config.Load()
	users := rbacrepo.NewUserRepository(sqlDB, cfg)
	user, err := users.CreateUser(rbacrepo.CreateUserInput{
		TenantID:     "default",
		SubtenantID:  "default",
		Login:        "rc_" + suffix,
		Email:        "rc_" + suffix + "@example.test",
		Phone:        fmt.Sprintf("+7905%07d", time.Now().UnixNano()%10000000),
		PasswordHash: "$argon2id$v=19$m=65536,t=3,p=2$dummy",
		Status:       "active",
	})
	if err != nil {
		return nil, fmt.Errorf("create user: %w", err)
	}
	f.userID = user.ID

	sessions := rbacrepo.NewSessionRepository(sqlDB)
	for i := 0; i < 16; i++ {
		sid := randomHex(16)
		token := randomHex(32)
		exp := rbacrepo.SessionExpiresAt(720)
		pid, _ := rbacrepo.DefaultProjectID(sqlDB, "default", "default")
		err := sessions.CreateSession(rbacrepo.SessionCreateInput{
			ID: sid, UserID: f.userID, TenantID: "default", SubtenantID: "default",
			ProjectID: pid, AccessToken: token, IP: "127.0.0.1", UserAgent: "racebench",
			AAL: "AAL1", ExpiresAt: exp, SecurityVersion: 1,
		})
		if err != nil {
			return nil, err
		}
		f.sessionIDs = append(f.sessionIDs, sid)
	}
	f.sessionHotID = f.sessionIDs[0]

	tokenHash := rbacrepo.HashCredentialToken(f.inviteToken)
	_, err = sqlDB.Exec(
		`INSERT INTO maniforge_registration_invites (
			token_hash, tenant_id, subtenant_name, subtenant_code, status, role_code, expires_at
		) VALUES ($1, 'default', 'Race', 'default', 'pending', 'user', NOW() + INTERVAL '1 hour')`,
		tokenHash)
	if err != nil {
		return nil, fmt.Errorf("invite: %w", err)
	}

	return f, nil
}

func (f *fixtures) cleanup(sqlDB *sql.DB) {
	queries := []string{
		`DELETE FROM maniforge_registration_invites WHERE tenant_id IN ('default', $1)`,
		`DELETE FROM maniforge_refresh_tokens WHERE tenant_id = 'default'`,
		`DELETE FROM maniforge_sessions WHERE tenant_id = 'default'`,
		`DELETE FROM maniforge_user_profile WHERE user_id = $2`,
		`DELETE FROM maniforge_users WHERE id = $2`,
		`DELETE FROM maniforge_tl_events WHERE tenant_code = $1`,
		`DELETE FROM maniforge_tl_audit_log WHERE tenant_code = $1`,
		`DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = $1`,
		`DELETE FROM maniforge_tl_subtenants WHERE tenant_code = $1`,
		`DELETE FROM maniforge_tl_tenants WHERE code = $1`,
		`DELETE FROM maniforge_tl_license_plans WHERE code = $3`,
	}
	for _, q := range queries {
		_, _ = sqlDB.Exec(q, f.licenseTenant, f.userID, f.licensePlan)
	}
}

type benchStats struct {
	attempts  atomic.Int64
	success   atomic.Int64
	errors    atomic.Int64
	lockWaits atomic.Int64
	latencies []float64
	mu        sync.Mutex
}

func (s *benchStats) record(ok bool, ms float64, lockWait bool) {
	s.attempts.Add(1)
	if ok {
		s.success.Add(1)
	} else {
		s.errors.Add(1)
	}
	if lockWait {
		s.lockWaits.Add(1)
	}
	s.mu.Lock()
	s.latencies = append(s.latencies, ms)
	s.mu.Unlock()
}

func (s *benchStats) finalize(name string, workers int, dur time.Duration) scenarioResult {
	s.mu.Lock()
	lat := append([]float64(nil), s.latencies...)
	s.mu.Unlock()
	sort.Float64s(lat)
	p50, p99 := percentile(lat, 0.50), percentile(lat, 0.99)
	att := s.attempts.Load()
	sec := dur.Seconds()
	qps := float64(att) / sec
	return scenarioResult{
		Name: name, Workers: workers, Duration: dur,
		Attempts: att, Success: s.success.Load(), Errors: s.errors.Load(),
		LockWaits: s.lockWaits.Load(), P50Ms: p50, P99Ms: p99, QPS: qps,
	}
}

func percentile(sorted []float64, p float64) float64 {
	if len(sorted) == 0 {
		return 0
	}
	idx := int(float64(len(sorted)-1) * p)
	return sorted[idx]
}

func startLockWatcher(ctx context.Context, sqlDB *sql.DB, stats *benchStats) func() {
	ctx, cancel := context.WithCancel(ctx)
	done := make(chan struct{})
	go func() {
		ticker := time.NewTicker(50 * time.Millisecond)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				close(done)
				return
			case <-ticker.C:
				var waiting int
				_ = sqlDB.QueryRow(
					`SELECT COUNT(*) FROM pg_locks WHERE NOT granted`).Scan(&waiting)
				if waiting > 0 {
					stats.lockWaits.Add(int64(waiting))
				}
			}
		}
	}()
	return func() {
		cancel()
		<-done
	}
}

func runWorkers(ctx context.Context, sqlDB *sql.DB, workers int, dur time.Duration, fn func() (bool, error)) *benchStats {
	stats := &benchStats{}
	stop := startLockWatcher(ctx, sqlDB, stats)
	defer stop()

	deadline := time.Now().Add(dur)
	var wg sync.WaitGroup
	for w := 0; w < workers; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for time.Now().Before(deadline) {
				start := time.Now()
				ok, err := fn()
				ms := float64(time.Since(start).Microseconds()) / 1000.0
				lockWait := err != nil && (strings.Contains(err.Error(), "deadlock") ||
					strings.Contains(err.Error(), "lock timeout") ||
					strings.Contains(err.Error(), "could not serialize"))
				stats.record(ok && err == nil, ms, lockWait)
			}
		}()
	}
	wg.Wait()
	return stats
}

func runSelectPing(ctx context.Context, sqlDB *sql.DB, _ *fixtures, workers int, dur time.Duration) scenarioResult {
	stats := runWorkers(ctx, sqlDB, workers, dur, func() (bool, error) {
		var one int
		err := sqlDB.QueryRowContext(ctx, `SELECT 1`).Scan(&one)
		return one == 1, err
	})
	res := stats.finalize("select_ping", workers, dur)
	res.Findings = []string{"baseline: пул + round-trip без блокировок приложения"}
	return res
}

func runUserReadSpread(ctx context.Context, sqlDB *sql.DB, f *fixtures, workers int, dur time.Duration) scenarioResult {
	cfg, _ := config.Load()
	users := rbacrepo.NewUserRepository(sqlDB, cfg)
	stats := runWorkers(ctx, sqlDB, workers, dur, func() (bool, error) {
		u, err := users.FindByIDInScope(f.userID, "default", "default")
		return u != nil, err
	})
	res := stats.finalize("user_read", workers, dur)
	res.Findings = []string{"чтение по PK+scope — масштабируется с воркерами"}
	return res
}

func runSessionTouchHot(ctx context.Context, sqlDB *sql.DB, f *fixtures, workers int, dur time.Duration) scenarioResult {
	sessions := rbacrepo.NewSessionRepository(sqlDB)
	stats := runWorkers(ctx, sqlDB, workers, dur, func() (bool, error) {
		return true, sessions.Touch(f.sessionHotID)
	})
	res := stats.finalize("session_touch_hot", workers, dur)
	res.Findings = []string{
		"WARN: hot row — все UPDATE одной сессии сериализуются (ROW EXCLUSIVE)",
		fmt.Sprintf("теор. потолок ~%.0f QPS при p50=%.2fms", 1000.0/res.P50Ms, res.P50Ms),
	}
	if res.QPS < float64(workers)/10 {
		res.Findings = append(res.Findings, "ожидаемо: QPS << workers из-за блокировки строки")
	}
	return res
}

func runSessionTouchCold(ctx context.Context, sqlDB *sql.DB, f *fixtures, workers int, dur time.Duration) scenarioResult {
	sessions := rbacrepo.NewSessionRepository(sqlDB)
	var idx atomic.Int64
	stats := runWorkers(ctx, sqlDB, workers, dur, func() (bool, error) {
		i := int(idx.Add(1)) % len(f.sessionIDs)
		return true, sessions.Touch(f.sessionIDs[i])
	})
	res := stats.finalize("session_touch_cold", workers, dur)
	res.Findings = []string{"разные session id — блокировки распределены"}
	return res
}

func runProfileUpsertHot(ctx context.Context, sqlDB *sql.DB, f *fixtures, workers int, dur time.Duration) scenarioResult {
	profiles := rbacrepo.NewUserProfileRepository(sqlDB)
	var n atomic.Int64
	stats := runWorkers(ctx, sqlDB, workers, dur, func() (bool, error) {
		v := fmt.Sprintf("bench-%d", n.Add(1))
		_, err := profiles.Upsert(f.userID, rbacrepo.ProfileUpdateInput{DisplayName: &v})
		return err == nil, err
	})
	res := stats.finalize("profile_upsert_hot", workers, dur)
	res.Findings = []string{"user_profile: UPSERT одной строки — умеренная конкуренция"}
	return res
}

func runSecurityBumpHot(ctx context.Context, sqlDB *sql.DB, f *fixtures, workers int, dur time.Duration) scenarioResult {
	var successes atomic.Int64
	stats := runWorkers(ctx, sqlDB, workers, dur, func() (bool, error) {
		res, err := sqlDB.ExecContext(ctx,
			`UPDATE maniforge_users SET security_version = security_version + 1, updated_at = NOW()
			 WHERE id = $1`, f.userID)
		if err != nil {
			return false, err
		}
		n, _ := res.RowsAffected()
		if n == 1 {
			successes.Add(1)
		}
		return n == 1, nil
	})
	res := stats.finalize("security_bump_hot", workers, dur)
	res.Findings = []string{
		fmt.Sprintf("security_version: %d успешных bump за окно (сериализация на user row)", successes.Load()),
		"при identity change + RevokeAllForUser — узкое место: user row + массовый UPDATE sessions",
	}
	return res
}

func runAccessStateRead(ctx context.Context, sqlDB *sql.DB, _ *fixtures, workers int, dur time.Duration) scenarioResult {
	tl := tlrepo.New(sqlDB)
	stats := runWorkers(ctx, sqlDB, workers, dur, func() (bool, error) {
		st := tl.AccessStateForProject("default", "main", "default")
		return st.OK && st.TenantActive, nil
	})
	res := stats.finalize("access_state_read", workers, dur)
	res.Findings = []string{"licensing read-only — JOIN tenant+project+license, без write lock"}
	return res
}

func runInviteClaimRace(ctx context.Context, sqlDB *sql.DB, f *fixtures, workers int, _ time.Duration) scenarioResult {
	invites := rbacrepo.NewInviteRepository(sqlDB)
	var okCount atomic.Int64
	var wg sync.WaitGroup
	start := make(chan struct{})
	latencies := make([]float64, 0, workers)
	var mu sync.Mutex

	for i := 0; i < workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			t0 := time.Now()
			rec, err := invites.ClaimPendingByToken(f.inviteToken, "default")
			ms := float64(time.Since(t0).Microseconds()) / 1000.0
			mu.Lock()
			latencies = append(latencies, ms)
			mu.Unlock()
			if err == nil && rec != nil {
				okCount.Add(1)
			}
		}()
	}
	close(start)
	wg.Wait()

	sort.Float64s(latencies)
	res := scenarioResult{
		Name: "invite_claim_race", Workers: workers, Duration: 0,
		Attempts: int64(workers), Success: okCount.Load(),
		Errors: int64(workers) - okCount.Load(),
		P50Ms: percentile(latencies, 0.5), P99Ms: percentile(latencies, 0.99),
		QPS: float64(workers),
	}
	if okCount.Load() != 1 {
		res.Findings = []string{fmt.Sprintf("RACE: ожидался 1 claim, получено %d (FOR UPDATE)", okCount.Load())}
	} else {
		res.Findings = []string{"OK: FOR UPDATE — ровно один claim при параллельных воркерах"}
	}
	return res
}

func runLicenseAssignRace(ctx context.Context, sqlDB *sql.DB, f *fixtures, workers int, _ time.Duration) scenarioResult {
	tl := tlrepo.New(sqlDB)
	exp := time.Now().UTC().Add(30 * 24 * time.Hour)
	seats := 10
	var okCount atomic.Int64
	var wg sync.WaitGroup
	start := make(chan struct{})

	for i := 0; i < workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			<-start
			res := tl.AssignLicense(f.licenseTenant, f.licensePlan, "racebench", &exp, &seats)
			if res.OK {
				okCount.Add(1)
			}
		}()
	}
	close(start)
	wg.Wait()

	var active int
	_ = sqlDB.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM maniforge_tl_tenant_licenses
		 WHERE tenant_code = $1 AND status = 'active'`, f.licenseTenant).Scan(&active)

	res := scenarioResult{
		Name: "license_assign_race", Workers: workers,
		Attempts: int64(workers), Success: okCount.Load(),
		Errors: int64(workers) - okCount.Load(), QPS: float64(workers),
	}
	if active != 1 {
		res.Findings = []string{fmt.Sprintf("RACE: active licenses = %d, ожидался 1", active)}
	} else {
		res.Findings = []string{"OK: tx revoke+insert — один active license после гонки"}
	}
	if okCount.Load() < 1 {
		res.Findings = append(res.Findings, "CRITICAL: ни один assign не прошёл")
	}
	return res
}
