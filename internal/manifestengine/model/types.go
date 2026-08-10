// Package manifestengine — динамические сущности: manifest → CRUD + field-level API.
//
// Файл: types.go
// Назначение: модели manifest, field definitions, scope сессии.
// См. также: docs/MANIFORGE_MANIFEST_ENGINE.md
// Package model — типы, валидация и field-path Manifest Engine.
package model

import "time"

// FieldType — поддерживаемые типы полей MVP.
type FieldType string

const (
	FieldString  FieldType = "string"
	FieldNumber  FieldType = "number"
	FieldBoolean FieldType = "boolean"
	FieldArray   FieldType = "array"
	FieldObject  FieldType = "object"
)

// FieldDef — описание поля в манифесте.
type FieldDef struct {
	Name       string    `json:"name"`
	Type       FieldType `json:"type"`
	Required   bool      `json:"required,omitempty"`
	MaxLength  *int      `json:"max_length,omitempty"`
	Min        *float64  `json:"min,omitempty"`
	Max        *float64  `json:"max,omitempty"`
	Items      *FieldDef `json:"items,omitempty"`
	ReadRoles  []string  `json:"read_roles,omitempty"`
	WriteRoles []string  `json:"write_roles,omitempty"`
}

// Manifest — определение сущности в scope tenant + project.
type Manifest struct {
	ID           int64
	TenantID     string
	ProjectID    int64
	Code         string
	Name         string
	Version      int
	Status       string
	Origin       string // platform | custom
	Fields       []FieldDef
	Metadata     map[string]any
	CreatedBy    *int64
	CreatedAt    time.Time
	UpdatedAt    *time.Time
}

// Record — экземпляр данных по манифесту.
type Record struct {
	ID         int64
	ManifestID int64
	TenantID   string
	ProjectID  int64
	Data       map[string]any
	CreatedBy  *int64
	UpdatedBy  *int64
	CreatedAt  time.Time
	UpdatedAt  *time.Time
}

// Scope — контур из RBAC-сессии.
type Scope struct {
	TenantID    string
	SubtenantID string
	ProjectID   int64
	UserID      int64
}
