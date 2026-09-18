// Файл: extras.go
// Назначение: leftover PHP TL — plans write, licenses, grants, quota, ops, audit.
// См. также: write.go, handler/handler.go
package repository

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"maniforge/internal/platform/code"
)

func (r *Repository) FindPlan(planCode string) (map[string]any, error) {
	planCode = code.Normalize(planCode)
	rows, err := r.db.Query(
		`SELECT code, name, status, features_json, limits_json, created_at, updated_at
		 FROM maniforge_tl_license_plans WHERE code = $1 LIMIT 1`, planCode)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items, err := scanRows(rows)
	if err != nil || len(items) == 0 {
		return nil, err
	}
	return items[0], nil
}

func (r *Repository) UpsertPlan(planCode, name, status string, features, limits map[string]any, actor string) WriteResult {
	planCode = code.Normalize(planCode)
	name = strings.TrimSpace(name)
	status = strings.TrimSpace(strings.ToLower(status))
	if planCode == "" || name == "" || !validPlanStatus(status) {
		return WriteResult{OK: false, Status: 422, Error: "Неверные code, name или status"}
	}
	if features == nil {
		features = map[string]any{}
	}
	if limits == nil {
		limits = map[string]any{}
	}
	existing, err := r.FindPlan(planCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	featJSON, _ := json.Marshal(features)
	limJSON, _ := json.Marshal(limits)
	_, err = r.db.Exec(
		`INSERT INTO maniforge_tl_license_plans (code, name, status, features_json, limits_json)
		 VALUES ($1, $2, $3, $4, $5)
		 ON CONFLICT (code) DO UPDATE SET
			name = EXCLUDED.name,
			status = EXCLUDED.status,
			features_json = EXCLUDED.features_json,
			limits_json = EXCLUDED.limits_json,
			updated_at = NOW()`,
		planCode, name, status, featJSON, limJSON)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	plan, _ := r.FindPlan(planCode)
	eventType := "plan.created"
	httpStatus := 201
	if existing != nil {
		eventType = "plan.updated"
		httpStatus = 200
	}
	_ = r.writeAudit(eventType, actor, "_platform", "", map[string]any{"plan_code": planCode, "name": name, "status": status})
	if existing == nil {
		_ = r.enqueueEvent(eventType, "_platform", "", map[string]any{"code": planCode, "name": name, "status": status})
	}
	return WriteResult{OK: true, Status: httpStatus, Extra: map[string]any{"plan": plan}}
}

func validPlanStatus(status string) bool {
	return status == "active" || status == "disabled" || status == "deprecated"
}

func (r *Repository) ListLicenses(limit int) ([]map[string]any, error) {
	if limit <= 0 {
		limit = 100
	}
	rows, err := r.db.Query(
		`SELECT id, tenant_code, plan_code, status, starts_at, expires_at, seats_max, assigned_by, created_at, updated_at
		 FROM maniforge_tl_tenant_licenses
		 ORDER BY id DESC
		 LIMIT $1`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanRows(rows)
}

func (r *Repository) FindLicense(id int64) (map[string]any, error) {
	rows, err := r.db.Query(
		`SELECT id, tenant_code, plan_code, status, starts_at, expires_at, seats_max, assigned_by, created_at, updated_at
		 FROM maniforge_tl_tenant_licenses WHERE id = $1 LIMIT 1`, id)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items, err := scanRows(rows)
	if err != nil || len(items) == 0 {
		return nil, err
	}
	return items[0], nil
}

func (r *Repository) UpdateLicense(id int64, status string, expiresAt *time.Time, seatsMax *int, actor string) WriteResult {
	license, err := r.FindLicense(id)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	if license == nil {
		return WriteResult{OK: false, Status: 404, Error: "license не найдена"}
	}
	status = strings.TrimSpace(strings.ToLower(status))
	if status == "" {
		status = strings.TrimSpace(stringFromAny(license["status"]))
	}
	if status != "active" && status != "suspended" && status != "revoked" && status != "expired" {
		return WriteResult{OK: false, Status: 422, Error: "Неверный status лицензии"}
	}
	_, err = r.db.Exec(
		`UPDATE maniforge_tl_tenant_licenses
		 SET status = $2, expires_at = $3, seats_max = $4, updated_at = NOW()
		 WHERE id = $1`,
		id, status, expiresAt, seatsMax)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	updated, _ := r.FindLicense(id)
	tenantCode := stringFromAny(license["tenant_code"])
	prev := stringFromAny(license["status"])
	payload := map[string]any{"license_id": id, "status": status, "previous_status": prev, "seats_max": seatsMax}
	if expiresAt != nil {
		payload["expires_at"] = expiresAt.UTC().Format("2006-01-02 15:04:05")
	}
	eventType := "license.changed"
	if status != prev {
		eventType = "license." + status
	}
	_ = r.writeAudit("license.updated", actor, tenantCode, "", payload)
	_ = r.enqueueEvent(eventType, tenantCode, "", payload)
	return WriteResult{OK: true, Status: 200, Extra: map[string]any{"license": updated}}
}

func (r *Repository) RevokeLicense(tenantCode, actor, reason string) WriteResult {
	tenantCode = code.Normalize(tenantCode)
	if tenantCode == "" {
		return WriteResult{OK: false, Status: 422, Error: "tenant_code обязателен"}
	}
	if strings.TrimSpace(reason) == "" {
		reason = "manual_revoke"
	}
	res, err := r.db.Exec(
		`UPDATE maniforge_tl_tenant_licenses
		 SET status = 'revoked', updated_at = NOW()
		 WHERE tenant_code = $1 AND status = 'active'`, tenantCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return WriteResult{OK: false, Status: 404, Error: "active license не найдена"}
	}
	payload := map[string]any{"reason": reason}
	_ = r.writeAudit("license.revoked", actor, tenantCode, "", payload)
	_ = r.enqueueEvent("license.revoked", tenantCode, "", payload)
	return WriteResult{OK: true, Status: 200, Extra: map[string]any{"revoked": true}}
}

func (r *Repository) ListQuota(tenantCode, metric string) ([]map[string]any, error) {
	tenantCode = code.Normalize(tenantCode)
	var (
		rows *sql.Rows
		err  error
	)
	if metric != "" {
		rows, err = r.db.Query(
			`SELECT tenant_code, subtenant_code, metric, period_key, used, limit_snapshot, updated_at
			 FROM maniforge_tl_quota_usage
			 WHERE tenant_code = $1 AND metric = $2
			 ORDER BY period_key DESC, metric ASC`, tenantCode, metric)
	} else {
		rows, err = r.db.Query(
			`SELECT tenant_code, subtenant_code, metric, period_key, used, limit_snapshot, updated_at
			 FROM maniforge_tl_quota_usage
			 WHERE tenant_code = $1
			 ORDER BY period_key DESC, metric ASC`, tenantCode)
	}
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items, err := scanRows(rows)
	if items == nil {
		items = []map[string]any{}
	}
	return items, err
}

func (r *Repository) PlatformOpsSummary() (map[string]any, error) {
	count := func(q string) int {
		var n int
		_ = r.db.QueryRow(q).Scan(&n)
		return n
	}
	var oldest sql.NullTime
	_ = r.db.QueryRow(`SELECT MIN(created_at) FROM maniforge_tl_events WHERE delivered_at IS NULL`).Scan(&oldest)
	out := map[string]any{
		"tenants_total":    count(`SELECT COUNT(*) FROM maniforge_tl_tenants`),
		"tenants_active":   count(`SELECT COUNT(*) FROM maniforge_tl_tenants WHERE status = 'active'`),
		"subtenants_total": count(`SELECT COUNT(*) FROM maniforge_tl_subtenants`),
		"licenses_active":  count(`SELECT COUNT(*) FROM maniforge_tl_tenant_licenses WHERE status = 'active'`),
		"events_pending":   count(`SELECT COUNT(*) FROM maniforge_tl_events WHERE delivered_at IS NULL`),
		"grants_active":    count(`SELECT COUNT(*) FROM maniforge_tl_tenant_grants WHERE status = 'active'`),
		"checked_at":       time.Now().UTC().Format("2006-01-02 15:04:05"),
	}
	if oldest.Valid {
		out["events_pending_oldest_created_at"] = oldest.Time.UTC().Format("2006-01-02 15:04:05")
	} else {
		out["events_pending_oldest_created_at"] = nil
	}
	return out, nil
}

func (r *Repository) ListAudit(tenantCode string, limit int) ([]map[string]any, error) {
	if limit <= 0 {
		limit = 100
	}
	if limit > 500 {
		limit = 500
	}
	tenantCode = code.Normalize(tenantCode)
	var (
		rows *sql.Rows
		err  error
	)
	if tenantCode == "" {
		rows, err = r.db.Query(
			`SELECT id, event_type, actor, tenant_code, subtenant_code, payload_json, created_at
			 FROM maniforge_tl_audit_log ORDER BY id DESC LIMIT $1`, limit)
	} else {
		rows, err = r.db.Query(
			`SELECT id, event_type, actor, tenant_code, subtenant_code, payload_json, created_at
			 FROM maniforge_tl_audit_log WHERE tenant_code = $1 ORDER BY id DESC LIMIT $2`, tenantCode, limit)
	}
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanRows(rows)
}

func (r *Repository) ListManagedTenants(agencyCode string, activeOnly bool) ([]map[string]any, error) {
	agencyCode = code.Normalize(agencyCode)
	q := `SELECT id, principal_tenant_code, managed_tenant_code, grant_level, status,
	             metadata_json, created_by, created_at, revoked_at
	      FROM maniforge_tl_tenant_grants
	      WHERE principal_tenant_code = $1`
	if activeOnly {
		q += ` AND status = 'active'`
	}
	q += ` ORDER BY managed_tenant_code ASC`
	rows, err := r.db.Query(q, agencyCode)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items, err := scanRows(rows)
	if items == nil {
		items = []map[string]any{}
	}
	return items, err
}

func (r *Repository) CreateManagedTenantGrant(agencyCode, managedCode, grantLevel, actor string, metadata map[string]any) WriteResult {
	agencyCode = code.Normalize(agencyCode)
	managedCode = code.Normalize(managedCode)
	grantLevel = strings.ToLower(strings.TrimSpace(grantLevel))
	if grantLevel == "" {
		grantLevel = "operator"
	}
	if agencyCode == "" || managedCode == "" {
		return WriteResult{OK: false, Status: 422, Error: "principal и managed tenant обязательны"}
	}
	if agencyCode == managedCode {
		return WriteResult{OK: false, Status: 422, Error: "Нельзя выдать grant на собственный tenant"}
	}
	if grantLevel != "operator" && grantLevel != "admin" && grantLevel != "read_only" {
		return WriteResult{OK: false, Status: 422, Error: "grant_level: operator | admin | read_only"}
	}
	principal, err := r.findTenant(agencyCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	if principal == nil {
		return WriteResult{OK: false, Status: 404, Error: "agency tenant не найден"}
	}
	managed, err := r.findTenant(managedCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	if managed == nil {
		return WriteResult{OK: false, Status: 404, Error: "managed tenant не найден"}
	}

	existing := r.findGrant(agencyCode, managedCode)
	needsSlot := existing == nil || stringFromAny(existing["status"]) != "active"
	if needsSlot {
		if limit := r.managedTenantLimit(agencyCode); limit != nil {
			used := r.countActiveManagedGrants(agencyCode)
			if used >= *limit {
				return WriteResult{
					OK: false, Status: 403, Error: "Превышен лимит managed tenants по тарифу principal",
					Extra: map[string]any{"limit": *limit, "used": used},
				}
			}
		}
	}
	if metadata == nil {
		metadata = map[string]any{}
	}
	metaJSON, _ := json.Marshal(metadata)
	_, err = r.db.Exec(
		`INSERT INTO maniforge_tl_tenant_grants (
			principal_tenant_code, managed_tenant_code, grant_level, status, metadata_json, created_by
		) VALUES ($1, $2, $3, 'active', $4, $5)
		 ON CONFLICT (principal_tenant_code, managed_tenant_code) DO UPDATE SET
			grant_level = EXCLUDED.grant_level,
			status = 'active',
			metadata_json = EXCLUDED.metadata_json,
			created_by = EXCLUDED.created_by,
			revoked_at = NULL`,
		agencyCode, managedCode, grantLevel, metaJSON, actor)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	grant := r.findGrant(agencyCode, managedCode)
	payload := map[string]any{"managed_tenant_code": managedCode, "grant_level": grantLevel, "metadata": metadata}
	_ = r.writeAudit("agency_grant.created", actor, agencyCode, "", payload)
	_ = r.enqueueEvent("agency_grant.created", agencyCode, "", payload)
	return WriteResult{OK: true, Status: 201, Extra: map[string]any{"grant": grant}}
}

func (r *Repository) RevokeManagedTenantGrant(agencyCode, managedCode, actor string) WriteResult {
	agencyCode = code.Normalize(agencyCode)
	managedCode = code.Normalize(managedCode)
	grant := r.findGrant(agencyCode, managedCode)
	if grant == nil || stringFromAny(grant["status"]) != "active" {
		return WriteResult{OK: false, Status: 404, Error: "active grant не найден"}
	}
	res, err := r.db.Exec(
		`UPDATE maniforge_tl_tenant_grants
		 SET status = 'revoked', revoked_at = NOW()
		 WHERE principal_tenant_code = $1 AND managed_tenant_code = $2 AND status = 'active'`,
		agencyCode, managedCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return WriteResult{OK: false, Status: 404, Error: "active grant не найден"}
	}
	payload := map[string]any{"managed_tenant_code": managedCode}
	_ = r.writeAudit("agency_grant.revoked", actor, agencyCode, "", payload)
	_ = r.enqueueEvent("agency_grant.revoked", agencyCode, "", payload)
	return WriteResult{OK: true, Status: 200, Extra: map[string]any{"revoked": true}}
}

func (r *Repository) findGrant(agencyCode, managedCode string) map[string]any {
	rows, err := r.db.Query(
		`SELECT id, principal_tenant_code, managed_tenant_code, grant_level, status,
		        metadata_json, created_by, created_at, revoked_at
		 FROM maniforge_tl_tenant_grants
		 WHERE principal_tenant_code = $1 AND managed_tenant_code = $2
		 LIMIT 1`, agencyCode, managedCode)
	if err != nil {
		return nil
	}
	defer rows.Close()
	items, _ := scanRows(rows)
	if len(items) == 0 {
		return nil
	}
	return items[0]
}

func (r *Repository) countActiveManagedGrants(principalCode string) int {
	var n int
	_ = r.db.QueryRow(
		`SELECT COUNT(*) FROM maniforge_tl_tenant_grants
		 WHERE principal_tenant_code = $1 AND status = 'active'`, principalCode).Scan(&n)
	return n
}

func (r *Repository) managedTenantLimit(principalCode string) *int {
	var limitsJSON []byte
	err := r.db.QueryRow(
		`SELECT p.limits_json
		 FROM maniforge_tl_tenant_licenses l
		 INNER JOIN maniforge_tl_license_plans p ON p.code = l.plan_code
		 WHERE l.tenant_code = $1 AND l.status = 'active'
		 ORDER BY l.id DESC LIMIT 1`, principalCode).Scan(&limitsJSON)
	if err != nil {
		return nil
	}
	limits := decodeJSONMap(limitsJSON)
	v, ok := limits["max_tenants"]
	if !ok {
		return nil
	}
	n := 0
	switch t := v.(type) {
	case float64:
		n = int(t)
	case int:
		n = t
	case int64:
		n = int(t)
	default:
		return nil
	}
	if n < 0 {
		n = 0
	}
	return &n
}

func stringFromAny(v any) string {
	if v == nil {
		return ""
	}
	if s, ok := v.(string); ok {
		return s
	}
	return strings.TrimSpace(fmt.Sprint(v))
}
