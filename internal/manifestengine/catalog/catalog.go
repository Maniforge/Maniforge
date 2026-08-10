// Package catalog — control plane: каталог типов полей для конструктора.
package catalog

import (
	"database/sql"
)

// FieldType — элемент палитры конструктора.
type FieldType struct {
	Code          string `json:"code"`
	Label         string `json:"label"`
	Description   string `json:"description,omitempty"`
	Category      string `json:"category"`
	SupportsItems bool   `json:"supports_items"`
}

// Repository читает maniforge_field_type_catalog.
type Repository struct {
	db *sql.DB
}

func New(db *sql.DB) *Repository {
	return &Repository{db: db}
}

func (r *Repository) ListActive() ([]FieldType, error) {
	rows, err := r.db.Query(
		`SELECT code, label, COALESCE(description, ''), category, supports_items
		 FROM maniforge_field_type_catalog
		 WHERE is_active = TRUE
		 ORDER BY sort_order ASC, code ASC`,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []FieldType
	for rows.Next() {
		var ft FieldType
		if err := rows.Scan(&ft.Code, &ft.Label, &ft.Description, &ft.Category, &ft.SupportsItems); err != nil {
			return nil, err
		}
		out = append(out, ft)
	}
	return out, rows.Err()
}
