// Package presets — supply chain manifest presets (product, stock).
//
// Файл: presets.go
// Назначение: эталонные схемы для номенклатуры и складских узлов (порт PHP modules).
// См. также: docs/MANIFORGE_PRODUCTS.md, docs/MANIFORGE_WAREHOUSES.md
package presets

import (
	"fmt"
	"strings"

	"maniforge/internal/manifestengine/model"
)

// Definition — готовый manifest для установки в project scope.
type Definition struct {
	Code     string
	Name     string
	Fields   []model.FieldDef
	Metadata map[string]any
}

// All возвращает все supply chain presets.
func All() []Definition {
	return []Definition{Product(), Stock()}
}

// ByCode — product | stock.
func ByCode(code string) (Definition, bool) {
	code = strings.ToLower(strings.TrimSpace(code))
	for _, d := range All() {
		if d.Code == code || strings.TrimSuffix(code, "_preset") == d.Code {
			return d, true
		}
	}
	switch code {
	case "product", "products":
		return Product(), true
	case "stock", "stocks", "warehouse":
		return Stock(), true
	}
	return Definition{}, false
}

// Product — номенклатура SKU (maniforge_products).
func Product() Definition {
	max64 := 64
	max255 := 255
	max32 := 32
	max13 := 13
	return Definition{
		Code: "product",
		Name: "Product (SKU)",
		Fields: []model.FieldDef{
			{Name: "code", Type: model.FieldString, Required: true, MaxLength: &max64},
			{Name: "name", Type: model.FieldString, Required: true, MaxLength: &max255},
			{Name: "unit", Type: model.FieldString, MaxLength: &max32},
			{Name: "description", Type: model.FieldString},
			{Name: "barcode_ean13", Type: model.FieldString, MaxLength: &max13},
			{Name: "attributes", Type: model.FieldObject},
		},
		Metadata: map[string]any{
			"preset": "product", "module": "supply_chain",
			"php_table": "maniforge_products", "php_module": "/products",
		},
	}
}

// Stock — складской узел WMS (maniforge_wh_stocks).
func Stock() Definition {
	max64 := 64
	max255 := 255
	max32 := 32
	return Definition{
		Code: "stock",
		Name: "Stock node (warehouse)",
		Fields: []model.FieldDef{
			{Name: "code", Type: model.FieldString, Required: true, MaxLength: &max64},
			{Name: "name", Type: model.FieldString, Required: true, MaxLength: &max255},
			{Name: "type_code", Type: model.FieldString, MaxLength: &max32},
			{Name: "parent_code", Type: model.FieldString, MaxLength: &max64},
			{Name: "description", Type: model.FieldString},
			{Name: "status", Type: model.FieldString, MaxLength: &max32},
		},
		Metadata: map[string]any{
			"preset": "stock", "module": "supply_chain",
			"php_table": "maniforge_wh_stocks", "php_module": "/warehouses",
		},
	}
}

// PublicList — каталог presets для API.
func PublicList() []map[string]any {
	out := make([]map[string]any, 0, len(All()))
	for _, d := range All() {
		out = append(out, map[string]any{
			"code": d.Code, "name": d.Name, "metadata": d.Metadata,
			"origin": "platform", "fields_count": len(d.Fields),
		})
	}
	return out
}

// IsReservedCode — код зарезервирован под platform preset (клиент не может POST /manifests).
func IsReservedCode(code string) bool {
	code = strings.ToLower(strings.TrimSpace(code))
	for _, d := range All() {
		if d.Code == code {
			return true
		}
	}
	return false
}

// ValidateCode возвращает ошибку если preset неизвестен.
func ValidateCode(code string) error {
	if _, ok := ByCode(code); ok {
		return nil
	}
	return fmt.Errorf("неизвестный preset: %s (доступны: product, stock)", code)
}
