// Файл: stock_type.go
// Назначение: каталог типов складских узлов maniforge_wh_stock_types.
// См. также: app/Maniforge/Warehouses/Repository/StockTypeRepository.php
package repository

import (
	"database/sql"
	"encoding/json"
)

type StockType struct {
	Code           string
	Name           string
	NameEn         string
	Description    string
	AllowedParents []string
	DataSchema     map[string]any
	SortOrder      int
	Active         bool
}

type StockTypeRepository struct {
	db *sql.DB
}

func NewStockTypeRepository(db *sql.DB) *StockTypeRepository {
	return &StockTypeRepository{db: db}
}

func (r *StockTypeRepository) ListActive() ([]StockType, error) {
	rows, err := r.db.Query(
		`SELECT code, name, COALESCE(name_en, ''), COALESCE(description, ''),
		        allowed_parents_json, data_schema_json, sort_order, active
		 FROM maniforge_wh_stock_types WHERE active = TRUE
		 ORDER BY sort_order ASC, name ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var items []StockType
	for rows.Next() {
		t, err := r.scan(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, t)
	}
	return items, rows.Err()
}

func (r *StockTypeRepository) FindByCode(code string) (*StockType, error) {
	row := r.db.QueryRow(
		`SELECT code, name, COALESCE(name_en, ''), COALESCE(description, ''),
		        allowed_parents_json, data_schema_json, sort_order, active
		 FROM maniforge_wh_stock_types WHERE code = $1 LIMIT 1`, code)
	t, err := r.scanRow(row)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return t, err
}

func (r *StockTypeRepository) CanBeChildOf(childType string, parentType *string) (bool, error) {
	t, err := r.FindByCode(childType)
	if err != nil || t == nil {
		return false, err
	}
	if parentType == nil || *parentType == "" {
		return len(t.AllowedParents) == 0, nil
	}
	for _, p := range t.AllowedParents {
		if p == *parentType {
			return true, nil
		}
	}
	return false, nil
}

func (r *StockTypeRepository) scan(rows *sql.Rows) (StockType, error) {
	var (
		t          StockType
		allowedRaw []byte
		schemaRaw  []byte
	)
	err := rows.Scan(&t.Code, &t.Name, &t.NameEn, &t.Description, &allowedRaw, &schemaRaw, &t.SortOrder, &t.Active)
	if err != nil {
		return StockType{}, err
	}
	decodeJSON(allowedRaw, &t.AllowedParents)
	decodeJSON(schemaRaw, &t.DataSchema)
	return t, nil
}

func (r *StockTypeRepository) scanRow(row *sql.Row) (*StockType, error) {
	var (
		t          StockType
		allowedRaw []byte
		schemaRaw  []byte
	)
	err := row.Scan(&t.Code, &t.Name, &t.NameEn, &t.Description, &allowedRaw, &schemaRaw, &t.SortOrder, &t.Active)
	if err != nil {
		return nil, err
	}
	decodeJSON(allowedRaw, &t.AllowedParents)
	decodeJSON(schemaRaw, &t.DataSchema)
	return &t, nil
}

func (t StockType) ToMap() map[string]any {
	return map[string]any{
		"code": t.Code, "name": t.Name, "name_en": t.NameEn, "description": t.Description,
		"allowed_parents": t.AllowedParents, "data_schema": t.DataSchema,
		"sort_order": t.SortOrder, "active": t.Active,
	}
}

func decodeJSON(raw []byte, target any) {
	if len(raw) == 0 {
		return
	}
	_ = json.Unmarshal(raw, target)
}
