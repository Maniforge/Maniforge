// Package rbac — Fiber-приложение сервиса аутентификации и RBAC.
package rbac

import (
	"database/sql"
	"log"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/logger"
	"github.com/gofiber/fiber/v2/middleware/recover"
	"maniforge/internal/config"
	platformauth "maniforge/internal/platform/auth"
	"maniforge/internal/platform/httpx"
	"maniforge/internal/platform/middleware"
	"maniforge/internal/rbac/handler"
	rbacmw "maniforge/internal/rbac/middleware"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/service"
)

func NewApp(cfg config.Config, sqlDB *sql.DB) *fiber.App {
	app := fiber.New(fiber.Config{AppName: "maniforge-rbac", ServerHeader: "maniforge-rbac"})
	app.Use(recover.New(), logger.New(), middleware.SecurityHeaders(cfg))

	health := handler.NewHealth(cfg, sqlDB)
	if sqlDB == nil {
		registerHealthOnly(app, health)
		return app
	}

	sessions := service.NewSessionService(cfg, sqlDB)
	sessionRepo := repository.NewSessionRepository(sqlDB)
	authHandler := handler.NewAuthHandler(cfg, sqlDB)
	meHandler := handler.NewMeHandler(cfg, sqlDB)
	adminHandler := handler.NewAdminHandler(cfg, sqlDB)
	pdAdminHandler := handler.NewPDAdminHandler(cfg, sqlDB)
	projectsHandler := handler.NewProjectsHandler(cfg, sqlDB)
	privacyHandler := handler.NewPrivacyHandler(cfg, sqlDB)
	internalHandler := handler.NewInternalHandler(cfg, sqlDB)
	mfaHandler := handler.NewMFAHandler(cfg, sqlDB)
	internalGuard := platformauth.GuardServiceToken(cfg, cfg.RBACInternalTokenEffective())

	register := func(router fiber.Router) {
		router.Get("/health", health.Handle)

		api := router.Group("/api/v1")
		api.Use(rbacmw.TenantResolver(cfg))
		api.Get("/privacy/notice", privacyHandler.Notice)
		api.Post("/auth/register", rbacmw.AuthRateLimitGuard(cfg, sqlDB, "register"), authHandler.Register)
		api.Post("/auth/login", rbacmw.AuthRateLimitGuard(cfg, sqlDB, "login"), authHandler.Login)
		api.Post("/auth/refresh", rbacmw.AuthRateLimitGuard(cfg, sqlDB, "refresh"), authHandler.Refresh)

		protected := api.Group("",
			rbacmw.SessionAuth(sessions),
			rbacmw.UserRateLimitGuard(cfg, sqlDB),
			rbacmw.DelegatedMutationGuard(cfg, sqlDB),
			rbacmw.CsrfGuard(sessionRepo),
		)
		protected.Post("/auth/logout", authHandler.Logout)
		protected.Post("/auth/reauth", authHandler.Reauth)
		protected.Post("/auth/switch-context", authHandler.SwitchContext)
		protected.Get("/me", meHandler.Me)
		protected.Get("/me/profile", meHandler.Profile)
		protected.Get("/me/permissions", meHandler.Permissions)
		protected.Get("/me/contexts", meHandler.Contexts)
		protected.Get("/me/access", meHandler.Access)
		protected.Get("/me/console-access", meHandler.ConsoleAccess)
		protected.Patch("/me/profile", meHandler.PatchProfile)
		protected.Patch("/me/identity", meHandler.PatchIdentity)
		protected.Post("/me/change-password", meHandler.ChangePassword)
		protected.Get("/me/mfa", mfaHandler.Status)
		protected.Post("/me/mfa/enroll", mfaHandler.Enroll)
		protected.Post("/me/mfa/verify", mfaHandler.Verify)
		protected.Post("/me/mfa/disable", mfaHandler.Disable)
		protected.Get("/projects", projectsHandler.List)
		protected.Post("/projects", projectsHandler.Create)
		protected.Post("/global-variables", projectsHandler.CreateGlobalVariable)
		protected.Get("/admin/users", adminHandler.ListUsers)
		protected.Post("/admin/users", adminHandler.CreateUser)
		protected.Post("/admin/users/batch-status", adminHandler.BatchUserStatus)
		protected.Post("/admin/user-roles/assign", adminHandler.AssignUserRole)
		protected.Get("/admin/user-roles", adminHandler.ListUserRoles)
		protected.Get("/admin/effective-access", adminHandler.EffectiveAccess)
		protected.Get("/admin/policies", adminHandler.GetPolicies)
		protected.Post("/admin/policies", adminHandler.UpdatePolicies)
		protected.Get("/admin/ops-summary", adminHandler.OpsSummary)
		protected.Get("/admin/sessions", adminHandler.ListSessions)
		protected.Post("/admin/sessions/revoke", adminHandler.RevokeSession)
		protected.Post("/admin/sessions/batch-revoke", adminHandler.BatchRevokeSessions)
		protected.Get("/admin/audit", adminHandler.ListAudit)
		protected.Get("/admin/audit/export", adminHandler.ExportAudit)
		protected.Get("/admin/security-events", adminHandler.ListSecurityEvents)
		protected.Get("/admin/roles", adminHandler.ListRoles)
		protected.Post("/admin/roles", adminHandler.CreateRole)
		protected.Patch("/admin/roles", adminHandler.UpdateRole)
		protected.Delete("/admin/roles", adminHandler.DeleteRole)
		protected.Get("/admin/permissions", adminHandler.ListPermissions)
		protected.Get("/admin/role-permissions", adminHandler.ListRolePermissions)
		protected.Put("/admin/role-permissions", adminHandler.ReplaceRolePermissions)
		protected.Get("/admin/personal-data/operator-profile", pdAdminHandler.GetOperatorProfile)
		protected.Put("/admin/personal-data/operator-profile", pdAdminHandler.PutOperatorProfile)
		protected.Get("/admin/personal-data/compliance-status", pdAdminHandler.ComplianceStatus)
		protected.Get("/admin/personal-data/purposes", pdAdminHandler.ListPurposes)
		protected.Post("/admin/personal-data/purposes", pdAdminHandler.CreatePurpose)
		protected.Patch("/admin/personal-data/purposes", pdAdminHandler.PatchPurpose)
		protected.Get("/admin/personal-data/subject-requests", pdAdminHandler.ListSubjectRequests)
		protected.Post("/admin/personal-data/subject-requests/resolve", pdAdminHandler.ResolveSubjectRequest)
		protected.Post("/admin/registration-invites", adminHandler.CreateRegistrationInvite)

		router.Post("/internal/v1/tenant-events", internalGuard, internalHandler.TenantEvents)
	}

	register(app.Group("/rbac"))
	register(app)
	app.Use(func(c *fiber.Ctx) error { return httpx.Fail(c, fiber.StatusNotFound, "not_found") })
	return app
}

func registerHealthOnly(app *fiber.App, health *handler.Health) {
	for _, prefix := range []string{"", "/rbac"} {
		group := app.Group(prefix)
		group.Get("/health", health.Handle)
	}
	app.Use(func(c *fiber.Ctx) error {
		return httpx.JSON(c, fiber.StatusServiceUnavailable, fiber.Map{"ok": false, "error": "database unavailable"})
	})
}

func Listen(cfg config.Config, app *fiber.App) error {
	log.Printf("maniforge-rbac listening on %s (env=%s)", cfg.RBACAddr, cfg.AppEnv)
	return app.Listen(cfg.RBACAddr)
}
