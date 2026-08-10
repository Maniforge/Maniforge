// Файл: guard.go
// Назначение: step-up (action token / mfa_verified_at) и admin guards.
// См. также: middleware/csrf.go, handler/admin.go
package service

import (
	"strings"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/rbac/repository"
)

type RequestGuard struct {
	cfg          config.Config
	sessions     *repository.SessionRepository
	actionTokens *ActionTokenService
	rbac         *RbacService
	policies     *PolicyService
	mfa          *MFAService
}

func NewRequestGuard(
	cfg config.Config,
	sessions *repository.SessionRepository,
	actions *ActionTokenService,
	rbac *RbacService,
	policies *PolicyService,
	mfa *MFAService,
) *RequestGuard {
	return &RequestGuard{cfg: cfg, sessions: sessions, actionTokens: actions, rbac: rbac, policies: policies, mfa: mfa}
}

func ActionTokenFromRequest(c *fiber.Ctx) string {
	return strings.TrimSpace(c.Get("X-Action-Token"))
}

func (g *RequestGuard) SatisfiesSensitiveAction(c *fiber.Ctx, session *repository.SessionRecord) bool {
	if g.actionTokens.Authenticate(ActionTokenFromRequest(c), session) {
		return true
	}
	maxAge := g.cfg.RBACMFAStepUpMaxAgeSec
	if maxAge <= 0 {
		maxAge = 900
	}
	return g.sessions.IsStepUpFresh(session.ID, maxAge)
}

func (g *RequestGuard) StepUpRequiredError() map[string]any {
	return map[string]any{
		"ok": false, "code": "step_up_required",
		"error": "Требуется step-up: POST /api/v1/auth/reauth, затем X-Action-Token",
	}
}

func (g *RequestGuard) GuardAdmin(session *repository.SessionRecord, permission string, c *fiber.Ctx) (map[string]any, int) {
	return g.guardAdminCore(session, permission, c, isMutatingMethod(c.Method()))
}

// GuardAdminRead — admin GET без step-up (паритет с PHP AdminController::guardAdmin).
func (g *RequestGuard) GuardAdminRead(session *repository.SessionRecord, permission string, c *fiber.Ctx) (map[string]any, int) {
	return g.guardAdminCore(session, permission, c, false)
}

func (g *RequestGuard) guardAdminCore(session *repository.SessionRecord, permission string, c *fiber.Ctx, mutating bool) (map[string]any, int) {
	ok, err := g.rbac.HasAnyRole(session.UserID, session.TenantID, session.SubtenantID, []string{
		"super_admin", "tenant_admin", "subtenant_admin", "security_auditor",
	})
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if !ok {
		return map[string]any{"ok": false, "error": "Недостаточно прав"}, fiber.StatusForbidden
	}
	hasPerm, err := g.rbac.HasPermission(session.UserID, session.TenantID, session.SubtenantID, permission)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if !hasPerm {
		return map[string]any{"ok": false, "error": "Недостаточно permissions"}, fiber.StatusForbidden
	}
	if g.policies != nil {
		policy := g.policies.AllowsAdminAction(c.IP(), session.TenantID, session.SubtenantID)
		if policy["ok"] == false {
			return map[string]any{"ok": false, "error": policy["error"]}, fiber.StatusForbidden
		}
	}
	if mutating {
		if payload, status := g.requireMFAEnrollment(session); status != 0 {
			return payload, status
		}
		if !g.SatisfiesSensitiveAction(c, session) {
			return g.StepUpRequiredError(), fiber.StatusForbidden
		}
	}
	return nil, 0
}

func (g *RequestGuard) requireMFAEnrollment(session *repository.SessionRecord) (map[string]any, int) {
	if g.policies == nil || g.mfa == nil || !g.policies.RequiresMFAEnrollment(session.TenantID, session.SubtenantID) {
		return nil, 0
	}
	if g.mfa.HasVerifiedTOTP(session) {
		return nil, 0
	}
	return map[string]any{
		"ok": false,
		"code": "mfa_enrollment_required",
		"error": "Требуется TOTP: POST /api/v1/me/mfa/enroll и /me/mfa/verify",
	}, fiber.StatusForbidden
}

func isMutatingMethod(method string) bool {
	switch strings.ToUpper(method) {
	case "POST", "PUT", "PATCH", "DELETE":
		return true
	default:
		return false
	}
}

func (g *RequestGuard) GuardPermission(session *repository.SessionRecord, permission string, c *fiber.Ctx, mutating bool) (map[string]any, int) {
	hasPerm, err := g.rbac.HasPermission(session.UserID, session.TenantID, session.SubtenantID, permission)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if !hasPerm {
		return map[string]any{"ok": false, "error": "Недостаточно permissions"}, fiber.StatusForbidden
	}
	if mutating {
		if payload, status := g.requireMFAEnrollment(session); status != 0 {
			return payload, status
		}
		if !g.SatisfiesSensitiveAction(c, session) {
			return g.StepUpRequiredError(), fiber.StatusForbidden
		}
	}
	return nil, 0
}
