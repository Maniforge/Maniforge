// Файл: mfa.go
// Назначение: HTTP handlers TOTP MFA (/me/mfa/*).
// См. также: service/mfa.go
package handler

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
)

type MFAHandler struct {
	mfa  *service.MFAService
	rbac *service.RbacService
}

func NewMFAHandler(cfg config.Config, db *sql.DB) *MFAHandler {
	roles := repository.NewRoleRepository(db)
	return &MFAHandler{
		mfa:  service.NewMFAService(cfg, db),
		rbac: service.NewRbacService(roles),
	}
}

func (h *MFAHandler) Status(c *fiber.Ctx) error {
	session, err := h.requireSession(c)
	if err != nil {
		return err
	}
	if err := h.requirePerm(c, session, "me.mfa.manage"); err != nil {
		return err
	}
	payload, status := h.mfa.Status(session)
	return httpx.JSON(c, status, payload)
}

func (h *MFAHandler) Enroll(c *fiber.Ctx) error {
	session, err := h.requireSession(c)
	if err != nil {
		return err
	}
	if err := h.requirePerm(c, session, "me.mfa.manage"); err != nil {
		return err
	}
	var req struct {
		Label string `json:"label"`
	}
	_ = c.BodyParser(&req)
	payload, status := h.mfa.Enroll(session, req.Label)
	return httpx.JSON(c, status, payload)
}

func (h *MFAHandler) Verify(c *fiber.Ctx) error {
	session, err := h.requireSession(c)
	if err != nil {
		return err
	}
	if err := h.requirePerm(c, session, "me.mfa.manage"); err != nil {
		return err
	}
	var req struct {
		Code string `json:"code"`
	}
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.mfa.VerifyEnroll(session, req.Code)
	return httpx.JSON(c, status, payload)
}

func (h *MFAHandler) Disable(c *fiber.Ctx) error {
	session, err := h.requireSession(c)
	if err != nil {
		return err
	}
	if err := h.requirePerm(c, session, "me.mfa.manage"); err != nil {
		return err
	}
	var req struct {
		Password     string `json:"password"`
		TotpCode     string `json:"totp_code"`
		RecoveryCode string `json:"recovery_code"`
	}
	if err := c.BodyParser(&req); err != nil {
		return httpx.Fail(c, fiber.StatusBadRequest, "invalid json")
	}
	payload, status := h.mfa.Disable(session, req.Password, req.TotpCode, req.RecoveryCode)
	return httpx.JSON(c, status, payload)
}

func (h *MFAHandler) requireSession(c *fiber.Ctx) (*repository.SessionRecord, error) {
	session, ok := c.Locals("maniforge_session").(*repository.SessionRecord)
	if !ok || session == nil {
		return nil, httpx.Fail(c, fiber.StatusUnauthorized, "Не авторизован")
	}
	return session, nil
}

func (h *MFAHandler) requirePerm(c *fiber.Ctx, session *repository.SessionRecord, perm string) error {
	ok, err := h.rbac.HasPermission(session.UserID, session.TenantID, session.SubtenantID, perm)
	if err != nil {
		return httpx.Fail(c, fiber.StatusInternalServerError, err.Error())
	}
	if !ok {
		return httpx.JSON(c, fiber.StatusForbidden, fiber.Map{
			"ok": false, "error": "Недостаточно permissions",
		})
	}
	return nil
}
