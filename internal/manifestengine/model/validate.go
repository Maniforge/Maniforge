package model

import (
	"fmt"
	"strings"
	"unicode/utf8"
)

// ValidateData проверяет payload по fields манифеста (только объявленные поля).
func ValidateData(fields []FieldDef, data map[string]any, partial bool) error {
	index := make(map[string]FieldDef, len(fields))
	for _, f := range fields {
		index[f.Name] = f
	}
	for key, val := range data {
		def, ok := index[key]
		if !ok {
			return fmt.Errorf("неизвестное поле: %s", key)
		}
		if err := validateValue(def, val); err != nil {
			return fmt.Errorf("%s: %w", key, err)
		}
	}
	if partial {
		return nil
	}
	for _, def := range fields {
		if !def.Required {
			continue
		}
		if _, ok := data[def.Name]; !ok {
			return fmt.Errorf("обязательное поле: %s", def.Name)
		}
	}
	return nil
}

func validateValue(def FieldDef, val any) error {
	if val == nil {
		if def.Required {
			return fmt.Errorf("значение обязательно")
		}
		return nil
	}
	switch def.Type {
	case FieldString:
		s, ok := val.(string)
		if !ok {
			return fmt.Errorf("ожидается string")
		}
		if def.MaxLength != nil && utf8.RuneCountInString(s) > *def.MaxLength {
			return fmt.Errorf("превышена max_length %d", *def.MaxLength)
		}
	case FieldNumber:
		if !isNumber(val) {
			return fmt.Errorf("ожидается number")
		}
		n := toFloat(val)
		if def.Min != nil && n < *def.Min {
			return fmt.Errorf("меньше min %v", *def.Min)
		}
		if def.Max != nil && n > *def.Max {
			return fmt.Errorf("больше max %v", *def.Max)
		}
	case FieldBoolean:
		if _, ok := val.(bool); !ok {
			return fmt.Errorf("ожидается boolean")
		}
	case FieldArray:
		arr, ok := val.([]any)
		if !ok {
			return fmt.Errorf("ожидается array")
		}
		if def.Items != nil {
			for i, item := range arr {
				if err := validateValue(*def.Items, item); err != nil {
					return fmt.Errorf("[%d]: %w", i, err)
				}
			}
		}
	case FieldObject:
		if _, ok := val.(map[string]any); !ok {
			return fmt.Errorf("ожидается object")
		}
	default:
		return fmt.Errorf("неподдерживаемый тип %s", def.Type)
	}
	return nil
}

func isNumber(v any) bool {
	switch v.(type) {
	case float64, float32, int, int64, int32:
		return true
	default:
		return false
	}
}

func toFloat(v any) float64 {
	switch n := v.(type) {
	case float64:
		return n
	case float32:
		return float64(n)
	case int:
		return float64(n)
	case int64:
		return float64(n)
	case int32:
		return float64(n)
	default:
		return 0
	}
}

// ParseFieldDefs из JSON-массива API.
func ParseFieldDefs(raw []FieldDef) ([]FieldDef, error) {
	if len(raw) == 0 {
		return []FieldDef{}, nil
	}
	for i, f := range raw {
		f.Name = strings.TrimSpace(f.Name)
		if f.Name == "" {
			return nil, fmt.Errorf("fields[%d]: name обязателен", i)
		}
		if f.Type == "" {
			return nil, fmt.Errorf("fields[%d]: type обязателен", i)
		}
		switch f.Type {
		case FieldString, FieldNumber, FieldBoolean, FieldArray, FieldObject:
		default:
			return nil, fmt.Errorf("fields[%d]: неподдерживаемый type %s", i, f.Type)
		}
		raw[i] = f
	}
	return raw, nil
}
