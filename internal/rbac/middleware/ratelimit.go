// Файл: ratelimit.go
// Назначение: rate limit в стиле WB API — 300 req/min на пользователя, auth по phone.
// См. также: repository/rate_limit.go, docs/MANIFORGE_ENTERPRISE_HARDENING.md
package middleware

import (
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
)

// AuthRateLimitGuard — login/register/refresh: bucket по phone (или refresh_token), не по IP.
func AuthRateLimitGuard(cfg config.Config, db *sql.DB, kind string) fiber.Handler {
	return rateLimitHandler(cfg, db, func(c *fiber.Ctx) (string, int) {
		limit := cfg.RBACRateLimitLoginMax
		switch kind {
		case "register":
			limit = cfg.RBACRateLimitRegisterMax
		case "refresh":
			limit = cfg.RBACRateLimitLoginMax
		}
		if hint := authBodyIdentity(c, kind); hint != "" {
			return hashBucket("auth", kind, hint), limit
		}
		return hashBucket("auth", kind, "ip:"+clientIP(c)), limit
	})
}

// UserRateLimitGuard — 300 req/min на tenant+user (как WB «на аккаунт продавца»).
func UserRateLimitGuard(cfg config.Config, db *sql.DB) fiber.Handler {
	return rateLimitHandler(cfg, db, func(c *fiber.Ctx) (string, int) {
		session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
		if !ok || session == nil {
			return "", 0
		}
		path := normalizePath(c.Path())
		category := "api"
		if strings.Contains(path, "/api/v1/admin/") {
			category = "admin"
		}
		limit := cfg.RBACRateLimitMax
		if category == "admin" && cfg.RBACRateLimitAdminMax > 0 {
			limit = cfg.RBACRateLimitAdminMax
		}
		raw := strings.Join([]string{
			"user",
			session.TenantID,
			session.SubtenantID,
			strconv.FormatInt(session.UserID, 10),
			category,
		}, "|")
		return hashBucket(raw), limit
	})
}

// RateLimitGuard — alias UserRateLimitGuard (после SessionAuth).
func RateLimitGuard(cfg config.Config, db *sql.DB) fiber.Handler {
	return UserRateLimitGuard(cfg, db)
}

type bucketResolver func(c *fiber.Ctx) (bucketKey string, limit int)

func rateLimitHandler(cfg config.Config, db *sql.DB, resolve bucketResolver) fiber.Handler {
	repo := repository.NewRateLimitRepository(db)
	return func(c *fiber.Ctx) error {
		if db == nil {
			return c.Next()
		}
		bucket, limit := resolve(c)
		if bucket == "" || limit <= 0 {
			return c.Next()
		}
		state, err := repo.Increment(bucket, cfg.RBACRateLimitWindowSec)
		if err != nil {
			return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
		}
		remaining := state.Remaining(limit)
		resetSec := state.ResetSec()
		if state.Count > limit {
			setRateLimit429Headers(c, limit, resetSec)
			return httpx.JSON(c, fiber.StatusTooManyRequests, fiber.Map{
				"ok": false, "error": "Слишком много запросов",
				"code": "rate_limit_exceeded",
			})
		}
		setRateLimitOKHeaders(c, limit, remaining, resetSec)
		return c.Next()
	}
}

func authBodyIdentity(c *fiber.Ctx, kind string) string {
	if !strings.EqualFold(c.Method(), "POST") {
		return ""
	}
	body := c.Body()
	if len(body) == 0 {
		return ""
	}
	switch kind {
	case "login", "register":
		var payload struct {
			Phone string `json:"phone"`
		}
		if err := json.Unmarshal(body, &payload); err != nil {
			return ""
		}
		return normalizePhoneHint(payload.Phone)
	case "refresh":
		var payload struct {
			RefreshToken string `json:"refresh_token"`
		}
		if err := json.Unmarshal(body, &payload); err != nil {
			return ""
		}
		token := strings.TrimSpace(payload.RefreshToken)
		if token == "" {
			return ""
		}
		if len(token) > 32 {
			token = token[:32]
		}
		return "rt:" + token
	default:
		return ""
	}
}

func clientIP(c *fiber.Ctx) string {
	ip := strings.TrimSpace(c.IP())
	if ip == "" {
		return "unknown"
	}
	return ip
}

func normalizePhoneHint(phone string) string {
	phone = strings.TrimSpace(phone)
	if phone == "" {
		return ""
	}
	var b strings.Builder
	for i, r := range phone {
		if r == '+' && i == 0 {
			b.WriteRune(r)
			continue
		}
		if r >= '0' && r <= '9' {
			b.WriteRune(r)
		}
	}
	return b.String()
}

func hashBucket(parts ...string) string {
	raw := strings.Join(parts, "|")
	sum := sha256.Sum256([]byte(raw))
	return hex.EncodeToString(sum[:])
}

func rateBucketKey(ip, method, path string) string {
	return hashBucket(ip, strings.ToUpper(method), path)
}

func setRateLimitOKHeaders(c *fiber.Ctx, limit, remaining, resetSec int) {
	c.Set("X-Ratelimit-Limit", strconv.Itoa(limit))
	c.Set("X-Ratelimit-Remaining", strconv.Itoa(remaining))
	if resetSec > 0 {
		c.Set("X-Ratelimit-Reset", strconv.Itoa(resetSec))
	}
}

func setRateLimit429Headers(c *fiber.Ctx, limit, resetSec int) {
	retry := resetSec
	if retry < 1 {
		retry = 1
	}
	c.Set("X-Ratelimit-Limit", strconv.Itoa(limit))
	c.Set("X-Ratelimit-Remaining", "0")
	c.Set("X-Ratelimit-Reset", strconv.Itoa(resetSec))
	c.Set("X-Ratelimit-Retry", strconv.Itoa(retry))
}
