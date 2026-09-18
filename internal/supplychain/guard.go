// Package supplychain — общая сессия, scope и HTTP-хелперы складов/товаров/остатков/WMS.
package supplychain

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"math"
	"regexp"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/licensingclient"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
)

type HTTPError struct {
	Status int
	Msg    string
	Code   string
	Extra  map[string]any
}

func (e *HTTPError) Error() string { return e.Msg }

func Guard(
	c *fiber.Ctx,
	rbac *service.RbacService,
	lic *licensingclient.Client,
	perm string,
) (*repository.SessionRecord, error) {
	session, _ := c.Locals("maniforge_session").(*repository.SessionRecord)
	if session == nil {
		return nil, &HTTPError{Status: fiber.StatusUnauthorized, Msg: "Не авторизован"}
	}
	ok, err := rbac.HasPermission(session.UserID, session.TenantID, session.SubtenantID, perm)
	if err != nil {
		return nil, &HTTPError{Status: fiber.StatusInternalServerError, Msg: err.Error()}
	}
	if !ok {
		return nil, &HTTPError{Status: fiber.StatusForbidden, Msg: "Недостаточно permissions"}
	}
	if lic != nil {
		d := lic.AssertAccess(session.TenantID, "main", session.SubtenantID)
		if !d.OK {
			st := d.Status
			if st == 0 {
				st = fiber.StatusForbidden
			}
			msg := d.Error
			if msg == "" {
				msg = "Лицензия недоступна"
			}
			return nil, &HTTPError{Status: st, Msg: msg}
		}
	}
	return session, nil
}

func WriteErr(c *fiber.Ctx, err error) error {
	he, ok := err.(*HTTPError)
	if !ok {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	body := fiber.Map{"ok": false, "error": he.Msg}
	if he.Code != "" {
		body["code"] = he.Code
	}
	for k, v := range he.Extra {
		body[k] = v
	}
	return httpx.JSON(c, he.Status, body)
}

func Result(c *fiber.Ctx, payload map[string]any, status int) error {
	if payload == nil {
		payload = map[string]any{"ok": false, "error": "empty"}
	}
	if _, has := payload["ok"]; !has {
		payload["ok"] = status >= 200 && status < 300
	}
	return httpx.JSON(c, status, payload)
}

func Body(c *fiber.Ctx) map[string]any {
	var m map[string]any
	_ = c.BodyParser(&m)
	if m == nil {
		m = map[string]any{}
	}
	return m
}

func Str(m map[string]any, keys ...string) string {
	for _, k := range keys {
		if v, ok := m[k]; ok && v != nil {
			s := strings.TrimSpace(fmt.Sprint(v))
			if s != "" && s != "<nil>" {
				return s
			}
		}
	}
	return ""
}

func AsInt64(v any) int64 {
	switch t := v.(type) {
	case float64:
		return int64(t)
	case json.Number:
		n, _ := t.Int64()
		return n
	case int64:
		return t
	case int:
		return int64(t)
	case string:
		n, _ := strconv.ParseInt(strings.TrimSpace(t), 10, 64)
		return n
	default:
		return 0
	}
}

func Int64(m map[string]any, keys ...string) int64 {
	for _, k := range keys {
		if v, ok := m[k]; ok && v != nil && fmt.Sprint(v) != "" {
			return AsInt64(v)
		}
	}
	return 0
}

type Scope struct {
	TenantID    string
	SubtenantID string
	ProjectID   sql.NullInt64
	Visibility  string
}

func ScopeFromSession(s *repository.SessionRecord) Scope {
	vis := "project"
	if !s.ProjectID.Valid {
		vis = "subtenant"
	}
	return Scope{
		TenantID:    s.TenantID,
		SubtenantID: s.SubtenantID,
		ProjectID:   s.ProjectID,
		Visibility:  vis,
	}
}

func VisibleSQL(alias string) string {
	p := alias
	if p != "" && !strings.HasSuffix(p, ".") {
		p += "."
	}
	return fmt.Sprintf(`%stenant_id = $1 AND (
		%s scope_visibility = 'tenant'
		OR (%sscope_visibility = 'subtenant' AND %ssubtenant_id = $2)
		OR (%sscope_visibility = 'project' AND (%sproject_id = $3 OR ($3::bigint IS NULL AND %sproject_id IS NULL)))
	)`, p, p, p, p, p, p, p)
}

func VisibleArgs(s *repository.SessionRecord) []any {
	var project any
	if s.ProjectID.Valid {
		project = s.ProjectID.Int64
	}
	return []any{s.TenantID, s.SubtenantID, project}
}

func SlugCode(typ, name string) string {
	re := regexp.MustCompile(`[^a-z0-9]+`)
	base := re.ReplaceAllString(strings.ToLower(strings.TrimSpace(name)), "-")
	base = strings.Trim(base, "-")
	if base == "" {
		base = "n"
	}
	if len(base) > 24 {
		base = base[:24]
	}
	return strings.ToLower(typ) + "-" + base
}

func ParseQty(v any) (string, bool) {
	if v == nil {
		return "", false
	}
	s := strings.TrimSpace(fmt.Sprint(v))
	if s == "" || s == "<nil>" {
		return "", false
	}
	if _, err := strconv.ParseFloat(s, 64); err != nil {
		return "", false
	}
	return s, true
}

func QtyFloat(s string) float64 {
	f, err := strconv.ParseFloat(strings.TrimSpace(s), 64)
	if err != nil {
		return 0
	}
	return f
}

func FormatQty(f float64) string {
	s := strconv.FormatFloat(f, 'f', 6, 64)
	s = strings.TrimRight(strings.TrimRight(s, "0"), ".")
	if s == "" || s == "-" {
		return "0"
	}
	return s
}

func QtyNeg(s string) string {
	s = strings.TrimSpace(s)
	if strings.HasPrefix(s, "-") {
		return strings.TrimPrefix(s, "-")
	}
	if QtyCmp(s, "0") == 0 {
		return "0"
	}
	return "-" + s
}

func QtyAdd(a, b string) string {
	return FormatQty(QtyFloat(a) + QtyFloat(b))
}

func QtyCmp(a, b string) int {
	d := QtyFloat(a) - QtyFloat(b)
	if math.Abs(d) < 1e-9 {
		return 0
	}
	if d < 0 {
		return -1
	}
	return 1
}

func NullInt(n sql.NullInt64) any {
	if n.Valid {
		return n.Int64
	}
	return nil
}

func LocalCORS() fiber.Handler {
	return func(c *fiber.Ctx) error {
		c.Set("Access-Control-Allow-Origin", "*")
		c.Set("Access-Control-Allow-Headers", "Authorization, Content-Type, Accept, X-CSRF-Token, X-Action-Token, X-Tenant-ID, X-Subtenant-ID")
		c.Set("Access-Control-Allow-Methods", "GET, POST, PATCH, PUT, DELETE, OPTIONS")
		if c.Method() == fiber.MethodOptions {
			return c.SendStatus(fiber.StatusNoContent)
		}
		return c.Next()
	}
}

func HasAdminRole(rbac *service.RbacService, s *repository.SessionRecord) bool {
	ok, _ := rbac.HasAnyRole(s.UserID, s.TenantID, s.SubtenantID, []string{
		"super_admin", "tenant_admin",
	})
	return ok
}

func ListGrantPeers(db *sql.DB, principalTenant string) ([]map[string]any, error) {
	rows, err := db.Query(`
		SELECT managed_tenant_code, grant_level, status
		FROM maniforge_tl_tenant_grants
		WHERE principal_tenant_code = $1 AND status = 'active'`, principalTenant)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var code, level, status string
		if err := rows.Scan(&code, &level, &status); err != nil {
			return nil, err
		}
		items = append(items, map[string]any{"tenant_id": code, "grant_level": level, "status": status})
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return items, nil
}
