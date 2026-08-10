package model

import (
	"fmt"
	"strconv"
	"strings"
)

// ClearFieldPath сбрасывает поле в null по пути (DELETE field-level API).
func ClearFieldPath(root map[string]any, path string) error {
	return SetFieldPath(root, path, nil)
}

// SetFieldPath устанавливает значение по пути variants/0/price в map[string]any.
func SetFieldPath(root map[string]any, path string, value any) error {
	parts := splitPath(path)
	if len(parts) == 0 {
		return fmt.Errorf("пустой field path")
	}
	cur := any(root)
	for i, part := range parts {
		last := i == len(parts)-1
		switch node := cur.(type) {
		case map[string]any:
			if last {
				node[part] = value
				return nil
			}
			next, ok := node[part]
			if !ok {
				child := map[string]any{}
				node[part] = child
				cur = child
				continue
			}
			cur = next
		case []any:
			idx, err := strconv.Atoi(part)
			if err != nil || idx < 0 || idx >= len(node) {
				return fmt.Errorf("неверный индекс массива: %s", part)
			}
			if last {
				node[idx] = value
				return nil
			}
			cur = node[idx]
		default:
			return fmt.Errorf("невозможно пройти path на %s", part)
		}
	}
	return nil
}

// GetFieldPath читает значение по пути.
func GetFieldPath(root map[string]any, path string) (any, error) {
	parts := splitPath(path)
	cur := any(root)
	for _, part := range parts {
		switch node := cur.(type) {
		case map[string]any:
			next, ok := node[part]
			if !ok {
				return nil, fmt.Errorf("поле не найдено: %s", part)
			}
			cur = next
		case []any:
			idx, err := strconv.Atoi(part)
			if err != nil || idx < 0 || idx >= len(node) {
				return nil, fmt.Errorf("неверный индекс: %s", part)
			}
			cur = node[idx]
		default:
			return nil, fmt.Errorf("невозможно пройти path на %s", part)
		}
	}
	return cur, nil
}

func splitPath(path string) []string {
	path = strings.Trim(path, "/")
	if path == "" {
		return nil
	}
	return strings.Split(path, "/")
}
