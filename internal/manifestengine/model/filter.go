// Файл: filter.go
// Назначение: парсинг ?filter= для list records (JSONB equality + ILIKE).
package model

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
)

var fieldNamePattern = regexp.MustCompile(`^[a-zA-Z_][a-zA-Z0-9_]*$`)

// RecordFilter — условия по полям manifest (AND).
type RecordFilter map[string]any

// ParseRecordFilter разбирает JSON из query ?filter=.
func ParseRecordFilter(raw string) (RecordFilter, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return nil, nil
	}
	var f RecordFilter
	if err := json.Unmarshal([]byte(raw), &f); err != nil {
		return nil, fmt.Errorf("filter: невалидный JSON")
	}
	if len(f) == 0 {
		return nil, nil
	}
	return f, nil
}

// ValidateFilterKeys — только объявленные поля manifest.
func ValidateFilterKeys(fields []FieldDef, filter RecordFilter) error {
	if len(filter) == 0 {
		return nil
	}
	index := make(map[string]FieldDef, len(fields))
	for _, f := range fields {
		index[f.Name] = f
	}
	for key := range filter {
		if !fieldNamePattern.MatchString(key) {
			return fmt.Errorf("filter: недопустимое имя поля %q", key)
		}
		if _, ok := index[key]; !ok {
			return fmt.Errorf("filter: неизвестное поле %q", key)
		}
	}
	return nil
}

// IsLikePattern — строковый фильтр с % для ILIKE.
func IsLikePattern(v any) (string, bool) {
	s, ok := v.(string)
	if !ok {
		return "", false
	}
	return s, strings.Contains(s, "%")
}
