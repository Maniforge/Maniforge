// Файл: write.go
// Назначение: запись в TL — CreateTenant, CreateSubtenant, AssignLicense (регистрация).
// См. также: service/registration.go, repository.go
package repository

import (
	"database/sql"
	"encoding/json"
	"strings"
	"time"

	"maniforge/internal/platform/code"
)

type WriteResult struct {
	OK     bool
	Status int
	Error  string
}

func (r *Repository) CreateTenant(tenantCode, name, actor string, metadata map[string]any) WriteResult {
	tenantCode = code.Normalize(tenantCode)
	if tenantCode == "" || strings.TrimSpace(name) == "" {
		return WriteResult{OK: false, Status: 422, Error: "code и name обязательны"}
	}

	meta, _ := json.Marshal(metadata)
	_, err := r.db.Exec(
		`INSERT INTO maniforge_tl_tenants (code, name, metadata_json) VALUES ($1, $2, $3)`,
		tenantCode, name, meta)
	if err != nil {
		if isUniqueViolation(err) {
			return WriteResult{OK: false, Status: 409, Error: "tenant уже существует"}
		}
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	_ = r.writeAudit("tenant.created", actor, tenantCode, "", map[string]any{"name": name})
	_ = r.enqueueEvent("tenant.created", tenantCode, "", map[string]any{"name": name})
	return WriteResult{OK: true, Status: 201}
}

func (r *Repository) CreateSubtenant(tenantCode, subtenantCode, name, actor string, metadata map[string]any) WriteResult {
	tenantCode = code.Normalize(tenantCode)
	subtenantCode = code.Normalize(subtenantCode)
	if subtenantCode == "" || strings.TrimSpace(name) == "" {
		return WriteResult{OK: false, Status: 422, Error: "code и name обязательны"}
	}

	var tenantID int64
	err := r.db.QueryRow(
		`SELECT id FROM maniforge_tl_tenants WHERE code = $1 LIMIT 1`, tenantCode).Scan(&tenantID)
	if err == sql.ErrNoRows {
		return WriteResult{OK: false, Status: 404, Error: "tenant не найден"}
	}
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	if sub, _ := r.findSubtenant(tenantCode, subtenantCode); sub != nil {
		return WriteResult{OK: false, Status: 409, Error: "subtenant уже существует"}
	}

	meta, _ := json.Marshal(metadata)
	_, err = r.db.Exec(
		`INSERT INTO maniforge_tl_subtenants (tenant_id, tenant_code, code, name, metadata_json)
		 VALUES ($1, $2, $3, $4, $5)`,
		tenantID, tenantCode, subtenantCode, name, meta)
	if err != nil {
		if isUniqueViolation(err) {
			return WriteResult{OK: false, Status: 409, Error: "subtenant уже существует"}
		}
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	_ = r.writeAudit("subtenant.created", actor, tenantCode, subtenantCode, map[string]any{"name": name})
	_ = r.enqueueEvent("subtenant.created", tenantCode, subtenantCode, map[string]any{"name": name})
	return WriteResult{OK: true, Status: 201}
}

func (r *Repository) AssignLicense(tenantCode, planCode, actor string, expiresAt *time.Time, seatsMax *int) WriteResult {
	tenantCode = code.Normalize(tenantCode)
	planCode = code.Normalize(planCode)

	var tenantID int64
	err := r.db.QueryRow(`SELECT id FROM maniforge_tl_tenants WHERE code = $1`, tenantCode).Scan(&tenantID)
	if err == sql.ErrNoRows {
		return WriteResult{OK: false, Status: 404, Error: "tenant или plan не найден"}
	}
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	var planExists bool
	err = r.db.QueryRow(`SELECT EXISTS(SELECT 1 FROM maniforge_tl_license_plans WHERE code = $1)`, planCode).Scan(&planExists)
	if err != nil || !planExists {
		return WriteResult{OK: false, Status: 404, Error: "tenant или plan не найден"}
	}

	if seatsMax == nil || *seatsMax <= 0 {
		var limitsJSON []byte
		_ = r.db.QueryRow(`SELECT limits_json FROM maniforge_tl_license_plans WHERE code = $1`, planCode).Scan(&limitsJSON)
		limits := decodeJSONMap(limitsJSON)
		if v, ok := limits["max_users"].(float64); ok && int(v) > 0 {
			n := int(v)
			seatsMax = &n
		}
	}

	tx, err := r.db.Begin()
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	defer tx.Rollback()

	// Сериализация assign per tenant: без блокировки параллельные TX создают несколько active license.
	var tenantLockID int64
	err = tx.QueryRow(
		`SELECT id FROM maniforge_tl_tenants WHERE code = $1 FOR UPDATE`, tenantCode).
		Scan(&tenantLockID)
	if err == sql.ErrNoRows {
		return WriteResult{OK: false, Status: 404, Error: "tenant или plan не найден"}
	}
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	_, err = tx.Exec(
		`UPDATE maniforge_tl_tenant_licenses SET status = 'revoked', updated_at = NOW()
		 WHERE tenant_code = $1 AND status = 'active'`, tenantCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	_, err = tx.Exec(
		`INSERT INTO maniforge_tl_tenant_licenses
		 (tenant_id, tenant_code, plan_code, status, expires_at, seats_max, assigned_by)
		 VALUES ($1, $2, $3, 'active', $4, $5, $6)`,
		tenantID, tenantCode, planCode, expiresAt, seatsMax, actor)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	if err := tx.Commit(); err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	payload := map[string]any{"plan_code": planCode, "seats_max": seatsMax}
	if expiresAt != nil {
		payload["expires_at"] = expiresAt.UTC().Format("2006-01-02 15:04:05")
	}
	_ = r.writeAudit("license.assigned", actor, tenantCode, "", payload)
	_ = r.enqueueEvent("license.changed", tenantCode, "", payload)
	return WriteResult{OK: true, Status: 200}
}

func (r *Repository) MergeTenantMetadata(tenantCode string, patch map[string]any, actor string) WriteResult {
	tenantCode = code.Normalize(tenantCode)
	tenant, err := r.findTenant(tenantCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	if tenant == nil {
		return WriteResult{OK: false, Status: 404, Error: "tenant не найден"}
	}

	var currentRaw []byte
	_ = r.db.QueryRow(`SELECT COALESCE(metadata_json, '{}'::jsonb) FROM maniforge_tl_tenants WHERE code = $1`, tenantCode).Scan(&currentRaw)
	current := decodeJSONMap(currentRaw)
	merged := map[string]any{}
	for k, v := range current {
		merged[k] = v
	}
	for k, v := range patch {
		merged[k] = v
	}
	meta, _ := json.Marshal(merged)
	_, err = r.db.Exec(
		`UPDATE maniforge_tl_tenants SET metadata_json = $2, updated_at = NOW() WHERE code = $1`,
		tenantCode, meta)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	_ = r.writeAudit("tenant.metadata.updated", actor, tenantCode, "", map[string]any{"patch": patch})
	return WriteResult{OK: true, Status: 200}
}

func (r *Repository) UpdateTenant(tenantCode, name, status, actor string) WriteResult {
	tenantCode = code.Normalize(tenantCode)
	status = code.Normalize(status)
	if tenantCode == "" || strings.TrimSpace(name) == "" || (status != "active" && status != "suspended" && status != "archived") {
		return WriteResult{OK: false, Status: 422, Error: "name и корректный status обязательны"}
	}
	tenant, err := r.findTenant(tenantCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	if tenant == nil {
		return WriteResult{OK: false, Status: 404, Error: "tenant не найден"}
	}

	var suspendedAt any
	if status == "suspended" {
		suspendedAt = time.Now().UTC()
	}
	_, err = r.db.Exec(
		`UPDATE maniforge_tl_tenants
		 SET name = $2, status = $3, suspended_at = $4, updated_at = NOW()
		 WHERE code = $1`,
		tenantCode, strings.TrimSpace(name), status, suspendedAt)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	eventType := "tenant.updated"
	if status == "suspended" {
		eventType = "tenant.suspended"
	} else if status == "active" {
		eventType = "tenant.activated"
	}
	payload := map[string]any{"name": strings.TrimSpace(name), "status": status}
	_ = r.writeAudit(eventType, actor, tenantCode, "", payload)
	_ = r.enqueueEvent(eventType, tenantCode, "", payload)
	return WriteResult{OK: true, Status: 200}
}

func (r *Repository) UpdateSubtenant(tenantCode, subtenantCode, name, status, actor string) WriteResult {
	tenantCode = code.Normalize(tenantCode)
	subtenantCode = code.Normalize(subtenantCode)
	status = code.Normalize(status)
	if subtenantCode == "" || strings.TrimSpace(name) == "" || (status != "active" && status != "suspended" && status != "archived") {
		return WriteResult{OK: false, Status: 422, Error: "name и корректный status обязательны"}
	}
	sub, err := r.findSubtenant(tenantCode, subtenantCode)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}
	if sub == nil {
		return WriteResult{OK: false, Status: 404, Error: "subtenant не найден"}
	}

	_, err = r.db.Exec(
		`UPDATE maniforge_tl_subtenants
		 SET name = $3, status = $4, updated_at = NOW()
		 WHERE tenant_code = $1 AND code = $2`,
		tenantCode, subtenantCode, strings.TrimSpace(name), status)
	if err != nil {
		return WriteResult{OK: false, Status: 500, Error: err.Error()}
	}

	eventType := "subtenant.updated"
	if status == "suspended" {
		eventType = "subtenant.suspended"
	} else if status == "active" {
		eventType = "subtenant.activated"
	}
	payload := map[string]any{"name": strings.TrimSpace(name), "status": status}
	_ = r.writeAudit(eventType, actor, tenantCode, subtenantCode, payload)
	_ = r.enqueueEvent(eventType, tenantCode, subtenantCode, payload)
	return WriteResult{OK: true, Status: 200}
}

func (r *Repository) writeAudit(eventType, actor, tenantCode, subtenantCode string, payload map[string]any) error {
	meta, _ := json.Marshal(payload)
	_, err := r.db.Exec(
		`INSERT INTO maniforge_tl_audit_log (event_type, actor, tenant_code, subtenant_code, payload_json)
		 VALUES ($1, $2, $3, $4, $5)`,
		eventType, actor, tenantCode, nullIfEmpty(subtenantCode), meta)
	return err
}

func (r *Repository) enqueueEvent(eventType, tenantCode, subtenantCode string, payload map[string]any) error {
	meta, _ := json.Marshal(payload)
	_, err := r.db.Exec(
		`INSERT INTO maniforge_tl_events (event_type, tenant_code, subtenant_code, payload_json)
		 VALUES ($1, $2, $3, $4)`,
		eventType, tenantCode, nullIfEmpty(subtenantCode), meta)
	return err
}

func nullIfEmpty(v string) any {
	if v == "" {
		return nil
	}
	return v
}

func isUniqueViolation(err error) bool {
	return err != nil && strings.Contains(err.Error(), "duplicate key")
}

func LicenseExpiresInDays(days int) *time.Time {
	t := time.Now().UTC().Add(time.Duration(days) * 24 * time.Hour)
	return &t
}

func (r *Repository) TenantExists(tenantCode string) bool {
	row, _ := r.findTenant(code.Normalize(tenantCode))
	return row != nil
}

func (r *Repository) SubtenantExists(tenantCode, subtenantCode string) bool {
	row, _ := r.findSubtenant(code.Normalize(tenantCode), code.Normalize(subtenantCode))
	return row != nil
}
