// Package handler — HTTP Manifest Engine.
package handler

import (
	"database/sql"
	"strconv"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/dataplane"
	"maniforge/internal/manifestengine/service"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"

)

type Handler struct {
	svc *service.Service
	cfg config.Config
}

func New(cfg config.Config, db *sql.DB, dp *dataplane.Resolver) *Handler {
	return &Handler{svc: service.New(cfg, db, dp), cfg: cfg}
}

func (h *Handler) scope(c *fiber.Ctx) (service.Scope, error) {
	session, _ := c.Locals("maniforge_session").(*repository.SessionRecord)
	return service.ScopeFromSession(session)
}

func (h *Handler) ListFieldTypes(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	payload, status := h.svc.ListFieldTypes(scope)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) ListPresets(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	payload, status := h.svc.ListPresets(scope)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) InstallPreset(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	payload, status := h.svc.InstallPreset(scope, c.Params("code"))
	return httpx.JSON(c, status, payload)
}

func (h *Handler) CreateManifest(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	var in service.CreateManifestInput
	if err := c.BodyParser(&in); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.svc.CreateManifest(scope, in)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) ListManifests(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	payload, status := h.svc.ListManifests(scope, c.Query("origin"))
	return httpx.JSON(c, status, payload)
}

func (h *Handler) GetManifest(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	payload, status := h.svc.GetManifest(scope, c.Params("code"))
	return httpx.JSON(c, status, payload)
}

func (h *Handler) UpdateManifest(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	var in service.CreateManifestInput
	if err := c.BodyParser(&in); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.svc.UpdateManifest(scope, c.Params("code"), in)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) DeleteManifest(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	payload, status := h.svc.DeleteManifest(scope, c.Params("code"))
	return httpx.JSON(c, status, payload)
}

func (h *Handler) OpenAPI(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	base := h.cfg.AppURL
	if base == "" {
		base = "http://127.0.0.1:8095"
	}
	payload, status := h.svc.OpenAPI(scope, c.Params("code"), base+"/api/data")
	return httpx.JSON(c, status, payload)
}

func (h *Handler) OpenAPIYAML(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	base := h.cfg.AppURL
	if base == "" {
		base = "http://127.0.0.1:8095"
	}
	raw, status, msg := h.svc.OpenAPIYAML(scope, c.Params("code"), base+"/api/data")
	if status != fiber.StatusOK {
		return httpx.Fail(c, status, msg)
	}
	c.Set("Content-Type", "application/yaml; charset=utf-8")
	return c.Send(raw)
}

func (h *Handler) ListRecords(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	limit, _ := strconv.Atoi(c.Query("limit", "50"))
	offset, _ := strconv.Atoi(c.Query("offset", "0"))
	payload, status := h.svc.ListRecords(scope, c.Params("entity"), limit, offset, c.Query("filter"))
	return httpx.JSON(c, status, payload)
}

func (h *Handler) CreateRecord(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	var body map[string]any
	if err := c.BodyParser(&body); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.svc.CreateRecord(scope, c.Params("entity"), body)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) GetRecord(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	id, err := service.ParseID(c.Params("id"))
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.svc.GetRecord(scope, c.Params("entity"), id)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) PatchRecord(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	id, err := service.ParseID(c.Params("id"))
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	var body map[string]any
	if err := c.BodyParser(&body); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.svc.PatchRecord(scope, c.Params("entity"), id, body)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) PutField(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	id, err := service.ParseID(c.Params("id"))
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	fieldPath := c.Params("*")
	var body struct {
		Value any `json:"value"`
	}
	if err := c.BodyParser(&body); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.svc.PutField(scope, c.Params("entity"), id, fieldPath, body.Value)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) DeleteField(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	id, err := service.ParseID(c.Params("id"))
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	fieldPath := c.Params("*")
	payload, status := h.svc.DeleteField(scope, c.Params("entity"), id, fieldPath)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) DeleteRecord(c *fiber.Ctx) error {
	scope, err := h.scope(c)
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, err.Error())
	}
	id, err := service.ParseID(c.Params("id"))
	if err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid id")
	}
	payload, status := h.svc.DeleteRecord(scope, c.Params("entity"), id)
	return httpx.JSON(c, status, payload)
}

func (h *Handler) Health(c *fiber.Ctx) error {
	return httpx.OK(c, fiber.Map{
		"ok": true, "service": "manifest-engine", "runtime": "go",
	})
}
