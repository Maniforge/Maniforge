// Package products — Fiber HTTP API номенклатуры (порт PHP Products).
package products

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"regexp"
	"strings"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	"maniforge/internal/licensingclient"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
	"maniforge/internal/supplychain"
)

type Handler struct {
	db    *sql.DB
	cfg   config.Config
	rbac  *service.RbacService
	lic   *licensingclient.Client
	audit *repository.AuditRepository
}

func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{AppName: "maniforge-products", ServerHeader: "maniforge-products"})
	app.Use(recover.New(), logger.New(), middleware.SecurityHeaders(cfg))
	if cfg.AppEnv == "local" || cfg.AppEnv == "testing" || cfg.AppEnv == "test" {
		app.Use(supplychain.LocalCORS())
	}
	h := &Handler{db: sqlDB, cfg: cfg}
	if sqlDB == nil {
		app.Get("/health", h.Health)
		app.Get("/products/health", h.Health)
		app.Use(func(c *fiber.Ctx) error {
			return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{"ok": false, "error": "database unavailable"})
		})
		return app
	}
	h.rbac = service.NewRbacService(repository.NewRoleRepository(sqlDB))
	h.lic = licensingclient.New(cfg, sqlDB)
	h.audit = repository.NewAuditRepository(sqlDB)
	sessions := service.NewSessionService(cfg, sqlDB)
	auth := rbacmw.SessionAuth(sessions)
	delegated := rbacmw.DelegatedMutationGuard(cfg, sqlDB)
	register := func(router fiber.Router) {
		router.Get("/health", h.Health)
		api := router.Group("/api/v1", auth, delegated)
		api.Get("/delegation/grant-peers", h.GrantPeers)
		api.Get("/products", h.List)
		api.Post("/products", h.Create)
		api.Get("/products/by-barcode/:code", h.GetByBarcode)
		api.Get("/products/:id", h.Get)
		api.Patch("/products/:id", h.Patch)
		api.Put("/products/:id", h.Patch)
		api.Delete("/products/:id", h.Delete)
		api.Post("/products/:id/external-meta", h.BindExternal)
	}
	register(app)
	register(app.Group("/products"))
	app.Use(func(c *fiber.Ctx) error { return httpx.Fail(c, fiber.StatusNotFound, "not_found") })
	return app
}

func Listen(cfg config.Config, app *fiber.App) error {
	log.Printf("maniforge-products listening on %s (env=%s)", cfg.ProductsAddr, cfg.AppEnv)
	return app.Listen(cfg.ProductsAddr)
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{"ok": true, "service": "products"})
}

func (h *Handler) guard(c *fiber.Ctx, perm string) (*repository.SessionRecord, error) {
	return supplychain.Guard(c, h.rbac, h.lic, perm)
}

func (h *Handler) GrantPeers(c *fiber.Ctx) error {
	sess, err := h.guard(c, "products.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	if !supplychain.HasAdminRole(h.rbac, sess) {
		return httpx.Fail(c, fiber.StatusForbidden, "Требуется tenant_admin")
	}
	items, err := supplychain.ListGrantPeers(h.db, sess.TenantID)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) List(c *fiber.Ctx) error {
	sess, err := h.guard(c, "products.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	status := c.Query("status", "active")
	search := strings.TrimSpace(c.Query("search"))
	args := supplychain.VisibleArgs(sess)
	q := `SELECT id, tenant_id, subtenant_id, project_id, scope_visibility, code, barcode_ean13, name, unit, description, attributes_json, status, created_at
		FROM maniforge_products WHERE ` + supplychain.VisibleSQL("") + ` AND status=$4`
	args = append(args, status)
	if search != "" {
		q += ` AND (name ILIKE $5 OR code ILIKE $5 OR barcode_ean13 = $6)`
		args = append(args, "%"+search+"%", search)
	}
	q += ` ORDER BY id`
	rows, err := h.db.Query(q, args...)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		item, err := scanProduct(rows)
		if err != nil {
			return httpx.Fail(c, 500, err.Error())
		}
		items = append(items, item)
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) Create(c *fiber.Ctx) error {
	sess, err := h.guard(c, "products.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	in := supplychain.Body(c)
	name := supplychain.Str(in, "name")
	if name == "" {
		return httpx.Fail(c, 422, "name обязателен")
	}
	code := strings.ToLower(supplychain.Str(in, "code"))
	if code == "" {
		code = supplychain.SlugCode("sku", name) + "-" + fmt.Sprintf("%d", time.Now().UnixNano()%100000)
	}
	unit := supplychain.Str(in, "unit")
	if unit == "" {
		unit = "pcs"
	}
	desc := supplychain.Str(in, "description")
	scope := supplychain.ScopeFromSession(sess)
	ean, eanErr := parseOptionalEAN(in)
	if eanErr != nil {
		return httpx.Fail(c, 422, eanErr.Error())
	}
	var attrs []byte
	if a, ok := in["attributes"]; ok && a != nil {
		attrs, _ = json.Marshal(a)
	}
	var id int64
	err = h.db.QueryRow(`
		INSERT INTO maniforge_products (
			tenant_id, subtenant_id, project_id, scope_visibility, code, barcode_ean13, name, unit, description, attributes_json, created_by
		) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11) RETURNING id`,
		scope.TenantID, scope.SubtenantID, nullInt(scope.ProjectID), scope.Visibility,
		code, ean, name, unit, nullStr(desc), nullJSON(attrs), sess.UserID,
	).Scan(&id)
	if err != nil {
		if strings.Contains(strings.ToLower(err.Error()), "unique") {
			return supplychain.Result(c, map[string]any{"ok": false, "error": "code или EAN-13 уже занят", "code": "duplicate"}, 409)
		}
		return httpx.Fail(c, 500, err.Error())
	}
	h.auditWrite(sess, "products.created", id, map[string]any{"code": code})
	p, _ := h.findVisible(sess, id)
	return httpx.JSON(c, 201, fiber.Map{"ok": true, "product": p})
}

func (h *Handler) Get(c *fiber.Ctx) error {
	sess, err := h.guard(c, "products.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	p, err := h.findVisible(sess, int64(id))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	if p == nil {
		return httpx.Fail(c, 404, "Товар не найден")
	}
	return httpx.OK(c, fiber.Map{"ok": true, "product": p})
}

func (h *Handler) GetByBarcode(c *fiber.Ctx) error {
	sess, err := h.guard(c, "products.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	parsed := NormalizeEAN13(c.Params("code"))
	if !parsed.OK {
		return httpx.Fail(c, 422, parsed.Error)
	}
	args := append(supplychain.VisibleArgs(sess), parsed.EAN13)
	p, err := scanProduct(h.db.QueryRow(`
		SELECT id, tenant_id, subtenant_id, project_id, scope_visibility, code, barcode_ean13, name, unit, description, attributes_json, status, created_at
		FROM maniforge_products WHERE `+supplychain.VisibleSQL("")+` AND barcode_ean13=$4 AND status='active'`, args...))
	if err == sql.ErrNoRows {
		return httpx.Fail(c, 404, "Товар с таким EAN-13 не найден")
	}
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "kind": "product", "barcode_type": "ean13", "barcode": parsed.EAN13, "product": p})
}

func (h *Handler) Patch(c *fiber.Ctx) error {
	sess, err := h.guard(c, "products.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	p, _ := h.findVisible(sess, int64(id))
	if p == nil {
		return httpx.Fail(c, 404, "Товар не найден")
	}
	in := supplychain.Body(c)
	name := supplychain.Str(in, "name")
	if name == "" {
		name = fmt.Sprint(p["name"])
	}
	unit := supplychain.Str(in, "unit")
	if unit == "" {
		unit = fmt.Sprint(p["unit"])
	}
	desc := supplychain.Str(in, "description")
	ean := p["barcode_ean13"]
	if _, has := in["barcode_ean13"]; has || in["ean13"] != nil || in["barcode"] != nil {
		parsed, e := parseOptionalEAN(in)
		if e != nil {
			return httpx.Fail(c, 422, e.Error())
		}
		if parsed != nil {
			ean = parsed
		} else {
			ean = nil
		}
	}
	_, err = h.db.Exec(`UPDATE maniforge_products SET name=$1, unit=$2, description=$3, barcode_ean13=$4, updated_by=$5, updated_at=NOW() WHERE id=$6`,
		name, unit, nullStr(desc), ean, sess.UserID, id)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	fresh, _ := h.findVisible(sess, int64(id))
	return httpx.OK(c, fiber.Map{"ok": true, "product": fresh})
}

func (h *Handler) Delete(c *fiber.Ctx) error {
	sess, err := h.guard(c, "products.delete")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	p, _ := h.findVisible(sess, int64(id))
	if p == nil {
		return httpx.Fail(c, 404, "Товар не найден")
	}
	_, err = h.db.Exec(`UPDATE maniforge_products SET status='archived', updated_by=$1, updated_at=NOW() WHERE id=$2`, sess.UserID, id)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "archived": true, "id": id})
}

func (h *Handler) BindExternal(c *fiber.Ctx) error {
	sess, err := h.guard(c, "products.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	p, _ := h.findVisible(sess, int64(id))
	if p == nil {
		return httpx.Fail(c, 404, "Товар не найден")
	}
	in := supplychain.Body(c)
	extType := supplychain.Str(in, "type")
	extID := supplychain.Str(in, "external_id", "meta")
	if extType == "" || extID == "" {
		return httpx.Fail(c, 422, "type и external_id обязательны")
	}
	b, _ := json.Marshal(map[string]any{"type": extType, "external_id": extID})
	_, err = h.db.Exec(`UPDATE maniforge_products SET attributes_json = COALESCE(attributes_json,'{}'::jsonb) || jsonb_build_object('external_meta', $1::jsonb), updated_by=$2, updated_at=NOW() WHERE id=$3`,
		string(b), sess.UserID, id)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "product_id": id, "external_type": extType, "external_id": extID})
}

func (h *Handler) findVisible(sess *repository.SessionRecord, id int64) (map[string]any, error) {
	args := append(supplychain.VisibleArgs(sess), id)
	p, err := scanProduct(h.db.QueryRow(`
		SELECT id, tenant_id, subtenant_id, project_id, scope_visibility, code, barcode_ean13, name, unit, description, attributes_json, status, created_at
		FROM maniforge_products WHERE `+supplychain.VisibleSQL("")+` AND id=$4`, args...))
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return p, err
}

func (h *Handler) auditWrite(sess *repository.SessionRecord, event string, id int64, extra map[string]any) {
	if h.audit == nil {
		return
	}
	payload := map[string]any{"product_id": id}
	for k, v := range extra {
		payload[k] = v
	}
	uid := sess.UserID
	_ = h.audit.Write(event, &uid, sess.TenantID, sess.SubtenantID, payload)
}

type scanner interface{ Scan(dest ...any) error }

func scanProduct(s scanner) (map[string]any, error) {
	var id int64
	var tenant, sub, vis, code, name, unit, status string
	var project sql.NullInt64
	var ean, desc sql.NullString
	var attrs []byte
	var created time.Time
	if err := s.Scan(&id, &tenant, &sub, &project, &vis, &code, &ean, &name, &unit, &desc, &attrs, &status, &created); err != nil {
		return nil, err
	}
	item := map[string]any{
		"id": id, "tenant_id": tenant, "subtenant_id": sub, "scope_visibility": vis,
		"code": code, "name": name, "unit": unit, "status": status,
		"project_id": supplychain.NullInt(project),
		"created_at": created.UTC().Format(time.RFC3339),
	}
	if ean.Valid {
		item["barcode_ean13"] = ean.String
		item["barcode"] = map[string]any{"type": "ean13", "value": ean.String}
	} else {
		item["barcode_ean13"] = nil
	}
	if desc.Valid {
		item["description"] = desc.String
	}
	if len(attrs) > 0 {
		var a any
		_ = json.Unmarshal(attrs, &a)
		item["attributes"] = a
	}
	return item, nil
}

func parseOptionalEAN(in map[string]any) (any, error) {
	raw := supplychain.Str(in, "barcode_ean13", "ean13", "barcode")
	if raw == "" {
		return nil, nil
	}
	p := NormalizeEAN13(raw)
	if !p.OK {
		return nil, fmt.Errorf("%s", p.Error)
	}
	return p.EAN13, nil
}

type EANResult struct {
	OK    bool
	EAN13 string
	Error string
}

func NormalizeEAN13(raw string) EANResult {
	re := regexp.MustCompile(`\D`)
	digits := re.ReplaceAllString(strings.TrimSpace(raw), "")
	if digits == "" {
		return EANResult{Error: "Пустой штрихкод"}
	}
	if len(digits) == 12 {
		digits = "0" + digits
	}
	if len(digits) != 13 {
		return EANResult{Error: "EAN-13: ожидается 13 цифр (или 12 для UPC-A)"}
	}
	if !validEAN13(digits) {
		return EANResult{Error: "EAN-13: неверная контрольная цифра"}
	}
	return EANResult{OK: true, EAN13: digits}
}

func validEAN13(ean string) bool {
	if len(ean) != 13 {
		return false
	}
	sum := 0
	for i := 0; i < 12; i++ {
		d := int(ean[i] - '0')
		if ean[i] < '0' || ean[i] > '9' {
			return false
		}
		if i%2 == 0 {
			sum += d
		} else {
			sum += d * 3
		}
	}
	check := (10 - (sum % 10)) % 10
	return check == int(ean[12]-'0')
}

func MakeEAN13(body12 string) string {
	re := regexp.MustCompile(`\D`)
	d := re.ReplaceAllString(body12, "")
	if len(d) > 12 {
		d = d[:12]
	}
	for len(d) < 12 {
		d += "0"
	}
	sum := 0
	for i := 0; i < 12; i++ {
		n := int(d[i] - '0')
		if i%2 == 0 {
			sum += n
		} else {
			sum += n * 3
		}
	}
	return d + fmt.Sprintf("%d", (10-(sum%10))%10)
}

func nullInt(n sql.NullInt64) any {
	if n.Valid {
		return n.Int64
	}
	return nil
}
func nullStr(s string) any {
	if s == "" {
		return nil
	}
	return s
}
func nullJSON(b []byte) any {
	if len(b) == 0 {
		return nil
	}
	return string(b)
}
