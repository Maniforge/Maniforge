// Package warehouses — Fiber HTTP API дерева складов (порт PHP Warehouses).
package warehouses

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
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
	app := fiber.New(fiber.Config{AppName: "maniforge-warehouses", ServerHeader: "maniforge-warehouses"})
	app.Use(recover.New(), logger.New(), middleware.SecurityHeaders(cfg))
	if cfg.AppEnv == "local" || cfg.AppEnv == "testing" || cfg.AppEnv == "test" {
		app.Use(supplychain.LocalCORS())
	}

	h := &Handler{db: sqlDB, cfg: cfg}
	if sqlDB == nil {
		app.Get("/health", h.Health)
		app.Get("/warehouses/health", h.Health)
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
		api.Get("/stock-types", h.ListTypes)
		api.Get("/stocks", h.List)
		api.Get("/stocks/tree", h.Tree)
		api.Get("/delegation/grant-peers", h.GrantPeers)
		api.Post("/stocks", h.Create)
		api.Get("/stocks/:id", h.Get)
		api.Patch("/stocks/:id", h.Patch)
		api.Put("/stocks/:id", h.Patch)
		api.Delete("/stocks/:id", h.Delete)
		api.Get("/stocks/:id/children", h.Children)
		api.Post("/stocks/:id/external-meta", h.BindExternal)
		api.Get("/stocks/:id/audit", h.Audit)
	}
	register(app)
	register(app.Group("/warehouses"))
	app.Use(func(c *fiber.Ctx) error { return httpx.Fail(c, fiber.StatusNotFound, "not_found") })
	return app
}

func Listen(cfg config.Config, app *fiber.App) error {
	log.Printf("maniforge-warehouses listening on %s (env=%s)", cfg.WarehousesAddr, cfg.AppEnv)
	return app.Listen(cfg.WarehousesAddr)
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{"ok": true, "service": "warehouses"})
}

func (h *Handler) guard(c *fiber.Ctx, perm string) (*repository.SessionRecord, error) {
	return supplychain.Guard(c, h.rbac, h.lic, perm)
}

func (h *Handler) ListTypes(c *fiber.Ctx) error {
	if _, err := h.guard(c, "warehouses.types.read"); err != nil {
		return supplychain.WriteErr(c, err)
	}
	rows, err := h.db.Query(`
		SELECT code, name, name_en, description, allowed_parents_json, data_schema_json, sort_order, active
		FROM maniforge_wh_stock_types WHERE active = TRUE ORDER BY sort_order, code`)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var code, name string
		var nameEn, desc sql.NullString
		var allowed, schema []byte
		var sort int
		var active bool
		if err := rows.Scan(&code, &name, &nameEn, &desc, &allowed, &schema, &sort, &active); err != nil {
			return httpx.Fail(c, 500, err.Error())
		}
		item := map[string]any{"code": code, "name": name, "sort_order": sort, "active": active}
		if nameEn.Valid {
			item["name_en"] = nameEn.String
		}
		if desc.Valid {
			item["description"] = desc.String
		}
		var parents any
		_ = json.Unmarshal(allowed, &parents)
		item["allowed_parents"] = parents
		var sch any
		_ = json.Unmarshal(schema, &sch)
		item["data_schema"] = sch
		items = append(items, item)
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) List(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	items, err := h.listVisible(sess, c.Query("type"), c.Query("search"), c.Query("status"), c.Query("parent_id"), c.Query("roots_only"))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) Tree(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	items, err := h.listVisible(sess, c.Query("type"), c.Query("search"), c.Query("status"), "", "")
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	byParent := map[int64][]map[string]any{}
	for _, item := range items {
		pid := int64(0)
		if item["parent_id"] != nil {
			pid = supplychain.AsInt64(item["parent_id"])
		}
		byParent[pid] = append(byParent[pid], item)
	}
	var build func(int64) []map[string]any
	build = func(parent int64) []map[string]any {
		nodes := []map[string]any{}
		for _, row := range byParent[parent] {
			id := supplychain.AsInt64(row["id"])
			row["children"] = build(id)
			nodes = append(nodes, row)
		}
		return nodes
	}
	return httpx.OK(c, fiber.Map{"ok": true, "tree": build(0), "flat_count": len(items)})
}

func (h *Handler) GrantPeers(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.read")
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

func (h *Handler) Create(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	in := supplychain.Body(c)
	name := supplychain.Str(in, "name")
	typ := strings.ToLower(supplychain.Str(in, "type"))
	if name == "" || typ == "" {
		return httpx.Fail(c, 422, "name и type обязательны")
	}
	parents, okType := h.typeParents(typ)
	if !okType {
		return supplychain.Result(c, map[string]any{"ok": false, "error": "Неизвестный тип узла", "code": "unknown_stock_type"}, 422)
	}
	var parentID sql.NullInt64
	var parent map[string]any
	if raw := supplychain.Int64(in, "parent_id"); raw > 0 {
		parentID = sql.NullInt64{Int64: raw, Valid: true}
		parent, err = h.findVisible(sess, raw)
		if err != nil || parent == nil {
			return httpx.Fail(c, 404, "Родительский узел не найден")
		}
		pType := fmt.Sprint(parent["type"])
		if !canBeChildOf(parents, &pType) {
			return supplychain.Result(c, map[string]any{
				"ok": false, "code": "invalid_parent_type",
				"error": fmt.Sprintf("Тип %s не может быть дочерним для %s", typ, pType),
			}, 422)
		}
	} else if !canBeChildOf(parents, nil) {
		return supplychain.Result(c, map[string]any{"ok": false, "error": "Для этого типа требуется parent_id", "code": "parent_required"}, 422)
	}
	scope := supplychain.ScopeFromSession(sess)
	if parent != nil {
		scope.TenantID = fmt.Sprint(parent["tenant_id"])
		scope.SubtenantID = fmt.Sprint(parent["subtenant_id"])
		scope.Visibility = fmt.Sprint(parent["scope_visibility"])
		if parent["project_id"] != nil {
			scope.ProjectID = sql.NullInt64{Int64: supplychain.AsInt64(parent["project_id"]), Valid: true}
		} else {
			scope.ProjectID = sql.NullInt64{}
		}
	}
	code := strings.ToLower(supplychain.Str(in, "code"))
	if code == "" {
		code = supplychain.SlugCode(typ, name) + "-" + fmt.Sprintf("%d", time.Now().UnixNano()%100000)
	}
	if exists, _ := h.codeTaken(scope, code); exists {
		return supplychain.Result(c, map[string]any{"ok": false, "error": "code уже занят в scope", "code": "code_exists"}, 409)
	}
	var dataJSON []byte
	if d, ok := in["data"]; ok && d != nil {
		dataJSON, _ = json.Marshal(d)
	}
	var id int64
	err = h.db.QueryRow(`
		INSERT INTO maniforge_wh_stocks (
			tenant_id, subtenant_id, project_id, scope_visibility, code, name, type, parent_id, data_json, created_by
		) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING id`,
		scope.TenantID, scope.SubtenantID, nullInt(scope.ProjectID), scope.Visibility,
		code, name, typ, nullInt(parentID), nullJSON(dataJSON), sess.UserID,
	).Scan(&id)
	if err != nil {
		if strings.Contains(err.Error(), "duplicate") || strings.Contains(err.Error(), "unique") {
			return supplychain.Result(c, map[string]any{"ok": false, "error": "code уже занят в scope", "code": "code_exists"}, 409)
		}
		return httpx.Fail(c, 500, err.Error())
	}
	h.writeAudit(sess, "warehouses.stock.created", id, map[string]any{"code": code, "type": typ})
	stock, _ := h.findVisible(sess, id)
	return httpx.JSON(c, 201, fiber.Map{"ok": true, "stock": stock})
}

func (h *Handler) Get(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	stock, err := h.findVisible(sess, int64(id))
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	if stock == nil {
		return httpx.Fail(c, 404, "Узел не найден")
	}
	return httpx.OK(c, fiber.Map{"ok": true, "stock": stock})
}

func (h *Handler) Patch(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	stock, _ := h.findVisible(sess, int64(id))
	if stock == nil {
		return httpx.Fail(c, 404, "Узел не найден")
	}
	in := supplychain.Body(c)
	name := supplychain.Str(in, "name")
	if name == "" {
		name = fmt.Sprint(stock["name"])
	}
	var dataJSON any
	if d, ok := in["data"]; ok {
		b, _ := json.Marshal(d)
		dataJSON = string(b)
	}
	if dataJSON != nil {
		_, err = h.db.Exec(`UPDATE maniforge_wh_stocks SET name=$1, data_json=$2::jsonb, updated_by=$3, updated_at=NOW() WHERE id=$4`,
			name, dataJSON, sess.UserID, id)
	} else {
		_, err = h.db.Exec(`UPDATE maniforge_wh_stocks SET name=$1, updated_by=$2, updated_at=NOW() WHERE id=$3`,
			name, sess.UserID, id)
	}
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	h.writeAudit(sess, "warehouses.stock.updated", int64(id), map[string]any{"name": name})
	fresh, _ := h.findVisible(sess, int64(id))
	return httpx.OK(c, fiber.Map{"ok": true, "stock": fresh})
}

func (h *Handler) Delete(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.delete")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	stock, _ := h.findVisible(sess, int64(id))
	if stock == nil {
		return httpx.Fail(c, 404, "Узел не найден")
	}
	var kids int
	_ = h.db.QueryRow(`SELECT COUNT(*) FROM maniforge_wh_stocks WHERE parent_id=$1 AND status='active'`, id).Scan(&kids)
	if kids > 0 {
		return supplychain.Result(c, map[string]any{"ok": false, "error": "Есть активные дочерние узлы", "code": "has_children"}, 409)
	}
	_, err = h.db.Exec(`UPDATE maniforge_wh_stocks SET status='archived', active=FALSE, updated_by=$1, updated_at=NOW() WHERE id=$2`,
		sess.UserID, id)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	h.writeAudit(sess, "warehouses.stock.archived", int64(id), map[string]any{"status": "archived"})
	return httpx.OK(c, fiber.Map{"ok": true, "archived": true, "id": id})
}

func (h *Handler) Children(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	if stock, _ := h.findVisible(sess, int64(id)); stock == nil {
		return httpx.Fail(c, 404, "Узел не найден")
	}
	items, err := h.listVisible(sess, "", "", "active", fmt.Sprint(id), "")
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) BindExternal(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.write")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	stock, _ := h.findVisible(sess, int64(id))
	if stock == nil {
		return httpx.Fail(c, 404, "Узел не найден")
	}
	in := supplychain.Body(c)
	extType := supplychain.Str(in, "type")
	extID := supplychain.Str(in, "external_id", "meta")
	if extType == "" || extID == "" {
		return httpx.Fail(c, 422, "type и external_id обязательны")
	}
	meta := map[string]any{"type": extType, "external_id": extID}
	b, _ := json.Marshal(meta)
	_, err = h.db.Exec(`
		UPDATE maniforge_wh_stocks
		SET data_json = COALESCE(data_json, '{}'::jsonb) || jsonb_build_object('external_meta', $1::jsonb),
		    updated_by=$2, updated_at=NOW()
		WHERE id=$3`, string(b), sess.UserID, id)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	h.writeAudit(sess, "warehouses.stock.external_bound", int64(id), meta)
	return httpx.OK(c, fiber.Map{"ok": true, "stock_id": id, "external_type": extType, "external_id": extID})
}

func (h *Handler) Audit(c *fiber.Ctx) error {
	sess, err := h.guard(c, "warehouses.audit.read")
	if err != nil {
		return supplychain.WriteErr(c, err)
	}
	id, _ := c.ParamsInt("id")
	if stock, _ := h.findVisible(sess, int64(id)); stock == nil {
		return httpx.Fail(c, 404, "Узел не найден")
	}
	rows, err := h.db.Query(`
		SELECT id, event_type, actor_user_id, payload_json, correlation_id, created_at
		FROM maniforge_audit_log
		WHERE tenant_id=$1 AND event_type LIKE 'warehouses.%'
		  AND (payload_json->>'stock_id')::bigint = $2
		ORDER BY id DESC LIMIT 50`, sess.TenantID, id)
	if err != nil {
		return httpx.Fail(c, 500, err.Error())
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		var eid int64
		var etype string
		var actor sql.NullInt64
		var payload []byte
		var corr sql.NullString
		var created time.Time
		if err := rows.Scan(&eid, &etype, &actor, &payload, &corr, &created); err != nil {
			continue
		}
		var p any
		_ = json.Unmarshal(payload, &p)
		item := map[string]any{"id": eid, "event_type": etype, "stock_id": id, "payload": p, "created_at": created.UTC().Format(time.RFC3339)}
		if corr.Valid {
			item["correlation_id"] = corr.String
		}
		items = append(items, item)
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) listVisible(sess *repository.SessionRecord, typ, search, status, parentID, rootsOnly string) ([]map[string]any, error) {
	if status == "" {
		status = "active"
	}
	args := supplychain.VisibleArgs(sess)
	sqlText := `SELECT id, tenant_id, subtenant_id, project_id, scope_visibility, code, name, type, parent_id, data_json, status, active, created_at
		FROM maniforge_wh_stocks WHERE ` + supplychain.VisibleSQL("") + ` AND status = $4`
	args = append(args, status)
	n := 5
	if typ != "" {
		sqlText += fmt.Sprintf(` AND type = $%d`, n)
		args = append(args, typ)
		n++
	}
	if search != "" {
		sqlText += fmt.Sprintf(` AND (name ILIKE $%d OR code ILIKE $%d)`, n, n)
		args = append(args, "%"+search+"%")
		n++
	}
	if parentID != "" {
		sqlText += fmt.Sprintf(` AND parent_id = $%d`, n)
		args = append(args, supplychain.AsInt64(parentID))
		n++
	} else if rootsOnly == "1" || strings.EqualFold(rootsOnly, "true") {
		sqlText += ` AND parent_id IS NULL`
	}
	sqlText += ` ORDER BY id`
	rows, err := h.db.Query(sqlText, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]any{}
	for rows.Next() {
		item, err := scanStock(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, nil
}

func (h *Handler) findVisible(sess *repository.SessionRecord, id int64) (map[string]any, error) {
	args := append(supplychain.VisibleArgs(sess), id)
	row := h.db.QueryRow(`SELECT id, tenant_id, subtenant_id, project_id, scope_visibility, code, name, type, parent_id, data_json, status, active, created_at
		FROM maniforge_wh_stocks WHERE `+supplychain.VisibleSQL("")+` AND id=$4`, args...)
	item, err := scanStock(row)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return item, err
}

func (h *Handler) typeParents(code string) ([]string, bool) {
	var raw []byte
	err := h.db.QueryRow(`SELECT allowed_parents_json FROM maniforge_wh_stock_types WHERE code=$1 AND active=TRUE`, code).Scan(&raw)
	if err != nil {
		return nil, false
	}
	var parents []string
	_ = json.Unmarshal(raw, &parents)
	return parents, true
}

func (h *Handler) codeTaken(scope supplychain.Scope, code string) (bool, error) {
	var id int64
	err := h.db.QueryRow(`
		SELECT id FROM maniforge_wh_stocks
		WHERE tenant_id=$1 AND subtenant_id=$2 AND code=$3
		  AND (project_id = $4 OR ($4::bigint IS NULL AND project_id IS NULL))
		LIMIT 1`, scope.TenantID, scope.SubtenantID, code, nullInt(scope.ProjectID)).Scan(&id)
	if err == sql.ErrNoRows {
		return false, nil
	}
	return err == nil, err
}

func (h *Handler) writeAudit(sess *repository.SessionRecord, event string, stockID int64, extra map[string]any) {
	if h.audit == nil {
		return
	}
	payload := map[string]any{"stock_id": stockID}
	for k, v := range extra {
		payload[k] = v
	}
	uid := sess.UserID
	_ = h.audit.Write(event, &uid, sess.TenantID, sess.SubtenantID, payload)
}

func canBeChildOf(allowed []string, parentType *string) bool {
	if parentType == nil {
		return len(allowed) == 0
	}
	for _, a := range allowed {
		if a == *parentType {
			return true
		}
	}
	return false
}

type scanner interface {
	Scan(dest ...any) error
}

func scanStock(s scanner) (map[string]any, error) {
	var id int64
	var tenant, sub, vis, code, name, typ, status string
	var project, parent sql.NullInt64
	var data []byte
	var active bool
	var created time.Time
	if err := s.Scan(&id, &tenant, &sub, &project, &vis, &code, &name, &typ, &parent, &data, &status, &active, &created); err != nil {
		return nil, err
	}
	item := map[string]any{
		"id": id, "tenant_id": tenant, "subtenant_id": sub, "scope_visibility": vis,
		"code": code, "name": name, "type": typ, "status": status, "active": active,
		"created_at": created.UTC().Format(time.RFC3339),
		"project_id": supplychain.NullInt(project),
		"parent_id":  supplychain.NullInt(parent),
	}
	if len(data) > 0 {
		var d any
		_ = json.Unmarshal(data, &d)
		item["data"] = d
	}
	return item, nil
}

func nullInt(n sql.NullInt64) any {
	if n.Valid {
		return n.Int64
	}
	return nil
}

func nullJSON(b []byte) any {
	if len(b) == 0 {
		return nil
	}
	return string(b)
}
