// Файл: catalog.go
// Назначение: control plane — каталог типов полей для конструктора.
package service

import (
	"github.com/gofiber/fiber/v2"
	"maniforge/internal/manifestengine/catalog"
	"maniforge/internal/manifestengine/model"
)

func (s *Service) ListFieldTypes(scope model.Scope) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	repo := catalog.New(s.shared)
	items, err := repo.ListActive()
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	out := make([]map[string]any, 0, len(items))
	for _, ft := range items {
		out = append(out, map[string]any{
			"code": ft.Code, "label": ft.Label, "description": ft.Description,
			"category": ft.Category, "supports_items": ft.SupportsItems,
		})
	}
	return ok(map[string]any{
		"field_types": out,
		"plane":       "control",
	}, fiber.StatusOK)
}
