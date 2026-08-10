// Файл: product.go
// Назначение: бизнес-логика номенклатуры (Products).
// См. также: app/Maniforge/Products/Security/ProductService.php
package service

import (
	"database/sql"
	"encoding/json"
	"strings"

	"github.com/gofiber/fiber/v2"
	invrepo "maniforge/internal/inventory/repository"
	"maniforge/internal/config"
	prodrepo "maniforge/internal/products/repository"
	rbacrepo "maniforge/internal/rbac/repository"
	whrepo "maniforge/internal/warehouses/repository"
)

type ProductService struct {
	db       *sql.DB
	products *prodrepo.ProductRepository
	balances *invrepo.BalanceRepository
}

func NewProductService(cfg config.Config, db *sql.DB) *ProductService {
	_ = cfg
	return &ProductService{
		db:       db,
		products: prodrepo.NewProductRepository(db),
		balances: invrepo.NewBalanceRepository(db),
	}
}

func (s *ProductService) CreateProduct(session *rbacrepo.SessionRecord, input map[string]any) (map[string]any, int) {
	name := strings.TrimSpace(stringVal(input["name"]))
	if name == "" {
		return map[string]any{"ok": false, "error": "name обязателен"}, fiber.StatusUnprocessableEntity
	}
	projectID, err := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	if err != nil || projectID == 0 {
		return map[string]any{"ok": false, "error": "Не удалось определить project scope"}, fiber.StatusUnprocessableEntity
	}
	code := strings.ToLower(strings.TrimSpace(stringVal(input["code"])))
	if code == "" {
		code = prodrepo.GenerateCode(name)
	}
	exists, _ := s.products.FindByCodeInScope(session.TenantID, session.SubtenantID, sql.NullInt64{Int64: projectID, Valid: true}, code)
	if exists != nil {
		return map[string]any{"ok": false, "error": "code уже занят в scope", "code": "code_exists"}, fiber.StatusConflict
	}
	unit := strings.TrimSpace(stringVal(input["unit"]))
	if unit == "" {
		unit = "pcs"
	}
	var desc sql.NullString
	if v, ok := input["description"]; ok {
		desc = sql.NullString{String: strings.TrimSpace(stringVal(v)), Valid: true}
	}
	var attrs json.RawMessage
	if a, ok := input["attributes"].(map[string]any); ok {
		attrs, _ = json.Marshal(a)
	} else if a, ok := input["data"].(map[string]any); ok {
		attrs, _ = json.Marshal(a)
	}
	row, err := s.products.Create(prodrepo.CreateProductInput{
		TenantID: session.TenantID, SubtenantID: session.SubtenantID,
		ProjectID: sql.NullInt64{Int64: projectID, Valid: true},
		ScopeVisibility: "project", Code: code, Name: name, Unit: unit,
		Description: desc, AttributesJSON: attrs, CreatedBy: session.UserID,
	})
	if err != nil {
		if strings.Contains(err.Error(), "duplicate") {
			return map[string]any{"ok": false, "error": "Конфликт уникальности", "code": "duplicate"}, fiber.StatusConflict
		}
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	return map[string]any{
		"ok": true, "status": fiber.StatusCreated,
		"product": row.ToMap(session.TenantID),
	}, fiber.StatusCreated
}

func (s *ProductService) GetProduct(session *rbacrepo.SessionRecord, id int64, query map[string]string) (map[string]any, int) {
	projectID, _ := whrepo.SessionProjectID(s.db, session.ProjectID, session.TenantID, session.SubtenantID)
	row, err := s.products.FindVisibleByID(session.TenantID, session.SubtenantID, projectID, id)
	if err != nil || row == nil {
		return map[string]any{"ok": false, "error": "Товар не найден"}, fiber.StatusNotFound
	}
	product := row.ToMap(session.TenantID)
	include := strings.ToLower(query["include"])
	if strings.Contains(include, "balances") {
		items, _ := s.balances.ListVisible(session, map[string]any{"product_id": id})
		product["balances"] = items
	}
	return map[string]any{"ok": true, "status": fiber.StatusOK, "product": product}, fiber.StatusOK
}

func stringVal(v any) string {
	if v == nil {
		return ""
	}
	switch t := v.(type) {
	case string:
		return t
	default:
		return ""
	}
}
