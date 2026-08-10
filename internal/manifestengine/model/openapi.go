// Файл: openapi.go
// Назначение: автогенерация OpenAPI 3 из manifest.fields[] → REST /api/data.
package model

import (
	"fmt"

	"gopkg.in/yaml.v3"
)

// OpenAPISpec генерирует OpenAPI 3 схему для манифеста (paths + schema из fields[]).
func OpenAPISpec(m *Manifest, baseURL string) map[string]any {
	entity := m.Code
	prefix := fmt.Sprintf("/api/data/%s", entity)
	schema := recordSchema(m.Fields)
	paths := map[string]any{
		prefix: map[string]any{
			"get": map[string]any{
				"summary": fmt.Sprintf("List %s", entity),
				"parameters": []map[string]any{
					{"name": "limit", "in": "query", "schema": map[string]any{"type": "integer", "default": 50}},
					{"name": "offset", "in": "query", "schema": map[string]any{"type": "integer", "default": 0}},
					{
						"name": "filter", "in": "query",
						"description": "JSON object: field equality or ILIKE with % (e.g. {\"title\":\"%foo%\"})",
						"schema": map[string]any{"type": "string"},
					},
				},
				"responses": map[string]any{"200": map[string]any{"description": "OK"}},
			},
			"post": map[string]any{
				"summary": fmt.Sprintf("Create %s", entity),
				"requestBody": map[string]any{
					"content": map[string]any{
						"application/json": map[string]any{"schema": schema},
					},
				},
				"responses": map[string]any{"201": map[string]any{"description": "Created"}},
			},
		},
		prefix + "/{id}": map[string]any{
			"get": map[string]any{
				"summary": "Get record",
				"responses": map[string]any{"200": map[string]any{"description": "OK"}},
			},
			"patch": map[string]any{
				"summary": "Patch record",
				"requestBody": map[string]any{
					"content": map[string]any{
						"application/json": map[string]any{"schema": schema},
					},
				},
				"responses": map[string]any{"200": map[string]any{"description": "OK"}},
			},
			"delete": map[string]any{
				"summary": "Delete record",
				"responses": map[string]any{"200": map[string]any{"description": "OK"}},
			},
		},
		prefix + "/{id}/{fieldPath}": fieldPathMethods("", nil),
	}

	for _, f := range m.Fields {
		fieldPath := prefix + "/{id}/" + f.Name
		paths[fieldPath] = fieldPathMethods(f.Name, &f)
	}

	return map[string]any{
		"openapi": "3.0.3",
		"info": map[string]any{
			"title":       m.Name,
			"version":     fmt.Sprintf("%d", m.Version),
			"description": fmt.Sprintf("Maniforge manifest %s (autogen from fields[])", m.Code),
		},
		"servers": []map[string]any{{"url": baseURL}},
		"paths":   paths,
	}
}

// OpenAPIYAML — сериализация OpenAPISpec в YAML.
func OpenAPIYAML(m *Manifest, baseURL string) ([]byte, error) {
	return yaml.Marshal(OpenAPISpec(m, baseURL))
}

func fieldPathMethods(name string, def *FieldDef) map[string]any {
	putSummary := "Field-level update (nested path)"
	delSummary := "Field-level clear (null)"
	if name != "" {
		putSummary = fmt.Sprintf("Update field %s", name)
		delSummary = fmt.Sprintf("Clear field %s (null)", name)
	}
	valueSchema := map[string]any{"description": "Новое значение поля"}
	if def != nil {
		valueSchema = fieldToSchema(*def)
	}
	return map[string]any{
		"put": map[string]any{
			"summary": putSummary,
			"requestBody": map[string]any{
				"content": map[string]any{
					"application/json": map[string]any{
						"schema": map[string]any{
							"type": "object",
							"properties": map[string]any{
								"value": valueSchema,
							},
							"required": []string{"value"},
						},
					},
				},
			},
			"responses": map[string]any{"200": map[string]any{"description": "OK"}},
		},
		"delete": map[string]any{
			"summary":   delSummary,
			"responses": map[string]any{"200": map[string]any{"description": "Field cleared (null)"}},
		},
	}
}

func recordSchema(fields []FieldDef) map[string]any {
	props := map[string]any{}
	required := []string{}
	for _, f := range fields {
		props[f.Name] = fieldToSchema(f)
		if f.Required {
			required = append(required, f.Name)
		}
	}
	schema := map[string]any{
		"type":       "object",
		"properties": props,
	}
	if len(required) > 0 {
		schema["required"] = required
	}
	return schema
}

func fieldToSchema(f FieldDef) map[string]any {
	switch f.Type {
	case FieldString:
		s := map[string]any{"type": "string"}
		if f.MaxLength != nil {
			s["maxLength"] = *f.MaxLength
		}
		return s
	case FieldNumber:
		s := map[string]any{"type": "number"}
		if f.Min != nil {
			s["minimum"] = *f.Min
		}
		if f.Max != nil {
			s["maximum"] = *f.Max
		}
		return s
	case FieldBoolean:
		return map[string]any{"type": "boolean"}
	case FieldArray:
		items := map[string]any{"type": "string"}
		if f.Items != nil {
			items = fieldToSchema(*f.Items)
		}
		return map[string]any{"type": "array", "items": items}
	default:
		return map[string]any{"type": "object"}
	}
}
