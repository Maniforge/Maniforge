// Файл: main.go (cmd/agency-demo-seed)
// Назначение: идемпотентный seed agency-demo / client-demo для delegation journey (PostgreSQL).
// См. также: maniforge/rbac/tools/demo_seed.php, agency_delegation_http_journey.php
package main

import (
	"database/sql"
	"fmt"
	"log"
	"os"
	"strings"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/db"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/security"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}
	sqlDB, err := db.Open(cfg)
	if err != nil {
		log.Fatalf("db: %v", err)
	}
	defer sqlDB.Close()

	adminPassword := env("MANIFORGE_DEMO_ADMIN_PASSWORD", "DemoAdmin!12345")
	userPassword := env("MANIFORGE_DEMO_USER_PASSWORD", "DemoUser!12345")
	actor := "agency-demo-seed"

	agencyCode := "agency-demo"
	clientCode := "client-demo"
	subCode := "main"

	for _, item := range []struct {
		code, name, plan string
	}{
		{agencyCode, "Agency Demo", "operator"},
		{clientCode, "Client Demo", "business"},
	} {
		if err := ensurePlan(sqlDB, item.plan); err != nil {
			log.Fatalf("plan %s: %v", item.plan, err)
		}
		if err := ensureTenant(sqlDB, item.code, item.name); err != nil {
			log.Fatalf("tenant %s: %v", item.code, err)
		}
		if err := ensureSubtenant(sqlDB, item.code, subCode, item.name+" Workspace"); err != nil {
			log.Fatalf("subtenant %s/%s: %v", item.code, subCode, err)
		}
		if err := ensureLicense(sqlDB, item.code, item.plan, actor); err != nil {
			log.Fatalf("license %s: %v", item.code, err)
		}
		if err := ensureProject(sqlDB, item.code, subCode); err != nil {
			log.Fatalf("project %s/%s: %v", item.code, subCode, err)
		}
	}

	if err := ensureGrant(sqlDB, agencyCode, clientCode, "operator", actor); err != nil {
		log.Fatalf("grant: %v", err)
	}

	users := repository.NewUserRepository(sqlDB, cfg)
	roles := repository.NewRoleRepository(sqlDB)
	hash, err := security.HashPassword(adminPassword)
	if err != nil {
		log.Fatalf("hash admin password: %v", err)
	}
	agencyAdmin, err := ensureUser(users, roles, agencyCode, subCode, "agency-admin",
		"agency-admin@example.test", "+79000000003", hash, true)
	if err != nil {
		log.Fatalf("agency-admin: %v", err)
	}
	userHash, err := security.HashPassword(userPassword)
	if err != nil {
		log.Fatalf("hash user password: %v", err)
	}
	clientAdmin, err := ensureUser(users, roles, clientCode, subCode, "client-admin",
		"client-admin@example.test", "+79000000004", userHash, false)
	if err != nil {
		log.Fatalf("client-admin: %v", err)
	}

	fmt.Printf("agency demo seed ok: principal=%s managed=%s agency_admin_id=%d client_admin_id=%d\n",
		agencyCode, clientCode, agencyAdmin.ID, clientAdmin.ID)
}

func env(key, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return fallback
}

func ensurePlan(db *sql.DB, code string) error {
	_, err := db.Exec(
		`INSERT INTO maniforge_tl_license_plans (code, name, features_json, limits_json)
		 VALUES ($1, $2, '{"rbac": true, "admin_api": true}'::jsonb, '{"max_users": 100, "max_sessions": 500}'::jsonb)
		 ON CONFLICT (code) DO NOTHING`,
		code, code)
	return err
}

func ensureTenant(db *sql.DB, code, name string) error {
	_, err := db.Exec(
		`INSERT INTO maniforge_tl_tenants (code, name, status)
		 VALUES ($1, $2, 'active')
		 ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, status = 'active', updated_at = NOW()`,
		code, name)
	return err
}

func ensureSubtenant(db *sql.DB, tenantCode, subCode, name string) error {
	_, err := db.Exec(
		`INSERT INTO maniforge_tl_subtenants (tenant_id, tenant_code, code, name, status)
		 SELECT id, code, $2, $3, 'active' FROM maniforge_tl_tenants WHERE code = $1
		 ON CONFLICT (tenant_code, code) DO UPDATE SET name = EXCLUDED.name, status = 'active', updated_at = NOW()`,
		tenantCode, subCode, name)
	return err
}

func ensureLicense(db *sql.DB, tenantCode, planCode, actor string) error {
	expires := time.Now().UTC().Add(90 * 24 * time.Hour)
	_, err := db.Exec(
		`INSERT INTO maniforge_tl_tenant_licenses (tenant_id, tenant_code, plan_code, status, expires_at, seats_max, assigned_by)
		 SELECT t.id, t.code, $2, 'active', $3, 100, $4
		 FROM maniforge_tl_tenants t
		 WHERE t.code = $1
		   AND NOT EXISTS (
		       SELECT 1 FROM maniforge_tl_tenant_licenses l
		       WHERE l.tenant_code = t.code AND l.status = 'active'
		   )`,
		tenantCode, planCode, expires, actor)
	return err
}

func ensureProject(db *sql.DB, tenantCode, subCode string) error {
	_, err := db.Exec(
		`INSERT INTO maniforge_projects (tenant_id, subtenant_id, code, name, status, is_default)
		 VALUES ($1, $2, 'main', 'Main project', 'active', TRUE)
		 ON CONFLICT (tenant_id, subtenant_id, code) DO UPDATE SET status = 'active', is_default = TRUE`,
		tenantCode, subCode)
	return err
}

func ensureGrant(db *sql.DB, principal, managed, level, actor string) error {
	_, err := db.Exec(
		`INSERT INTO maniforge_tl_tenant_grants (
			principal_tenant_code, managed_tenant_code, grant_level, status, metadata_json, created_by
		) VALUES ($1, $2, $3, 'active', '{"source":"agency-demo-seed"}'::jsonb, $4)
		ON CONFLICT (principal_tenant_code, managed_tenant_code)
		DO UPDATE SET grant_level = EXCLUDED.grant_level, status = 'active', revoked_at = NULL`,
		principal, managed, level, actor)
	return err
}

func ensureUser(
	users *repository.UserRepository,
	roles *repository.RoleRepository,
	tenantCode, subCode, login, email, phone, passwordHash string,
	mfaRequired bool,
) (*repository.User, error) {
	existing, err := users.FindByLogin(tenantCode, subCode, login)
	if err != nil {
		return nil, err
	}
	if existing != nil {
		roles.AssignRoleByCode(existing.ID, tenantCode, subCode, "tenant_admin", existing.ID)
		return existing, nil
	}
	created, err := users.CreateUser(repository.CreateUserInput{
		TenantID: tenantCode, SubtenantID: subCode, Login: login, Email: email, Phone: phone,
		PasswordHash: passwordHash, MFARequired: mfaRequired, Status: "active",
	})
	if err != nil {
		return nil, err
	}
	roles.AssignRoleByCode(created.ID, tenantCode, subCode, "tenant_admin", created.ID)
	return created, nil
}
