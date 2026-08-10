// Файл: privacy.go
// Назначение: GET /api/v1/privacy/notice.
package handler

import (
	"database/sql"
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/code"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
)

type PrivacyHandler struct {
	cfg config.Config
	pd  *repository.PDRepository
}

func NewPrivacyHandler(cfg config.Config, db *sql.DB) *PrivacyHandler {
	return &PrivacyHandler{cfg: cfg, pd: repository.NewPDRepository(db, cfg)}
}

func (h *PrivacyHandler) Notice(c *fiber.Ctx) error {
	tenantID, subtenantID, ok := h.resolveTenant(c)
	if !ok {
		return httpx.JSON(c, fiber.StatusBadRequest, fiber.Map{
			"ok": false, "error": "Для privacy API укажите X-Tenant-ID и X-Subtenant-ID",
		})
	}
	_ = subtenantID
	payload, status := h.pd.BuildPrivacyNotice(tenantID)
	return httpx.JSON(c, status, payload)
}

func (h *PrivacyHandler) resolveTenant(c *fiber.Ctx) (string, string, bool) {
	tenantID := code.Normalize(c.Get("X-Tenant-ID"))
	subtenantID := code.Normalize(c.Get("X-Subtenant-ID"))
	if tenantID != "" && subtenantID != "" {
		return tenantID, subtenantID, true
	}
	mode := strings.ToLower(h.cfg.TenancyMode)
	if mode == "single" || mode == "disabled" {
		return h.cfg.DefaultTenantID, h.cfg.DefaultSubtenantID, true
	}
	return "", "", false
}
