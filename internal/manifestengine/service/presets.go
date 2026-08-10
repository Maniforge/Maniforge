// Файл: presets.go
// Назначение: установка supply chain manifest presets в project scope.
package service

import (
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/manifestengine/model"
	"maniforge/internal/manifestengine/presets"
	mrepo "maniforge/internal/manifestengine/repository"
)

func (s *Service) ListPresets(scope Scope) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	return ok(map[string]any{"presets": presets.PublicList()}, fiber.StatusOK)
}

func (s *Service) InstallPreset(scope Scope, presetCode string) (map[string]any, int) {
	if deny := s.guardLicensed(scope); deny != nil {
		return deny, int(deny["status"].(int))
	}
	roles, err := s.userRoles(scope)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if !model.IsManifestAdmin(roles) {
		return fail("требуется роль tenant_admin или subtenant_admin", fiber.StatusForbidden)
	}
	if err := presets.ValidateCode(presetCode); err != nil {
		return fail(err.Error(), fiber.StatusUnprocessableEntity)
	}
	def, _ := presets.ByCode(presetCode)

	repo := s.dataRepo(scope)
	existing, err := repo.GetManifestByCode(scope, def.Code)
	if err != nil {
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	if existing != nil {
		return ok(map[string]any{
			"manifest": mrepo.PublicManifest(existing),
			"installed": false,
			"message":  "preset уже установлен",
		}, fiber.StatusOK)
	}

	m, err := repo.CreateManifest(scope, def.Code, def.Name, model.OriginPlatform, def.Fields, def.Metadata, scope.UserID)
	if err != nil {
		if strings.Contains(err.Error(), "duplicate") || strings.Contains(err.Error(), "unique") {
			ex, _ := repo.GetManifestByCode(scope, def.Code)
			if ex != nil {
				return ok(map[string]any{"manifest": mrepo.PublicManifest(ex), "installed": false}, fiber.StatusOK)
			}
		}
		return fail(err.Error(), fiber.StatusInternalServerError)
	}
	_ = repo.WriteAudit("manifest.preset_installed", scope.TenantID, scope.ProjectID, m.Code, nil, scope.UserID, def.Metadata)
	return ok(map[string]any{
		"manifest":  mrepo.PublicManifest(m),
		"installed": true,
		"preset":    def.Metadata["preset"],
	}, fiber.StatusCreated)
}
