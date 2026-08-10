// Файл: inventory.go
// Назначение: движения, остатки, заказы, партии (Inventory).
// См. также: app/Maniforge/Inventory/Security/InventoryPostingService.php
package service

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	invrepo "maniforge/internal/inventory/repository"
	"maniforge/internal/platform/qty"
	prodrepo "maniforge/internal/products/repository"
	rbacrepo "maniforge/internal/rbac/repository"
	whrepo "maniforge/internal/warehouses/repository"
)

const (
	movReceipt     = "receipt"
	movIssue       = "issue"
	movTransfer    = "transfer"
	movAdjustment  = "adjustment"
	errInsufficient = "insufficient_qty"
)

type InventoryService struct {
	db        *sql.DB
	balances  *invrepo.BalanceRepository
	movements *invrepo.MovementRepository
	reserves  *invrepo.ReserveRepository
	orders    *invrepo.OrderRepository
	lots      *invrepo.LotRepository
	products  *prodrepo.ProductRepository
	stocks    *whrepo.StockRepository
}

func NewInventoryService(cfg config.Config, db *sql.DB) *InventoryService {
	_ = cfg
	return &InventoryService{
		db:        db,
		balances:  invrepo.NewBalanceRepository(db),
		movements: invrepo.NewMovementRepository(db),
		reserves:  invrepo.NewReserveRepository(db),
		orders:    invrepo.NewOrderRepository(db),
		lots:      invrepo.NewLotRepository(db),
		products:  prodrepo.NewProductRepository(db),
		stocks:    whrepo.NewStockRepository(db),
	}
}

func (s *InventoryService) ListBalances(session *rbacrepo.SessionRecord, query map[string]string) (map[string]any, int) {
	filters := map[string]any{}
	if v := query["product_id"]; v != "" {
		var id int64
		fmt.Sscan(v, &id)
		filters["product_id"] = id
	}
	if v := query["stock_id"]; v != "" {
		var id int64
		fmt.Sscan(v, &id)
		filters["stock_id"] = id
	}
	items, err := s.balances.ListVisible(session, filters)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "items": items}, fiber.StatusOK
}

func (s *InventoryService) ListMovements(session *rbacrepo.SessionRecord, query map[string]string) (map[string]any, int) {
	limit := 50
	if v := query["limit"]; v != "" {
		fmt.Sscan(v, &limit)
	}
	items, err := s.movements.ListVisible(session, limit)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "items": items}, fiber.StatusOK
}

func (s *InventoryService) PostMovement(session *rbacrepo.SessionRecord, input map[string]any) (map[string]any, int) {
	if session.TenantID == "" {
		return map[string]any{"ok": false, "error": "Движение только в контексте владельца tenant"}, fiber.StatusForbidden
	}
	movType := strings.ToLower(strings.TrimSpace(stringVal(input["movement_type"], input["type"])))
	if !isValidMovType(movType) {
		return map[string]any{"ok": false, "error": "movement_type: receipt|issue|transfer|adjustment"}, fiber.StatusUnprocessableEntity
	}
	scope, st, errMap := s.resolveScope(session, input)
	if errMap != nil {
		return errMap, st
	}
	lines, st, errMap := s.buildLines(session, movType, input)
	if errMap != nil {
		return errMap, st
	}
	docNumber := strings.ToLower(strings.TrimSpace(stringVal(input["doc_number"], input["docNumber"])))
	if docNumber == "" {
		docNumber = "mov-" + time.Now().UTC().Format("20060102") + "-" + randomSuffix(6)
	}
	var note *string
	if v := strings.TrimSpace(stringVal(input["note"])); v != "" {
		note = &v
	}
	var metadata json.RawMessage
	if m, ok := input["metadata"].(map[string]any); ok {
		metadata, _ = json.Marshal(m)
	}
	if s.isDraft(input) {
		id, err := s.movements.Insert(scope, docNumber, movType, "draft", note, metadata, session.UserID, false, lines)
		if err != nil {
			if strings.Contains(err.Error(), "duplicate") {
				return map[string]any{"ok": false, "error": "Конфликт doc_number", "code": "duplicate"}, fiber.StatusConflict
			}
			return map[string]any{"ok": false, "error": "Ошибка сохранения черновика"}, fiber.StatusInternalServerError
		}
		mov, _ := s.movements.FindVisibleByID(session, id)
		return map[string]any{"ok": true, "status": fiber.StatusCreated, "movement": mov}, fiber.StatusCreated
	}
	if err := s.applyPosted(session.TenantID, lines); err != nil {
		if errors.Is(err, errInsufficientQty) {
			return map[string]any{"ok": false, "error": "Недостаточно остатка", "code": errInsufficient}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": "Ошибка проведения движения"}, fiber.StatusInternalServerError
	}
	id, err := s.movements.Insert(scope, docNumber, movType, "posted", note, metadata, session.UserID, true, lines)
	if err != nil {
		return map[string]any{"ok": false, "error": "Ошибка проведения движения"}, fiber.StatusInternalServerError
	}
	mov, _ := s.movements.FindVisibleByID(session, id)
	return map[string]any{"ok": true, "status": fiber.StatusOK, "movement": mov}, fiber.StatusOK
}

func (s *InventoryService) PostDraft(session *rbacrepo.SessionRecord, movementID int64) (map[string]any, int) {
	mov, err := s.movements.FindVisibleByID(session, movementID)
	if err != nil || mov == nil {
		return map[string]any{"ok": false, "error": "Движение не найдено"}, fiber.StatusNotFound
	}
	if mov["status"] != "draft" {
		return map[string]any{"ok": false, "error": "Только draft можно провести", "code": "not_draft"}, fiber.StatusUnprocessableEntity
	}
	if mov["tenant_id"] != session.TenantID {
		return map[string]any{"ok": false, "error": "Проведение только в tenant владельца"}, fiber.StatusForbidden
	}
	rawLines, _ := mov["lines"].([]map[string]any)
	var lines []invrepo.MovementLine
	for _, line := range rawLines {
		lines = append(lines, invrepo.MovementLine{
			ProductID: int64(numVal(line["product_id"])),
			StockID:   int64(numVal(line["stock_id"])),
			QtyDelta:  qty.Format(fmt.Sprint(line["qty_delta"])),
		})
	}
	if err := s.applyPosted(session.TenantID, lines); err != nil {
		if errors.Is(err, errInsufficientQty) {
			return map[string]any{"ok": false, "error": "Недостаточно остатка", "code": errInsufficient}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": "Ошибка проведения черновика"}, fiber.StatusInternalServerError
	}
	ok, _ := s.movements.MarkPosted(movementID, session.TenantID, session.UserID)
	if !ok {
		return map[string]any{"ok": false, "error": "Ошибка проведения черновика"}, fiber.StatusInternalServerError
	}
	posted, _ := s.movements.FindVisibleByID(session, movementID)
	return map[string]any{"ok": true, "status": fiber.StatusOK, "movement": posted}, fiber.StatusOK
}

func (s *InventoryService) RegisterLot(session *rbacrepo.SessionRecord, input map[string]any) (map[string]any, int) {
	productID := int64(numVal(input["product_id"]))
	batchCode := strings.TrimSpace(stringVal(input["batch_code"]))
	lotCode := strings.TrimSpace(stringVal(input["lot_code"]))
	if productID <= 0 {
		return map[string]any{"ok": false, "error": "product_id обязателен"}, fiber.StatusUnprocessableEntity
	}
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if p, _ := s.products.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, productID); p == nil {
		return map[string]any{"ok": false, "error": "Товар не найден"}, fiber.StatusNotFound
	}
	existing, _ := s.lots.FindByKey(session.TenantID, productID, batchCode, lotCode)
	if existing != nil {
		return map[string]any{"ok": true, "status": fiber.StatusOK, "lot": existing, "created": false}, fiber.StatusOK
	}
	id, err := s.lots.Create(session.TenantID, productID, batchCode, lotCode, session.UserID)
	if err != nil {
		if ex, _ := s.lots.FindByKey(session.TenantID, productID, batchCode, lotCode); ex != nil {
			return map[string]any{"ok": true, "status": fiber.StatusOK, "lot": ex}, fiber.StatusOK
		}
		return map[string]any{"ok": false, "error": "Ошибка регистрации партии"}, fiber.StatusInternalServerError
	}
	lot, _ := s.lots.FindByIDInTenant(id, session.TenantID)
	return map[string]any{"ok": true, "status": fiber.StatusCreated, "created": true, "lot": lot}, fiber.StatusCreated
}

func (s *InventoryService) CreateOrder(session *rbacrepo.SessionRecord, input map[string]any) (map[string]any, int) {
	stockID := int64(numVal(input["stock_id"]))
	orderNumber := strings.TrimSpace(stringVal(input["order_number"], input["orderNumber"]))
	if orderNumber == "" {
		orderNumber = "ord-" + time.Now().UTC().Format("20060102") + "-" + randomSuffix(6)
	}
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if stock, _ := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, stockID); stock == nil {
		return map[string]any{"ok": false, "error": "Складской узел не найден"}, fiber.StatusNotFound
	}
	lines := parseOrderLines(input["lines"])
	if len(lines) == 0 {
		return map[string]any{"ok": false, "error": "lines обязателен (product_id, qty)"}, fiber.StatusUnprocessableEntity
	}
	for _, line := range lines {
		if p, _ := s.products.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, line.ProductID); p == nil {
			return map[string]any{"ok": false, "error": "Товар в строке не найден"}, fiber.StatusNotFound
		}
	}
	var note *string
	if v := strings.TrimSpace(stringVal(input["note"])); v != "" {
		note = &v
	}
	var metadata json.RawMessage
	if m, ok := input["metadata"].(map[string]any); ok {
		metadata, _ = json.Marshal(m)
	}
	id, err := s.orders.Create(session.TenantID, orderNumber, stockID, note, metadata, session.UserID, lines)
	if err != nil {
		if strings.Contains(err.Error(), "duplicate") {
			return map[string]any{"ok": false, "error": "order_number уже существует", "code": "duplicate"}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": "Ошибка создания заказа"}, fiber.StatusInternalServerError
	}
	order, _ := s.orders.FindByIDInTenant(id, session.TenantID)
	return map[string]any{"ok": true, "status": fiber.StatusCreated, "order": order}, fiber.StatusCreated
}

func (s *InventoryService) ConfirmOrder(session *rbacrepo.SessionRecord, id int64) (map[string]any, int) {
	order, err := s.orders.FindByIDInTenant(id, session.TenantID)
	if err != nil || order == nil {
		return map[string]any{"ok": false, "error": "Заказ не найден"}, fiber.StatusNotFound
	}
	if order["status"] != "draft" {
		return map[string]any{"ok": false, "error": "Только draft можно подтвердить", "code": "invalid_status"}, fiber.StatusUnprocessableEntity
	}
	refCode := invrepo.OrderRefCode(fmt.Sprint(order["order_number"]))
	stockID := int64(numVal(order["stock_id"]))
	rawLines, _ := order["lines"].([]map[string]any)
	for _, line := range rawLines {
		q, ok := qty.Normalize(line["qty_ordered"])
		if !ok {
			q = qty.Format(fmt.Sprint(line["qty"]))
		}
		res := s.createReserve(session, int64(numVal(line["product_id"])), stockID, q, refCode, order)
		if res != nil {
			return res, int(numVal(res["status"]))
		}
	}
	if ok, _ := s.orders.UpdateStatus(id, session.TenantID, "confirmed", "confirmed_at"); !ok {
		return map[string]any{"ok": false, "error": "Не удалось обновить статус"}, fiber.StatusInternalServerError
	}
	order, _ = s.orders.FindByIDInTenant(id, session.TenantID)
	return map[string]any{"ok": true, "status": fiber.StatusOK, "order": order}, fiber.StatusOK
}

func (s *InventoryService) FulfillOrder(session *rbacrepo.SessionRecord, id int64) (map[string]any, int) {
	order, err := s.orders.FindByIDInTenant(id, session.TenantID)
	if err != nil || order == nil {
		return map[string]any{"ok": false, "error": "Заказ не найден"}, fiber.StatusNotFound
	}
	if order["status"] != "confirmed" {
		return map[string]any{"ok": false, "error": "Только confirmed можно отгрузить", "code": "invalid_status"}, fiber.StatusUnprocessableEntity
	}
	stockID := int64(numVal(order["stock_id"]))
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if stock, _ := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, stockID); stock == nil {
		return map[string]any{"ok": false, "error": "Склад не найден"}, fiber.StatusNotFound
	}
	refCode := invrepo.OrderRefCode(fmt.Sprint(order["order_number"]))
	_ = s.reserves.ReleaseActiveByRefCode(session.TenantID, refCode, session.UserID)

	var issueLines []invrepo.MovementLine
	rawLines, _ := order["lines"].([]map[string]any)
	for _, line := range rawLines {
		q := qty.Format(fmt.Sprint(line["qty_ordered"]))
		issueLines = append(issueLines, invrepo.MovementLine{
			ProductID: int64(numVal(line["product_id"])),
			StockID:   stockID,
			QtyDelta:  qty.Negate(q),
		})
	}
	scope, st, errMap := s.resolveScope(session, map[string]any{})
	if errMap != nil {
		return errMap, st
	}
	if err := s.applyPosted(session.TenantID, issueLines); err != nil {
		if errors.Is(err, errInsufficientQty) {
			return map[string]any{"ok": false, "error": "Недостаточно остатка", "code": errInsufficient}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": "Ошибка отгрузки"}, fiber.StatusInternalServerError
	}
	docNumber := "fulfill-" + fmt.Sprint(order["order_number"])
	meta, _ := json.Marshal(map[string]any{"order_id": id, "order_number": order["order_number"]})
	movID, err := s.movements.Insert(scope, docNumber, movIssue, "posted", nil, meta, session.UserID, true, issueLines)
	if err != nil {
		return map[string]any{"ok": false, "error": "Движение проведено, но запись не сохранена"}, fiber.StatusInternalServerError
	}
	if ok, _ := s.orders.UpdateStatus(id, session.TenantID, "fulfilled", "fulfilled_at"); !ok {
		return map[string]any{"ok": false, "error": "Движение проведено, но статус заказа не обновлён"}, fiber.StatusInternalServerError
	}
	order, _ = s.orders.FindByIDInTenant(id, session.TenantID)
	mov, _ := s.movements.FindVisibleByID(session, movID)
	if order != nil {
		order["fulfillment_movement_id"] = movID
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "order": order, "movement": mov}, fiber.StatusOK
}

func (s *InventoryService) createReserve(session *rbacrepo.SessionRecord, productID, stockID int64, q, refCode string, order map[string]any) map[string]any {
	onHand := s.balances.QtyForPair(session.TenantID, productID, stockID)
	reserved, _ := s.reserves.SumActiveForPair(session.TenantID, productID, stockID)
	available := qty.Sub(onHand, reserved)
	if qty.Cmp(available, q) < 0 {
		return map[string]any{
			"ok": false, "status": fiber.StatusConflict,
			"error": "Недостаточно свободного остатка", "code": "insufficient_available",
			"qty_on_hand": onHand, "qty_reserved": reserved, "qty_available": available,
		}
	}
	note := "order #" + fmt.Sprint(order["order_number"])
	_, err := s.reserves.Create(session.TenantID, productID, stockID, q, refCode, note, session.UserID)
	if err != nil {
		return map[string]any{"ok": false, "status": fiber.StatusInternalServerError, "error": err.Error()}
	}
	return nil
}

var errInsufficientQty = errors.New(errInsufficient)

func (s *InventoryService) applyPosted(tenantID string, lines []invrepo.MovementLine) error {
	bal := invrepo.NewBalanceRepository(s.db)
	for _, line := range lines {
		if err := assertSufficient(bal, tenantID, line); err != nil {
			return err
		}
	}
	for _, line := range lines {
		if err := bal.ApplyDelta(tenantID, line.ProductID, line.StockID, line.QtyDelta); err != nil {
			return err
		}
	}
	return nil
}

func assertSufficient(bal *invrepo.BalanceRepository, tenantID string, line invrepo.MovementLine) error {
	delta := line.QtyDelta
	if qty.Cmp(delta, "0") >= 0 {
		return nil
	}
	current := bal.QtyForPair(tenantID, line.ProductID, line.StockID)
	after := qty.Add(current, delta)
	if qty.Cmp(after, "0") < 0 {
		return errInsufficientQty
	}
	available := bal.AvailableQtyForPair(tenantID, line.ProductID, line.StockID)
	afterAvail := qty.Add(available, delta)
	if qty.Cmp(afterAvail, "0") < 0 {
		return errInsufficientQty
	}
	return nil
}

func (s *InventoryService) resolveScope(session *rbacrepo.SessionRecord, input map[string]any) (invrepo.ScopeRow, int, map[string]any) {
	projectID, err := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if err != nil || projectID == 0 {
		return invrepo.ScopeRow{}, fiber.StatusUnprocessableEntity, map[string]any{"ok": false, "error": "Не удалось определить project scope"}
	}
	_ = input
	return invrepo.ScopeRow{
		TenantID: session.TenantID, SubtenantID: session.SubtenantID,
		ProjectID: sql.NullInt64{Int64: projectID, Valid: true}, ScopeVisibility: "project",
	}, 0, nil
}

func (s *InventoryService) buildLines(session *rbacrepo.SessionRecord, movType string, input map[string]any) ([]invrepo.MovementLine, int, map[string]any) {
	tenantID := session.TenantID
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	switch movType {
	case movTransfer:
		return s.buildTransfer(session, tenantID, projectID, input)
	case movAdjustment:
		return s.buildAdjustment(session, tenantID, projectID, input)
	default:
		return s.buildSingleLine(session, tenantID, projectID, movType, input)
	}
}

func (s *InventoryService) buildSingleLine(session *rbacrepo.SessionRecord, tenantID string, projectID int64, movType string, input map[string]any) ([]invrepo.MovementLine, int, map[string]any) {
	productID := int64(numVal(input["product_id"], input["productId"]))
	stockID := int64(numVal(input["stock_id"], input["stockId"]))
	q, ok := qty.Normalize(input["qty"])
	if !ok {
		q, ok = qty.Normalize(input["quantity"])
	}
	if productID <= 0 || stockID <= 0 || !ok || qty.Cmp(q, "0") <= 0 {
		return nil, fiber.StatusUnprocessableEntity, map[string]any{"ok": false, "error": "product_id, stock_id, qty > 0 обязательны"}
	}
	delta := q
	if movType == movIssue {
		delta = qty.Negate(q)
	}
	if errMap, st := s.validatePair(session, tenantID, projectID, productID, stockID); errMap != nil {
		return nil, st, errMap
	}
	return []invrepo.MovementLine{{ProductID: productID, StockID: stockID, QtyDelta: delta}}, 0, nil
}

func (s *InventoryService) buildTransfer(session *rbacrepo.SessionRecord, tenantID string, projectID int64, input map[string]any) ([]invrepo.MovementLine, int, map[string]any) {
	productID := int64(numVal(input["product_id"]))
	fromID := int64(numVal(input["from_stock_id"], input["fromStockId"]))
	toID := int64(numVal(input["to_stock_id"], input["toStockId"]))
	q, ok := qty.Normalize(input["qty"])
	if productID <= 0 || fromID <= 0 || toID <= 0 || !ok || qty.Cmp(q, "0") <= 0 {
		return nil, fiber.StatusUnprocessableEntity, map[string]any{"ok": false, "error": "transfer: product_id, from_stock_id, to_stock_id, qty обязательны"}
	}
	if fromID == toID {
		return nil, fiber.StatusUnprocessableEntity, map[string]any{"ok": false, "error": "from_stock_id и to_stock_id должны различаться"}
	}
	for _, sid := range []int64{fromID, toID} {
		if errMap, st := s.validatePair(session, tenantID, projectID, productID, sid); errMap != nil {
			return nil, st, errMap
		}
	}
	return []invrepo.MovementLine{
		{ProductID: productID, StockID: fromID, QtyDelta: qty.Negate(q)},
		{ProductID: productID, StockID: toID, QtyDelta: q},
	}, 0, nil
}

func (s *InventoryService) buildAdjustment(session *rbacrepo.SessionRecord, tenantID string, projectID int64, input map[string]any) ([]invrepo.MovementLine, int, map[string]any) {
	productID := int64(numVal(input["product_id"]))
	stockID := int64(numVal(input["stock_id"]))
	if productID <= 0 || stockID <= 0 {
		return nil, fiber.StatusUnprocessableEntity, map[string]any{"ok": false, "error": "adjustment: product_id и stock_id обязательны"}
	}
	if errMap, st := s.validatePair(session, tenantID, projectID, productID, stockID); errMap != nil {
		return nil, st, errMap
	}
	current := s.balances.QtyForPair(tenantID, productID, stockID)
	var delta string
	if _, has := input["qty_after"]; has {
		target, ok := qty.Normalize(input["qty_after"])
		if !ok {
			target, ok = qty.Normalize(input["qtyAfter"])
		}
		if !ok {
			return nil, fiber.StatusUnprocessableEntity, map[string]any{"ok": false, "error": "qty_after обязателен"}
		}
		delta = qty.Sub(target, current)
	} else {
		var ok bool
		delta, ok = qty.Normalize(input["qty_delta"])
		if !ok {
			delta, ok = qty.Normalize(input["qty"])
		}
		if !ok || qty.Cmp(delta, "0") == 0 {
			return nil, fiber.StatusUnprocessableEntity, map[string]any{"ok": false, "error": "qty_delta или qty_after обязателен"}
		}
	}
	return []invrepo.MovementLine{{ProductID: productID, StockID: stockID, QtyDelta: delta}}, 0, nil
}

func (s *InventoryService) validatePair(session *rbacrepo.SessionRecord, tenantID string, projectID, productID, stockID int64) (map[string]any, int) {
	if p, _ := s.products.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, productID); p == nil {
		return map[string]any{"ok": false, "error": "Товар не найден"}, fiber.StatusNotFound
	}
	if st, _ := s.stocks.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, stockID); st == nil {
		return map[string]any{"ok": false, "error": "Узел не найден"}, fiber.StatusNotFound
	}
	if p, _ := s.products.FindByIDInTenant(tenantID, productID); p != nil && p.TenantID != tenantID {
		return map[string]any{"ok": false, "error": "product и stock в разных tenant"}, fiber.StatusUnprocessableEntity
	}
	return nil, 0
}

func (s *InventoryService) isDraft(input map[string]any) bool {
	if strings.ToLower(strings.TrimSpace(stringVal(input["status"]))) == "draft" {
		return true
	}
	if v, ok := input["post_immediately"]; ok {
		switch t := v.(type) {
		case bool:
			return !t
		case string:
			return strings.EqualFold(t, "false") || t == "0"
		}
	}
	return false
}

func isValidMovType(t string) bool {
	switch t {
	case movReceipt, movIssue, movTransfer, movAdjustment:
		return true
	default:
		return false
	}
}

func parseOrderLines(raw any) []invrepo.OrderLine {
	arr, ok := raw.([]any)
	if !ok {
		return nil
	}
	var lines []invrepo.OrderLine
	for _, item := range arr {
		m, ok := item.(map[string]any)
		if !ok {
			continue
		}
		q, ok := qty.Normalize(m["qty"])
		if !ok {
			q, ok = qty.Normalize(m["qty_ordered"])
		}
		if !ok {
			continue
		}
		lines = append(lines, invrepo.OrderLine{
			ProductID: int64(numVal(m["product_id"])),
			QtyOrdered: q,
		})
	}
	return lines
}

func stringVal(vals ...any) string {
	for _, v := range vals {
		if v == nil {
			continue
		}
		if s, ok := v.(string); ok && s != "" {
			return s
		}
	}
	for _, v := range vals {
		if v == nil {
			continue
		}
		if s := strings.TrimSpace(fmt.Sprint(v)); s != "" && s != "<nil>" {
			return s
		}
	}
	return ""
}

func numVal(vals ...any) float64 {
	for _, v := range vals {
		switch t := v.(type) {
		case float64:
			return t
		case int:
			return float64(t)
		case int64:
			return float64(t)
		case string:
			var f float64
			fmt.Sscan(t, &f)
			return f
		}
	}
	return 0
}

func randomSuffix(n int) string {
	b := make([]byte, (n+1)/2)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)[:n]
}
