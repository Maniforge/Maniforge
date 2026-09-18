// Package wms — Fiber HTTP API упаковок, КИЗ и сканирования (порт PHP Wms).
package wms

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"fmt"
	"log"
	"strings"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	"maniforge/internal/inventory"
	"maniforge/internal/licensingclient"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	"maniforge/internal/products"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
	"maniforge/internal/supplychain"
)

var packTypes = map[string]bool{"consumer": true, "group": true, "pallet": true, "sscc": true}

type Handler struct {
	db   *sql.DB
	cfg  config.Config
	rbac *service.RbacService
	lic  *licensingclient.Client
	inv  *inventory.Engine
}

func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{AppName: "maniforge-wms", ServerHeader: "maniforge-wms"})
	app.Use(recover.New(), logger.New(), middleware.SecurityHeaders(cfg))
	if cfg.AppEnv == "local" || cfg.AppEnv == "testing" || cfg.AppEnv == "test" {
		app.Use(supplychain.LocalCORS())
	}
	h := &Handler{db: sqlDB, cfg: cfg}
	if sqlDB == nil {
		app.Get("/health", h.Health)
		app.Get("/wms/health", h.Health)
		app.Use(func(c *fiber.Ctx) error {
			return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{"ok": false, "error": "database unavailable"})
		})
		return app
	}
	h.rbac = service.NewRbacService(repository.NewRoleRepository(sqlDB))
	h.lic = licensingclient.New(cfg, sqlDB)
	h.inv = inventory.NewEngine(sqlDB)
	sessions := service.NewSessionService(cfg, sqlDB)
	auth := rbacmw.SessionAuth(sessions)
	delegated := rbacmw.DelegatedMutationGuard(cfg, sqlDB)
	register := func(router fiber.Router) {
		router.Get("/health", h.Health)
		api := router.Group("/api/v1", auth, delegated)
		api.Get("/packs", h.ListPacks)
		api.Post("/packs", h.CreatePack)
		api.Get("/packs/:id", h.GetPack)
		api.Delete("/packs/:id", h.DeletePack)
		api.Post("/packs/:id/seal", h.SealPack)
		api.Post("/packs/:id/disaggregate", h.Disaggregate)
		api.Post("/packs/:id/markings", h.AddMarking)
		api.Post("/packs/:id/children", h.AddChild)
		api.Get("/markings", h.ListMarkings)
		api.Post("/markings", h.RegisterMarking)
		api.Post("/markings/bulk", h.BulkMarkings)
		api.Get("/markings/:id/trace", h.TraceMarking)
		api.Get("/markings/:id", h.GetMarking)
		api.Get("/scan", h.Scan)
		api.Post("/scan", h.Scan)
		api.Post("/movements/scan", h.ScanMovement)
	}
	register(app)
	register(app.Group("/wms"))
	app.Use(func(c *fiber.Ctx) error { return httpx.Fail(c, fiber.StatusNotFound, "not_found") })
	return app
}

func Listen(cfg config.Config, app *fiber.App) error {
	addr := cfg.WmsAddr
	if addr == "" {
		addr = ":8101"
	}
	log.Printf("maniforge-wms listening on %s (env=%s)", addr, cfg.AppEnv)
	return app.Listen(addr)
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{"ok": true, "service": "wms"})
}

func (h *Handler) guard(c *fiber.Ctx, perm string) (*repository.SessionRecord, error) {
	return supplychain.Guard(c, h.rbac, h.lic, perm)
}

func (h *Handler) ListPacks(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	rows, err := h.db.Query(`SELECT id FROM maniforge_wms_pack_units WHERE `+supplychain.VisibleSQL("")+` ORDER BY id DESC LIMIT 100`,
		supplychain.VisibleArgs(sess)...)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return httpx.Fail(c, 500, err.Error())
		}
		p, _ := h.findPack(sess, id)
		if p != nil {
			items = append(items, p)
		}
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) CreatePack(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	in := supplychain.Body(c)
	unit := strings.ToLower(supplychain.Str(in, "unit_type", "unitType"))
	if !packTypes[unit] {
		return httpx.Fail(c, 422, "unit_type: consumer|group|pallet|sscc")
	}
	code := supplychain.Str(in, "code")
	if code == "" {
		code = unit + "-" + randHex(8)
	}
	scope := supplychain.ScopeFromSession(sess)
	sscc := supplychain.Str(in, "sscc")
	if (unit == "pallet" || unit == "sscc") && sscc == "" {
		sscc = "00" + randHex(9)
		if len(sscc) > 18 {
			sscc = sscc[:18]
		}
	}
	lookup := "pack:" + strings.ToLower(code)
	stockID := supplychain.Int64(in, "stock_id")
	productID := supplychain.Int64(in, "product_id")
	var id int64
	err = h.db.QueryRow(`
		INSERT INTO maniforge_wms_pack_units (
			tenant_id, subtenant_id, project_id, scope_visibility, unit_type, code, sscc, qr_lookup, stock_id, product_id, created_by
		) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11) RETURNING id`,
		scope.TenantID, scope.SubtenantID, nullInt(scope.ProjectID), scope.Visibility,
		unit, code, nullStr(sscc), lookup, nullPos(stockID), nullPos(productID), sess.UserID,
	).Scan(&id)
	if err != nil {
		if strings.Contains(strings.ToLower(err.Error()), "unique") {
			return supplychain.Result(c, map[string]any{"ok": false, "error": "code уже занят", "code": "duplicate"}, 409)
		}
		return httpx.Fail(c, 500, err.Error())
	}
	pack, _ := h.findPack(sess, id)
	return httpx.JSON(c, 201, fiber.Map{"ok": true, "pack": pack})
}

func (h *Handler) GetPack(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	pack, _ := h.findPack(sess, int64(id))
	if pack == nil {
		return httpx.Fail(c, 404, "Не найдено")
	}
	contents, _ := h.listContents(int64(id))
	markings, _ := h.listMarkingsByPack(int64(id))
	return httpx.OK(c, fiber.Map{"ok": true, "pack": pack, "contents": contents, "markings": markings})
}

func (h *Handler) DeletePack(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	pack, _ := h.findPack(sess, int64(id))
	if pack == nil {
		return httpx.Fail(c, 404, "Не найдено")
	}
	if fmt.Sprint(pack["tenant_id"]) != sess.TenantID {
		return httpx.Fail(c, 403, "Удаление только в tenant владельца")
	}
	if fmt.Sprint(pack["status"]) != "draft" {
		return httpx.Fail(c, 422, "Удалять можно только draft")
	}
	tx, err := h.db.Begin()
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	defer func() { _ = tx.Rollback() }()
	_, _ = tx.Exec(`UPDATE maniforge_wms_marking_codes SET pack_unit_id=NULL, status='available' WHERE pack_unit_id=$1`, id)
	if _, err := tx.Exec(`DELETE FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id=$1`, id); err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	if _, err := tx.Exec(`DELETE FROM maniforge_wms_pack_units WHERE id=$1 AND tenant_id=$2 AND status='draft'`, id, sess.TenantID); err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	if err := tx.Commit(); err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "deleted": true, "id": id})
}

func (h *Handler) SealPack(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	pack, _ := h.findPack(sess, int64(id))
	if pack == nil || fmt.Sprint(pack["tenant_id"]) != sess.TenantID {
		return httpx.Fail(c, 404, "Упаковка не найдена")
	}
	var n int
	_ = h.db.QueryRow(`SELECT COUNT(*) FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id=$1`, id).Scan(&n)
	if n < 1 {
		return httpx.Fail(c, 422, "Пустая упаковка")
	}
	_, err = h.db.Exec(`UPDATE maniforge_wms_pack_units SET status='sealed', sealed_at=NOW(), sealed_by=$1, updated_at=NOW() WHERE id=$2`, sess.UserID, id)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	unit := fmt.Sprint(pack["unit_type"])
	if unit == "pallet" || unit == "sscc" {
		qr := fmt.Sprintf(`{"kind":"maniforge_wms_pack","pack_id":%d,"code":%q}`, id, pack["code"])
		lookup := "qr:" + randHex(12)
		_, _ = h.db.Exec(`UPDATE maniforge_wms_pack_units SET qr_payload=$1, qr_lookup=$2 WHERE id=$3`, qr, lookup, id)
	}
	fresh, _ := h.findPack(sess, int64(id))
	return httpx.OK(c, fiber.Map{"ok": true, "pack": fresh})
}

func (h *Handler) Disaggregate(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	pack, _ := h.findPack(sess, int64(id))
	if pack == nil {
		return httpx.Fail(c, 404, "Упаковка не найдена")
	}
	if fmt.Sprint(pack["status"]) != "sealed" {
		return httpx.Fail(c, 422, "Только sealed можно разобрать")
	}
	_, _ = h.db.Exec(`UPDATE maniforge_wms_marking_codes SET status='available', pack_unit_id=NULL WHERE pack_unit_id=$1`, id)
	_, err = h.db.Exec(`UPDATE maniforge_wms_pack_units SET status='draft', sealed_at=NULL, sealed_by=NULL, updated_at=NOW() WHERE id=$1`, id)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	fresh, _ := h.findPack(sess, int64(id))
	return httpx.OK(c, fiber.Map{"ok": true, "pack": fresh, "disaggregated": true})
}

func (h *Handler) AddMarking(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	pack, _ := h.findPack(sess, int64(id))
	if pack == nil || fmt.Sprint(pack["tenant_id"]) != sess.TenantID {
		return httpx.Fail(c, 404, "Упаковка не найдена")
	}
	if fmt.Sprint(pack["status"]) != "draft" {
		return supplychain.Result(c, map[string]any{"ok": false, "error": "Только draft упаковка", "code": "pack_sealed"}, 422)
	}
	unit := fmt.Sprint(pack["unit_type"])
	if unit != "group" && unit != "consumer" {
		return httpx.Fail(c, 422, "КИЗ добавляются в group/consumer")
	}
	in := supplychain.Body(c)
	markID := supplychain.Int64(in, "marking_code_id")
	code := supplychain.Str(in, "code")
	if markID <= 0 && code != "" {
		m, _ := h.findMarkingByCode(sess.TenantID, code)
		if m == nil {
			return httpx.Fail(c, 404, "КИЗ не найден — сначала register")
		}
		markID = supplychain.AsInt64(m["id"])
	}
	if markID <= 0 {
		return httpx.Fail(c, 422, "marking_code_id или code")
	}
	m, _ := h.findMarking(sess, markID)
	if m == nil || fmt.Sprint(m["status"]) != "available" {
		return supplychain.Result(c, map[string]any{"ok": false, "error": "КИЗ недоступен", "code": "marking_unavailable"}, 422)
	}
	var lineNo int
	_ = h.db.QueryRow(`SELECT COALESCE(MAX(line_no),0)+1 FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id=$1`, id).Scan(&lineNo)
	if _, err := h.db.Exec(`INSERT INTO maniforge_wms_pack_contents (parent_pack_unit_id, line_no, marking_code_id, qty) VALUES ($1,$2,$3,1)`, id, lineNo, markID); err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	_, _ = h.db.Exec(`UPDATE maniforge_wms_marking_codes SET status='in_group', pack_unit_id=$1 WHERE id=$2`, id, markID)
	contents, _ := h.listContents(int64(id))
	return httpx.OK(c, fiber.Map{"ok": true, "pack_id": id, "marking_code_id": markID, "contents": contents})
}

func (h *Handler) AddChild(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	parent, _ := h.findPack(sess, int64(id))
	if parent == nil || fmt.Sprint(parent["tenant_id"]) != sess.TenantID {
		return httpx.Fail(c, 404, "Родитель не найден")
	}
	if fmt.Sprint(parent["status"]) != "draft" {
		return httpx.Fail(c, 422, "Родитель должен быть draft")
	}
	in := supplychain.Body(c)
	childID := supplychain.Int64(in, "child_pack_unit_id", "child_pack_id")
	child, _ := h.findPack(sess, childID)
	if child == nil {
		return httpx.Fail(c, 404, "Дочерняя упаковка не найдена")
	}
	if !canContain(fmt.Sprint(parent["unit_type"]), fmt.Sprint(child["unit_type"])) {
		return httpx.Fail(c, 422, "Недопустимая вложенность")
	}
	if fmt.Sprint(child["status"]) != "sealed" {
		return httpx.Fail(c, 422, "Дочерняя упаковка должна быть sealed")
	}
	var lineNo int
	_ = h.db.QueryRow(`SELECT COALESCE(MAX(line_no),0)+1 FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id=$1`, id).Scan(&lineNo)
	if _, err := h.db.Exec(`INSERT INTO maniforge_wms_pack_contents (parent_pack_unit_id, line_no, child_pack_unit_id, qty) VALUES ($1,$2,$3,1)`, id, lineNo, childID); err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	contents, _ := h.listContents(int64(id))
	return httpx.OK(c, fiber.Map{"ok": true, "contents": contents})
}

func (h *Handler) ListMarkings(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	q := `SELECT id FROM maniforge_wms_marking_codes WHERE tenant_id=$1`
	args := []any{sess.TenantID}
	if pid := c.Query("product_id"); pid != "" {
		q += ` AND product_id=$2`
		args = append(args, supplychain.AsInt64(pid))
	}
	q += ` ORDER BY id DESC LIMIT 100`
	rows, err := h.db.Query(q, args...)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var id int64
		_ = rows.Scan(&id)
		m, _ := h.findMarking(sess, id)
		if m != nil {
			items = append(items, m)
		}
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) RegisterMarking(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	payload, status := h.registerOne(sess, supplychain.Body(c))
	return supplychain.Result(c, payload, status)
}

func (h *Handler) BulkMarkings(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	in := supplychain.Body(c)
	raw, _ := in["items"].([]any)
	if len(raw) == 0 {
		if codes, ok := in["codes"].([]any); ok {
			for _, cde := range codes {
				raw = append(raw, map[string]any{"code": fmt.Sprint(cde), "product_id": in["product_id"]})
			}
		}
	}
	if len(raw) == 0 {
		return httpx.Fail(c, 422, "items или codes обязательны")
	}
	created := []map[string]any{}
	for _, r := range raw {
		row, _ := r.(map[string]any)
		if row == nil {
			continue
		}
		if _, has := row["product_id"]; !has {
			row["product_id"] = in["product_id"]
		}
		p, st := h.registerOne(sess, row)
		if st >= 300 {
			return supplychain.Result(c, p, st)
		}
		if m, ok := p["marking"].(map[string]any); ok {
			created = append(created, m)
		}
	}
	return httpx.JSON(c, 201, fiber.Map{"ok": true, "items": created})
}

func (h *Handler) GetMarking(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	m, _ := h.findMarking(sess, int64(id))
	if m == nil {
		return httpx.Fail(c, 404, "КИЗ не найден")
	}
	return httpx.OK(c, fiber.Map{"ok": true, "marking": m})
}

func (h *Handler) TraceMarking(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	m, _ := h.findMarking(sess, int64(id))
	if m == nil {
		return httpx.Fail(c, 404, "КИЗ не найден")
	}
	var pack any
	if pid := supplychain.AsInt64(m["pack_unit_id"]); pid > 0 {
		pack, _ = h.findPack(sess, pid)
	}
	return httpx.OK(c, fiber.Map{"ok": true, "marking": m, "pack": pack})
}

func (h *Handler) Scan(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	code := strings.TrimSpace(c.Query("code"))
	if code == "" {
		code = supplychain.Str(supplychain.Body(c), "code")
	}
	if code == "" {
		return httpx.Fail(c, 422, "code обязателен")
	}
	if m, _ := h.findMarkingByCode(sess.TenantID, code); m != nil {
		return httpx.OK(c, fiber.Map{"ok": true, "kind": "marking", "marking": m})
	}
	if p, _ := h.findPackByCode(sess, code); p != nil {
		contents, _ := h.listContents(supplychain.AsInt64(p["id"]))
		return httpx.OK(c, fiber.Map{"ok": true, "kind": "pack", "pack": p, "contents": contents})
	}
	ean := products.NormalizeEAN13(code)
	if ean.OK {
		var pid int64
		err := h.db.QueryRow(`SELECT id FROM maniforge_products WHERE tenant_id=$1 AND barcode_ean13=$2 AND status='active'`, sess.TenantID, ean.EAN13).Scan(&pid)
		if err == nil {
			return httpx.OK(c, fiber.Map{"ok": true, "kind": "product", "product_id": pid, "barcode": ean.EAN13})
		}
	}
	return httpx.Fail(c, 404, "Код не найден")
}

func (h *Handler) ScanMovement(c *fiber.Ctx) error {
	sess, err := h.guard(c, "wms.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	in := supplychain.Body(c)
	typ := strings.ToLower(supplychain.Str(in, "movement_type", "type"))
	if typ == "" {
		typ = "receipt"
	}
	stockID := supplychain.Int64(in, "stock_id")
	if stockID <= 0 {
		return httpx.Fail(c, 422, "stock_id обязателен")
	}
	packID := supplychain.Int64(in, "pack_unit_id", "pack_id")
	if packID == 0 {
		code := supplychain.Str(in, "code")
		if p, _ := h.findPackByCode(sess, code); p != nil {
			packID = supplychain.AsInt64(p["id"])
		}
		if m, _ := h.findMarkingByCode(sess.TenantID, code); m != nil && packID == 0 {
			in["product_id"] = m["product_id"]
			in["qty"] = "1"
			in["stock_id"] = stockID
			in["movement_type"] = typ
			in["metadata"] = map[string]any{"marking_code_id": m["id"], "scan": true}
			payload, status := h.inv.Post(sess, in, true)
			return supplychain.Result(c, payload, status)
		}
	}
	if packID <= 0 {
		return httpx.Fail(c, 422, "pack_unit_id или code")
	}
	pack, _ := h.findPack(sess, packID)
	if pack == nil {
		return httpx.Fail(c, 404, "Упаковка не найдена")
	}
	productID := supplychain.AsInt64(pack["product_id"])
	if productID == 0 {
		productID = supplychain.Int64(in, "product_id")
	}
	qty := "1"
	if q, ok := supplychain.ParseQty(in["qty"]); ok {
		qty = q
	}
	var n int
	_ = h.db.QueryRow(`SELECT COUNT(*) FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id=$1`, packID).Scan(&n)
	if n > 0 {
		qty = fmt.Sprintf("%d", n)
	}
	if productID <= 0 {
		return httpx.Fail(c, 422, "product_id обязателен для scan movement")
	}
	payload, status := h.inv.Post(sess, map[string]any{
		"movement_type": typ,
		"product_id":    productID,
		"stock_id":      stockID,
		"qty":           qty,
		"metadata":      map[string]any{"pack_unit_id": packID, "scan": true},
	}, true)
	return supplychain.Result(c, payload, status)
}

func (h *Handler) registerOne(sess *repository.SessionRecord, in map[string]any) (map[string]any, int) {
	productID := supplychain.Int64(in, "product_id")
	code := supplychain.Str(in, "code", "code_full")
	if productID <= 0 || code == "" {
		return map[string]any{"ok": false, "error": "product_id и code обязательны"}, 422
	}
	var tenant string
	err := h.db.QueryRow(`SELECT tenant_id FROM maniforge_products WHERE id=$1`, productID).Scan(&tenant)
	if err != nil || tenant != sess.TenantID {
		return map[string]any{"ok": false, "error": "Товар не найден"}, 404
	}
	if existing, _ := h.findMarkingByCode(sess.TenantID, code); existing != nil {
		return map[string]any{"ok": false, "error": "КИЗ уже зарегистрирован", "code": "duplicate_marking"}, 409
	}
	codeType := strings.ToLower(supplychain.Str(in, "code_type"))
	if codeType == "" {
		codeType = "kiz"
	}
	var id int64
	err = h.db.QueryRow(`
		INSERT INTO maniforge_wms_marking_codes (tenant_id, product_id, code_full, code_type, status)
		VALUES ($1,$2,$3,$4,'available') RETURNING id`, sess.TenantID, productID, code, codeType).Scan(&id)
	if err != nil {
		if strings.Contains(strings.ToLower(err.Error()), "unique") {
			return map[string]any{"ok": false, "error": "КИЗ уже зарегистрирован", "code": "duplicate_marking"}, 409
		}
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	m, _ := h.findMarking(sess, id)
	return map[string]any{"ok": true, "marking": m}, 201
}

func (h *Handler) findPack(sess *repository.SessionRecord, id int64) (map[string]any, error) {
	args := append(supplychain.VisibleArgs(sess), id)
	return scanPack(h.db.QueryRow(`
		SELECT id, tenant_id, subtenant_id, project_id, scope_visibility, unit_type, code, sscc, qr_payload, qr_lookup, stock_id, product_id, status, created_at
		FROM maniforge_wms_pack_units WHERE `+supplychain.VisibleSQL("")+` AND id=$4`, args...))
}

func (h *Handler) findPackByCode(sess *repository.SessionRecord, code string) (map[string]any, error) {
	var id int64
	err := h.db.QueryRow(`SELECT id FROM maniforge_wms_pack_units WHERE tenant_id=$1 AND (code=$2 OR sscc=$2 OR qr_lookup=$2) LIMIT 1`, sess.TenantID, code).Scan(&id)
	if err != nil {
		return nil, err
	}
	return h.findPack(sess, id)
}

func (h *Handler) findMarking(sess *repository.SessionRecord, id int64) (map[string]any, error) {
	return scanMarking(h.db.QueryRow(`
		SELECT id, tenant_id, product_id, code_full, code_type, gtin, serial_number, status, pack_unit_id, stock_id, created_at
		FROM maniforge_wms_marking_codes WHERE id=$1 AND tenant_id=$2`, id, sess.TenantID))
}

func (h *Handler) findMarkingByCode(tenantID, code string) (map[string]any, error) {
	return scanMarking(h.db.QueryRow(`
		SELECT id, tenant_id, product_id, code_full, code_type, gtin, serial_number, status, pack_unit_id, stock_id, created_at
		FROM maniforge_wms_marking_codes WHERE tenant_id=$1 AND code_full=$2`, tenantID, code))
}

func (h *Handler) listContents(packID int64) ([]map[string]any, error) {
	rows, err := h.db.Query(`SELECT line_no, child_pack_unit_id, marking_code_id, qty::text FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id=$1 ORDER BY line_no`, packID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var no int
		var child, mark sql.NullInt64
		var qty string
		if err := rows.Scan(&no, &child, &mark, &qty); err != nil {
			return nil, err
		}
		item := map[string]any{"line_no": no, "qty": qty}
		if child.Valid {
			item["child_pack_unit_id"] = child.Int64
		}
		if mark.Valid {
			item["marking_code_id"] = mark.Int64
		}
		items = append(items, item)
	}
	return items, nil
}

func (h *Handler) listMarkingsByPack(packID int64) ([]map[string]any, error) {
	rows, err := h.db.Query(`SELECT id FROM maniforge_wms_marking_codes WHERE pack_unit_id=$1`, packID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var id int64
		_ = rows.Scan(&id)
		m, _ := scanMarking(h.db.QueryRow(`
			SELECT id, tenant_id, product_id, code_full, code_type, gtin, serial_number, status, pack_unit_id, stock_id, created_at
			FROM maniforge_wms_marking_codes WHERE id=$1`, id))
		if m != nil {
			items = append(items, m)
		}
	}
	return items, nil
}

func canContain(parent, child string) bool {
	switch parent {
	case "group":
		return child == "consumer" || child == "group"
	case "pallet", "sscc":
		return child == "group" || child == "consumer" || child == "pallet"
	default:
		return false
	}
}

type scanner interface{ Scan(dest ...any) error }

func scanPack(s scanner) (map[string]any, error) {
	var id int64
	var tenant, sub, vis, unit, code, lookup, status string
	var project, stock, product sql.NullInt64
	var sscc, qr sql.NullString
	var created time.Time
	if err := s.Scan(&id, &tenant, &sub, &project, &vis, &unit, &code, &sscc, &qr, &lookup, &stock, &product, &status, &created); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		return nil, err
	}
	item := map[string]any{
		"id": id, "tenant_id": tenant, "subtenant_id": sub, "scope_visibility": vis,
		"unit_type": unit, "code": code, "qr_lookup": lookup, "status": status,
		"project_id": supplychain.NullInt(project),
		"stock_id":   supplychain.NullInt(stock),
		"product_id": supplychain.NullInt(product),
		"created_at": created.UTC().Format(time.RFC3339),
	}
	if sscc.Valid {
		item["sscc"] = sscc.String
	}
	if qr.Valid {
		item["qr_payload"] = qr.String
	}
	return item, nil
}

func scanMarking(s scanner) (map[string]any, error) {
	var id, pid int64
	var tenant, full, ctype, status string
	var gtin, serial sql.NullString
	var pack, stock sql.NullInt64
	var created time.Time
	if err := s.Scan(&id, &tenant, &pid, &full, &ctype, &gtin, &serial, &status, &pack, &stock, &created); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		return nil, err
	}
	item := map[string]any{
		"id": id, "tenant_id": tenant, "product_id": pid, "code_full": full, "code": full,
		"code_type": ctype, "status": status, "created_at": created.UTC().Format(time.RFC3339),
		"pack_unit_id": supplychain.NullInt(pack), "stock_id": supplychain.NullInt(stock),
	}
	if gtin.Valid {
		item["gtin"] = gtin.String
	}
	if serial.Valid {
		item["serial_number"] = serial.String
	}
	return item, nil
}

func randHex(n int) string {
	b := make([]byte, (n+1)/2)
	_, _ = rand.Read(b)
	s := hex.EncodeToString(b)
	if len(s) > n {
		return s[:n]
	}
	return s
}
func nullInt(n sql.NullInt64) any {
	if n.Valid {
		return n.Int64
	}
	return nil
}
func nullPos(n int64) any {
	if n > 0 {
		return n
	}
	return nil
}
func nullStr(s string) any {
	if s == "" {
		return nil
	}
	return s
}
