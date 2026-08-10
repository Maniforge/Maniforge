// Файл: projects.go
// Назначение: projects и global-variables HTTP handlers.
package handler

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
	"maniforge/internal/versioning"
)

type ProjectsHandler struct {
	projects *service.ProjectService
	guard    *service.RequestGuard
}

func NewProjectsHandler(cfg config.Config, db *sql.DB) *ProjectsHandler {
	roles := repository.NewRoleRepository(db)
	rbac := service.NewRbacService(roles)
	sessions := repository.NewSessionRepository(db)
	actions := service.NewActionTokenService(cfg, repository.NewActionTokenRepository(db))
	policies := service.NewPolicyService(repository.NewPolicyRuleRepository(db))
	mfa := service.NewMFAService(cfg, db)
	guard := service.NewRequestGuard(cfg, sessions, actions, rbac, policies, mfa)
	projects := service.NewProjectService(
		repository.NewProjectRepository(db),
		repository.NewScopeVariableRepository(db),
		rbac,
		versioning.NewRecorder(cfg, db),
	)
	return &ProjectsHandler{projects: projects, guard: guard}
}

func (h *ProjectsHandler) List(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "projects.read", c, false); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	payload, status := h.projects.ListProjects(session)
	return httpx.JSON(c, status, payload)
}

func (h *ProjectsHandler) Create(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "projects.create", c, true); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.projects.CreateProject(session, input)
	return httpx.JSON(c, status, payload)
}

func (h *ProjectsHandler) CreateGlobalVariable(c *fiber.Ctx) error {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	if payload, status := h.guard.GuardPermission(session, "scope_variables.create", c, true); status != 0 {
		return httpx.JSON(c, status, payload)
	}
	var input map[string]any
	if err := c.BodyParser(&input); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.projects.CreateGlobalVariable(session, input)
	return httpx.JSON(c, status, payload)
}
