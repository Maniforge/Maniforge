// Package handler — HTTP-обработчики Tenant Licensing.
//
// Файл: handler.go
// Назначение: access-state (tenant+project), tenants/plans/entitlements, events.
// См. также: repository/repository.go, internal/tenantlicensing/app.go
package handler

import (
	"database/sql"
	"strconv"
	"time"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/tenantlicensing/repository"
)

type Handler struct {
	repo *repository.Repository
}

func New(db *sql.DB) *Handler {
	return &Handler{repo: repository.New(db)}
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{
		"ok":      true,
		"service": "tenant-licensing",
		"runtime": "go",
	})
}

// AccessStateProject — основной контракт: tenant + project (контур работ).
// Query ?workspace= — workspace (бывш. subtenant_id), если code проекта не уникален.
func (h *Handler) AccessStateProject(c *fiber.Ctx) error {
	workspace := c.Query("workspace")
	state := h.repo.AccessStateForProject(
		c.Params("tenantCode"),
		c.Params("projectCode"),
		workspace,
	)
	return httpx.OK(c, state)
}

// AccessState — legacy subtenant path (→ project main в workspace). Deprecated.
func (h *Handler) AccessState(c *fiber.Ctx) error {
	state := h.repo.AccessState(c.Params("tenantCode"), c.Params("subtenantCode"))
	return httpx.OK(c, state)
}

func (h *Handler) Tenants(c *fiber.Ctx) error {
	items, err := h.repo.ListTenants(100)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) Plans(c *fiber.Ctx) error {
	items, err := h.repo.ListPlans()
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) Entitlements(c *fiber.Ctx) error {
	tenantCode := c.Params("tenantCode")
	ent := h.repo.Entitlements(tenantCode)
	return httpx.OK(c, fiber.Map{
		"ok":           true,
		"tenant_code":  tenantCode,
		"entitlements": ent,
	})
}

func (h *Handler) UpdateTenant(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	result := h.repo.UpdateTenant(
		c.Params("tenantCode"),
		toString(input["name"]),
		toString(input["status"]),
		"tl_admin_api",
	)
	if !result.OK {
		return httpx.Fail(c, result.Status, result.Error)
	}
	return httpx.OK(c, fiber.Map{"ok": true})
}

func (h *Handler) UpdateSubtenant(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	result := h.repo.UpdateSubtenant(
		c.Params("tenantCode"),
		c.Params("subtenantCode"),
		toString(input["name"]),
		toString(input["status"]),
		"tl_admin_api",
	)
	if !result.OK {
		return httpx.Fail(c, result.Status, result.Error)
	}
	return httpx.OK(c, fiber.Map{"ok": true})
}

func (h *Handler) Events(c *fiber.Ctx) error {
	limit, _ := strconv.Atoi(c.Query("limit", "50"))
	items, err := h.repo.ListEvents(c.Query("tenant_code"), limit)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) PendingEvents(c *fiber.Ctx) error {
	items, err := h.repo.PendingEvents(50)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) AckEvent(c *fiber.Ctx) error {
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid event id")
	}
	ok, err := h.repo.AckEvent(id)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": ok})
}

func (h *Handler) ListSubtenants(c *fiber.Ctx) error {
	items, err := h.repo.ListSubtenants(c.Params("tenantCode"))
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) CreateTenant(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	meta, _ := input["metadata"].(map[string]any)
	result := h.repo.CreateTenant(toString(input["code"]), toString(input["name"]), "tl_admin_api", meta)
	return writeResult(c, result)
}

func (h *Handler) CreateSubtenant(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	meta, _ := input["metadata"].(map[string]any)
	result := h.repo.CreateSubtenant(
		c.Params("tenantCode"),
		toString(input["code"]),
		toString(input["name"]),
		"tl_admin_api",
		meta,
	)
	return writeResult(c, result)
}

func (h *Handler) AssignLicense(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	var seats *int
	if v, ok := input["seats_max"]; ok && v != nil && v != "" {
		n := int(toFloat(v))
		if n > 0 {
			seats = &n
		}
	}
	result := h.repo.AssignLicense(
		toString(input["tenant_code"]),
		toString(input["plan_code"]),
		"tl_admin_api",
		parseOptionalTime(input["expires_at"]),
		seats,
	)
	return writeResult(c, result)
}

func (h *Handler) UpsertPlan(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	planCode := c.Params("code")
	if planCode == "" {
		planCode = toString(input["code"])
	}
	features, _ := input["features"].(map[string]any)
	limits, _ := input["limits"].(map[string]any)
	status := toString(input["status"])
	if status == "" {
		status = "active"
	}
	return writeResult(c, h.repo.UpsertPlan(planCode, toString(input["name"]), status, features, limits, "tl_admin_api"))
}

func (h *Handler) ListLicenses(c *fiber.Ctx) error {
	limit, _ := strconv.Atoi(c.Query("limit", "100"))
	items, err := h.repo.ListLicenses(limit)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) UpdateLicense(c *fiber.Ctx) error {
	id, err := strconv.ParseInt(c.Params("id"), 10, 64)
	if err != nil || id <= 0 {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid license id")
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	license, err := h.repo.FindLicense(id)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	if license == nil {
		return httpx.Fail(c, fiber.StatusNotFound, "license не найдена")
	}
	status := toString(input["status"])
	var expires *time.Time
	if _, ok := input["expires_at"]; ok {
		expires = parseOptionalTime(input["expires_at"])
	} else {
		expires = parseOptionalTime(license["expires_at"])
	}
	var seats *int
	if _, ok := input["seats_max"]; ok {
		n := int(toFloat(input["seats_max"]))
		if n > 0 {
			seats = &n
		}
	} else if n := int(toFloat(license["seats_max"])); n > 0 {
		seats = &n
	}
	return writeResult(c, h.repo.UpdateLicense(id, status, expires, seats, "tl_admin_api"))
}

func (h *Handler) RevokeLicense(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	return writeResult(c, h.repo.RevokeLicense(toString(input["tenant_code"]), "tl_admin_api", toString(input["reason"])))
}

func (h *Handler) Quota(c *fiber.Ctx) error {
	items, err := h.repo.ListQuota(c.Params("tenantCode"), c.Query("metric"))
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "tenant_code": c.Params("tenantCode"), "items": items})
}

func (h *Handler) OpsSummary(c *fiber.Ctx) error {
	summary, err := h.repo.PlatformOpsSummary()
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "summary": summary})
}

func (h *Handler) Audit(c *fiber.Ctx) error {
	limit, _ := strconv.Atoi(c.Query("limit", "100"))
	items, err := h.repo.ListAudit(c.Query("tenant_code"), limit)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "items": items})
}

func (h *Handler) ListManagedTenants(c *fiber.Ctx) error {
	items, err := h.repo.ListManagedTenants(c.Params("tenantCode"), true)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	return httpx.OK(c, fiber.Map{"ok": true, "agency_code": c.Params("tenantCode"), "items": items})
}

func (h *Handler) CreateManagedTenant(c *fiber.Ctx) error {
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	meta, _ := input["metadata"].(map[string]any)
	return writeResult(c, h.repo.CreateManagedTenantGrant(
		c.Params("tenantCode"),
		toString(input["managed_tenant_code"]),
		toString(input["grant_level"]),
		"tl_admin_api",
		meta,
	))
}

func (h *Handler) RevokeManagedTenant(c *fiber.Ctx) error {
	return writeResult(c, h.repo.RevokeManagedTenantGrant(
		c.Params("tenantCode"),
		c.Params("managedCode"),
		"tl_admin_api",
	))
}

func writeResult(c *fiber.Ctx, result repository.WriteResult) error {
	if !result.OK {
		payload := fiber.Map{"ok": false, "error": result.Error}
		for k, v := range result.Extra {
			payload[k] = v
		}
		return httpx.JSON(c, result.Status, payload)
	}
	payload := fiber.Map{"ok": true}
	for k, v := range result.Extra {
		payload[k] = v
	}
	return httpx.JSON(c, result.Status, payload)
}

func toFloat(v any) float64 {
	switch t := v.(type) {
	case float64:
		return t
	case int:
		return float64(t)
	case int64:
		return float64(t)
	case string:
		n, _ := strconv.ParseFloat(t, 64)
		return n
	default:
		return 0
	}
}

func parseOptionalTime(v any) *time.Time {
	s := toString(v)
	if s == "" {
		return nil
	}
	for _, layout := range []string{time.RFC3339, "2006-01-02 15:04:05", "2006-01-02"} {
		if t, err := time.Parse(layout, s); err == nil {
			utc := t.UTC()
			return &utc
		}
	}
	return nil
}

func toString(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}
