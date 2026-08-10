// Файл: delegation.go
// Назначение: delegated contexts по maniforge_tl_tenant_grants (ось A).
// См. также: service/context.go, app/Maniforge/Rbac/Security/ContextService.php
package repository

import (
	"database/sql"
	"strings"
)

type DelegatedContext struct {
	TenantID          string
	SubtenantID       string
	GrantLevel        string
	PrincipalTenantID string
}

type DelegationRepository struct {
	db *sql.DB
}

func NewDelegationRepository(db *sql.DB) *DelegationRepository {
	return &DelegationRepository{db: db}
}

func (r *DelegationRepository) PrincipalTenantsForPhone(phone string) ([]string, error) {
	rows, err := r.db.Query(
		`SELECT DISTINCT u.tenant_id
		 FROM maniforge_users u
		 WHERE u.phone = $1 AND u.status = 'active'
		 ORDER BY u.tenant_id ASC`, phone)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var tenants []string
	for rows.Next() {
		var code string
		if err := rows.Scan(&code); err != nil {
			return nil, err
		}
		code = strings.ToLower(strings.TrimSpace(code))
		if code != "" {
			tenants = append(tenants, code)
		}
	}
	return tenants, rows.Err()
}

func (r *DelegationRepository) DelegatedContexts(principalTenant, phone string) ([]DelegatedContext, error) {
	principalTenant = strings.ToLower(strings.TrimSpace(principalTenant))
	if principalTenant == "" {
		return nil, nil
	}
	rows, err := r.db.Query(
		`SELECT g.managed_tenant_code, g.grant_level, s.code AS subtenant_code
		 FROM maniforge_tl_tenant_grants g
		 INNER JOIN maniforge_tl_subtenants s
		    ON s.tenant_code = g.managed_tenant_code AND s.status = 'active'
		 WHERE g.principal_tenant_code = $1
		   AND g.status = 'active'
		   AND g.principal_tenant_code <> g.managed_tenant_code
		   AND EXISTS (
		       SELECT 1 FROM maniforge_users u
		       WHERE u.phone = $2
		         AND u.tenant_id = g.principal_tenant_code
		         AND u.status = 'active'
		   )
		 ORDER BY g.managed_tenant_code ASC, s.code ASC`,
		principalTenant, phone)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var contexts []DelegatedContext
	for rows.Next() {
		var managed, level, sub string
		if err := rows.Scan(&managed, &level, &sub); err != nil {
			return nil, err
		}
		contexts = append(contexts, DelegatedContext{
			TenantID:          managed,
			SubtenantID:       sub,
			GrantLevel:        level,
			PrincipalTenantID: principalTenant,
		})
	}
	return contexts, rows.Err()
}

func (r *DelegationRepository) DelegatedContextsForPhone(phone string) ([]DelegatedContext, error) {
	principals, err := r.PrincipalTenantsForPhone(phone)
	if err != nil {
		return nil, err
	}
	var all []DelegatedContext
	for _, principal := range principals {
		items, err := r.DelegatedContexts(principal, phone)
		if err != nil {
			return nil, err
		}
		all = append(all, items...)
	}
	return all, nil
}
