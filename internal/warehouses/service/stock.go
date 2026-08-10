// Файл: stock.go
// Назначение: бизнес-логика складских узлов (CRUD, tree, audit, delegation share).
// См. также: app/Maniforge/Warehouses/Security/StockService.php
package service

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	rbacrepo "maniforge/internal/rbac/repository"
	rbacsvc "maniforge/internal/rbac/service"
	"strconv"
	"maniforge/internal/versioning"
	whrepo "maniforge/internal/warehouses/repository"
)

const (
	eventStockCreated       = "warehouses.stock.created"
	eventStockUpdated       = "warehouses.stock.updated"
	eventStockArchived      = "warehouses.stock.archived"
	eventStockExternalBound = "warehouses.stock.external_bound"
	stockTypeWarehouse      = "warehouse"
)

type StockService struct {
	cfg        config.Config
	db         *sql.DB
	stocks     *whrepo.StockRepository
	types      *whrepo.StockTypeRepository
	audit      *whrepo.WarehouseAuditRepository
	users      *rbacrepo.UserRepository
	rbac       *rbacsvc.RbacService
	versioning *versioning.Recorder
	delegation *DelegationShareService
}

func NewStockService(cfg config.Config, db *sql.DB) *StockService {
	users := rbacrepo.NewUserRepository(db, cfg)
	return &StockService{
		cfg:        cfg,
		db:         db,
		stocks:     whrepo.NewStockRepository(db),
		types:      whrepo.NewStockTypeRepository(db),
		audit:      whrepo.NewWarehouseAuditRepository(db, users),
		users:      users,
		rbac:       rbacsvc.NewRbacService(rbacrepo.NewRoleRepository(db)),
		versioning: versioning.NewRecorder(cfg, db),
		delegation: NewDelegationShareService(db),
	}
}

func (s *StockService) ListTypes() map[string]any {
	items, err := s.types.ListActive()
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}
	}
	out := make([]map[string]any, 0, len(items))
	for _, t := range items {
		out = append(out, t.ToMap())
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "items": out}
}

func (s *StockService) ListGrantPeers(session *rbacrepo.SessionRecord) (map[string]any, int) {
	ok, _ := s.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{"super_admin", "tenant_admin"})
	if !ok {
		return map[string]any{"ok": false, "error": "Требуется tenant_admin"}, fiber.StatusForbidden
	}
	items, err := s.delegation.ListActiveGrantPeers(session.TenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "items": items}, fiber.StatusOK
}

func (s *StockService) ListStocks(session *rbacrepo.SessionRecord, query map[string]string) (map[string]any, int) {
	projectID, err := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	filters := whrepo.StockFilters{Status: strings.TrimSpace(query["status"])}
	if filters.Status == "" {
		filters.Status = "active"
	}
	filters.Type = strings.TrimSpace(query["type"])
	filters.Search = strings.TrimSpace(query["search"])
	filters.RootsOnly = query["roots_only"] == "true" || query["roots_only"] == "1"
	if p, ok := query["parent_id"]; ok && p != "" {
		var id int64
		fmt.Sscan(p, &id)
		filters.ParentID = &id
	}
	rows, err := s.stocks.ListVisible(session.TenantID, session.SubtenantID, projectID, filters)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "items": s.enrichMany(rows, session)}, fiber.StatusOK
}

func (s *StockService) Tree(session *rbacrepo.SessionRecord, query map[string]string) (map[string]any, int) {
	list, status := s.ListStocks(session, query)
	if status != fiber.StatusOK {
		return list, status
	}
	items, _ := list["items"].([]map[string]any)
	byParent := map[int64][]map[string]any{}
	for _, item := range items {
		var pid int64
		if v, ok := item["parent_id"].(int64); ok {
			pid = v
		}
		byParent[pid] = append(byParent[pid], item)
	}
	var build func(int64) []map[string]any
	build = func(parentID int64) []map[string]any {
		nodes := []map[string]any{}
		for _, row := range byParent[parentID] {
			id, _ := row["id"].(int64)
			row["children"] = build(id)
			nodes = append(nodes, row)
		}
		return nodes
	}
	return map[string]any{
		"ok": true, "status": fiber.StatusOK,
		"tree": build(0), "flat_count": len(items),
	}, fiber.StatusOK
}

func (s *StockService) GetStock(session *rbacrepo.SessionRecord, id int64) (map[string]any, int) {
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	row, err := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, id)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if row == nil {
		return map[string]any{"ok": false, "error": "Складской узел не найден"}, fiber.StatusNotFound
	}
	stock := s.enrichOne(*row, session)
	count, _ := s.stocks.CountChildren(id, row.TenantID, false)
	stock["children_count"] = count
	return map[string]any{"ok": true, "status": fiber.StatusOK, "stock": stock}, fiber.StatusOK
}

func (s *StockService) CreateStock(session *rbacrepo.SessionRecord, input map[string]any) (map[string]any, int) {
	name := strings.TrimSpace(stringVal(input["name"]))
	stockType := strings.TrimSpace(stringVal(input["type"]))
	if name == "" || stockType == "" {
		return map[string]any{"ok": false, "error": "name и type обязательны"}, fiber.StatusUnprocessableEntity
	}
	if t, _ := s.types.FindByCode(stockType); t == nil {
		return map[string]any{"ok": false, "error": "Неизвестный тип узла", "code": "unknown_stock_type"}, fiber.StatusUnprocessableEntity
	}

	var parentID sql.NullInt64
	if raw, ok := input["parent_id"]; ok && raw != nil && raw != "" {
		pid := int64(intVal(raw))
		if pid > 0 {
			projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
			parent, err := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, pid)
			if err != nil || parent == nil {
				return map[string]any{"ok": false, "error": "Родительский узел не найден"}, fiber.StatusNotFound
			}
			ok, _ := s.types.CanBeChildOf(stockType, &parent.Type)
			if !ok {
				return map[string]any{
					"ok": false, "status": fiber.StatusUnprocessableEntity,
					"error": fmt.Sprintf("Тип %s не может быть дочерним для %s", stockType, parent.Type),
					"code": "invalid_parent_type",
				}, fiber.StatusUnprocessableEntity
			}
			parentID = sql.NullInt64{Int64: pid, Valid: true}
		}
	} else {
		ok, _ := s.types.CanBeChildOf(stockType, nil)
		if !ok {
			return map[string]any{"ok": false, "error": "Для этого типа требуется parent_id", "code": "parent_required"}, fiber.StatusUnprocessableEntity
		}
	}

	projectID, err := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if err != nil || projectID == 0 {
		return map[string]any{"ok": false, "error": "Не удалось определить project scope"}, fiber.StatusUnprocessableEntity
	}
	code := strings.ToLower(strings.TrimSpace(stringVal(input["code"])))
	if code == "" {
		code = generateCode(stockType, name)
	}
	exists, _ := s.stocks.FindByCodeInScope(session.TenantID, session.SubtenantID, sql.NullInt64{Int64: projectID, Valid: true}, code)
	if exists != nil {
		return map[string]any{"ok": false, "error": "code уже занят в scope", "code": "code_exists"}, fiber.StatusConflict
	}

	var dataJSON json.RawMessage
	if data, ok := input["data"].(map[string]any); ok {
		dataJSON, _ = json.Marshal(data)
	}

	shareJSON, shareStatus, shareErr := s.delegation.ResolveShareJSON(session.TenantID, input)
	if shareErr != nil {
		return map[string]any{"ok": false, "error": shareErr.Error()}, shareStatus
	}

	row, err := s.stocks.Create(whrepo.CreateStockInput{
		TenantID: session.TenantID, SubtenantID: session.SubtenantID,
		ProjectID: sql.NullInt64{Int64: projectID, Valid: true},
		ScopeVisibility: "project", Code: code, Name: name, Type: stockType,
		ParentID: parentID, DataJSON: dataJSON, CreatedBy: session.UserID,
		SharedGrantTenantIDs: shareJSON,
	})
	if err != nil {
		if strings.Contains(err.Error(), "duplicate") {
			return map[string]any{"ok": false, "error": "Конфликт уникальности", "code": "duplicate"}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	_ = s.audit.Write(eventStockCreated, session.UserID, session.TenantID, session.SubtenantID, row.ID, map[string]any{
		"code": code, "type": stockType, "parent_id": nullInt(parentID), "name": name,
	})
	s.recordVersion(session, "insert", nil, row.ToMap(session.TenantID))

	return map[string]any{
		"ok": true, "status": fiber.StatusCreated,
		"stock": s.enrichOne(*row, session),
	}, fiber.StatusCreated
}

func (s *StockService) UpdateStock(session *rbacrepo.SessionRecord, id int64, input map[string]any) (map[string]any, int) {
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	before, err := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, id)
	if err != nil || before == nil {
		return map[string]any{"ok": false, "error": "Складской узел не найден"}, fiber.StatusNotFound
	}
	if before.TenantID != session.TenantID {
		return map[string]any{
			"ok": false, "error": "Изменение сущности другого tenant только в его контексте (switch-context)",
			"code": "delegated_entity_read_only",
		}, fiber.StatusForbidden
	}

	fields := map[string]any{}
	if v, ok := input["name"]; ok {
		fields["name"] = strings.TrimSpace(stringVal(v))
	}
	if v, ok := input["parent_id"]; ok {
		newParent := sql.NullInt64{}
		if v != nil && stringVal(v) != "" {
			pid := int64(intVal(v))
			if pid == id {
				return map[string]any{"ok": false, "error": "Узел не может быть родителем самому себе"}, fiber.StatusUnprocessableEntity
			}
			desc, _ := s.stocks.ListDescendantIDs(session.TenantID, session.SubtenantID, projectID, id)
			for _, d := range desc {
				if d == pid {
					return map[string]any{
						"ok": false, "error": "Нельзя переместить узел под своего потомка", "code": "cycle",
					}, fiber.StatusUnprocessableEntity
				}
			}
			parent, _ := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, pid)
			if parent == nil {
				return map[string]any{"ok": false, "error": "Родитель не найден"}, fiber.StatusNotFound
			}
			childType := before.Type
			if t, ok := input["type"]; ok {
				childType = strings.TrimSpace(stringVal(t))
			}
			ok, _ := s.types.CanBeChildOf(childType, &parent.Type)
			if !ok {
				return map[string]any{"ok": false, "error": "Несовместимый тип родителя", "code": "invalid_parent_type"}, fiber.StatusUnprocessableEntity
			}
			newParent = sql.NullInt64{Int64: pid, Valid: true}
		}
		fields["parent_id"] = newParent
	}
	if len(fields) == 0 {
		return map[string]any{"ok": true, "status": fiber.StatusOK, "stock": s.enrichOne(*before, session)}, fiber.StatusOK
	}
	if err := s.stocks.Update(id, session.TenantID, fields, session.UserID); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	after, _ := s.stocks.FindByIDInTenant(id, session.TenantID)
	_ = s.audit.Write(eventStockUpdated, session.UserID, session.TenantID, session.SubtenantID, id, map[string]any{"code": before.Code})
	if after != nil {
		s.recordVersion(session, "update", before.ToMap(session.TenantID), after.ToMap(session.TenantID))
		return map[string]any{"ok": true, "status": fiber.StatusOK, "stock": s.enrichOne(*after, session)}, fiber.StatusOK
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "stock": s.enrichOne(*before, session)}, fiber.StatusOK
}

func (s *StockService) ArchiveStock(session *rbacrepo.SessionRecord, id int64) (map[string]any, int) {
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	before, _ := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, id)
	if before == nil {
		return map[string]any{"ok": false, "error": "Складской узел не найден"}, fiber.StatusNotFound
	}
	count, _ := s.stocks.CountChildren(id, before.TenantID, true)
	if count > 0 {
		return map[string]any{
			"ok": false, "error": "Сначала архивируйте дочерние узлы",
			"code": "has_active_children",
		}, fiber.StatusConflict
	}
	_ = s.stocks.Update(id, before.TenantID, map[string]any{"status": "archived", "active": false}, session.UserID)
	after, _ := s.stocks.FindByIDInTenant(id, before.TenantID)
	_ = s.audit.Write(eventStockArchived, session.UserID, session.TenantID, session.SubtenantID, id, map[string]any{"code": before.Code})
	if after != nil {
		s.recordVersion(session, "delete", before.ToMap(session.TenantID), after.ToMap(session.TenantID))
		return map[string]any{"ok": true, "status": fiber.StatusOK, "stock": s.enrichOne(*after, session)}, fiber.StatusOK
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK}, fiber.StatusOK
}

func (s *StockService) BindExternal(session *rbacrepo.SessionRecord, id int64, input map[string]any) (map[string]any, int) {
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	stock, _ := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, id)
	if stock == nil {
		return map[string]any{"ok": false, "error": "Узел не найден"}, fiber.StatusNotFound
	}
	extType := strings.TrimSpace(stringVal(input["type"]))
	extID := strings.TrimSpace(stringVal(input["external_id"]))
	if extID == "" {
		extID = strings.TrimSpace(stringVal(input["meta"]))
	}
	if extType == "" || extID == "" {
		return map[string]any{"ok": false, "error": "type и external_id обязательны"}, fiber.StatusUnprocessableEntity
	}
	_ = s.audit.Write(eventStockExternalBound, session.UserID, session.TenantID, session.SubtenantID, id, map[string]any{
		"external_type": extType, "external_id": extID,
	})
	return map[string]any{
		"ok": true, "status": fiber.StatusOK,
		"stock_id": id, "external_type": extType, "external_id": extID,
	}, fiber.StatusOK
}

func (s *StockService) StockAudit(session *rbacrepo.SessionRecord, id int64, limit int) (map[string]any, int) {
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if row, _ := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, id); row == nil {
		return map[string]any{"ok": false, "error": "Складской узел не найден"}, fiber.StatusNotFound
	}
	items, err := s.audit.ListForStock(session.TenantID, session.SubtenantID, id, limit)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "stock_id": id, "items": items}, fiber.StatusOK
}

func (s *StockService) enrichMany(rows []whrepo.StockRow, session *rbacrepo.SessionRecord) []map[string]any {
	out := make([]map[string]any, 0, len(rows))
	for _, row := range rows {
		out = append(out, s.enrichOne(row, session))
	}
	return out
}

func (s *StockService) enrichOne(row whrepo.StockRow, session *rbacrepo.SessionRecord) map[string]any {
	m := row.ToMap(session.TenantID)
	m["type_label"] = typeLabel(row.Type)
	if row.CreatedBy.Valid {
		if u, _ := s.users.FindByID(row.CreatedBy.Int64); u != nil {
			m["created_by_user"] = rbacrepo.PublicUser(*u)
		}
	}
	return m
}

func (s *StockService) recordVersion(session *rbacrepo.SessionRecord, op string, before, after map[string]any) {
	if s.versioning == nil {
		return
	}
	var entityID int64
	if after != nil {
		entityID = int64(intVal(after["id"]))
	}
	var pid int64
	if session.ProjectID.Valid {
		pid = session.ProjectID.Int64
	}
	s.versioning.Record(versioning.Scope{
		TenantID: session.TenantID, SubtenantID: session.SubtenantID,
		ProjectID: pid, ActorUserID: session.UserID,
	}, "maniforge_wh_stocks", strconv.FormatInt(entityID, 10), op, before, after, stringVal(after["code"]))
}

func typeLabel(code string) string {
	labels := map[string]string{
		"warehouse": "Склад (здание)", "zone": "Зона", "cell": "Ячейка",
	}
	if l, ok := labels[code]; ok {
		return l
	}
	if len(code) == 0 {
		return code
	}
	return strings.ToUpper(code[:1]) + code[1:]
}

func generateCode(stockType, name string) string {
	re := regexp.MustCompile(`[^a-z0-9]+`)
	slug := strings.Trim(re.ReplaceAllString(strings.ToLower(name), "-"), "-")
	if slug == "" {
		slug = "node"
	}
	if len(slug) > 40 {
		slug = slug[:40]
	}
	b := make([]byte, 3)
	_, _ = rand.Read(b)
	return stockType + "-" + slug + "-" + hex.EncodeToString(b)
}

func stringVal(v any) string {
	if v == nil {
		return ""
	}
	switch t := v.(type) {
	case string:
		return t
	case float64:
		return fmt.Sprintf("%.0f", t)
	default:
		return fmt.Sprint(v)
	}
}

func intVal(v any) int64 {
	switch t := v.(type) {
	case float64:
		return int64(t)
	case int:
		return int64(t)
	case int64:
		return t
	case json.Number:
		n, _ := t.Int64()
		return n
	default:
		var n int64
		fmt.Sscan(stringVal(v), &n)
		return n
	}
}

func nullInt(n sql.NullInt64) any {
	if n.Valid {
		return n.Int64
	}
	return nil
}
