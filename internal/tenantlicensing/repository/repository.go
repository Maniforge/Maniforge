// Package repository — PostgreSQL для Tenant Licensing (чтение и запись).
//
// Файл: repository.go
// Назначение: AccessStateForProject, entitlements, events, find tenant/project.
// Таблицы: maniforge_tl_*, maniforge_projects (project_active).
// См. также: write.go, licensingclient/client.go
package repository

import (
	"database/sql"
	"encoding/json"
	"time"

	"maniforge/internal/platform/code"
)

type Repository struct {
	db *sql.DB
}

func New(db *sql.DB) *Repository {
	return &Repository{db: db}
}

type LicenseInfo struct {
	PlanCode  string     `json:"plan_code"`
	Status    string     `json:"status"`
	ExpiresAt *time.Time `json:"expires_at"`
	SeatsMax  *int       `json:"seats_max"`
}

// AccessState — runtime-контур лицензии для RBAC-сессии.
// Ось проверки: tenant (коммерция) + project (контур работ).
// Subtenant (workspace) — только справочно в workspace_id; не путать с managed tenant (клиент-реферал).
type AccessState struct {
	OK            bool           `json:"ok"`
	TenantCode    string         `json:"tenant_code"`
	ProjectCode   string         `json:"project_code"`
	WorkspaceID   string         `json:"workspace_id,omitempty"`
	TenantActive  bool           `json:"tenant_active"`
	ProjectActive bool           `json:"project_active"`
	LicenseActive bool           `json:"license_active"`
	Features      map[string]any `json:"features"`
	Limits        map[string]any `json:"limits"`
	License       *LicenseInfo   `json:"license"`
	CheckedAt     string         `json:"checked_at"`

	// Deprecated: legacy subtenant path; mirrors workspace_id / project_active for старых клиентов.
	SubtenantCode   string `json:"subtenant_code,omitempty"`
	SubtenantActive bool   `json:"subtenant_active"`
}

type Entitlements struct {
	Features map[string]any `json:"features"`
	Limits   map[string]any `json:"limits"`
	License  *LicenseInfo   `json:"license"`
}

// AccessStateForProject проверяет tenant + project (основной контракт).
// workspaceSubtenant сужает поиск проекта, если code не уникален в tenant.
func (r *Repository) AccessStateForProject(tenantCode, projectCode, workspaceSubtenant string) AccessState {
	tenantCode = code.Normalize(tenantCode)
	projectCode = code.Normalize(projectCode)
	workspaceSubtenant = code.Normalize(workspaceSubtenant)

	tenant, _ := r.findTenant(tenantCode)
	project, workspace := r.findProject(tenantCode, projectCode, workspaceSubtenant)
	ent := r.Entitlements(tenantCode)

	licenseActive := false
	if ent.License != nil {
		licenseActive = ent.License.Status == "active" &&
			(ent.License.ExpiresAt == nil || ent.License.ExpiresAt.After(time.Now().UTC()))
	}

	subtenantActive := true
	if workspace != "" {
		if sub, _ := r.findSubtenant(tenantCode, workspace); sub == nil || sub.Status != "active" {
			subtenantActive = false
		}
	}
	projectActive := project != nil && project.Status == "active" && subtenantActive
	if workspace == "" && project != nil {
		workspace = project.WorkspaceID
	}

	return AccessState{
		OK:            true,
		TenantCode:    tenantCode,
		ProjectCode:   projectCode,
		WorkspaceID:   workspace,
		TenantActive:  tenant != nil && tenant.Status == "active",
		ProjectActive: projectActive,
		LicenseActive: licenseActive,
		Features:      ent.Features,
		Limits:        ent.Limits,
		License:       ent.License,
		CheckedAt:     time.Now().UTC().Format("2006-01-02 15:04:05"),
		SubtenantCode: workspace,
		SubtenantActive: projectActive,
	}
}

// AccessState — legacy: subtenant path → default project "main" в workspace.
// Deprecated: используйте AccessStateForProject.
func (r *Repository) AccessState(tenantCode, subtenantCode string) AccessState {
	return r.AccessStateForProject(tenantCode, "main", subtenantCode)
}

type projectRow struct {
	Status      string
	WorkspaceID string
}

func (r *Repository) findProject(tenantCode, projectCode, workspaceSubtenant string) (*projectRow, string) {
	if workspaceSubtenant != "" {
		row, ws := r.queryProject(tenantCode, workspaceSubtenant, projectCode)
		return row, ws
	}
	// tenant-level project (workspace '')
	if row, _ := r.queryProject(tenantCode, "", projectCode); row != nil {
		return row, ""
	}
	// fallback: первый active project с таким code в tenant
	row := r.db.QueryRow(
		`SELECT status, subtenant_id FROM maniforge_projects
		 WHERE tenant_id = $1 AND code = $2 AND status = 'active'
		 ORDER BY CASE WHEN subtenant_id = '' THEN 0 ELSE 1 END, id ASC
		 LIMIT 1`, tenantCode, projectCode)
	var pr projectRow
	var ws string
	err := row.Scan(&pr.Status, &ws)
	if err != nil {
		return nil, ""
	}
	pr.WorkspaceID = ws
	return &pr, ws
}

func (r *Repository) queryProject(tenantCode, workspaceSubtenant, projectCode string) (*projectRow, string) {
	row := r.db.QueryRow(
		`SELECT status, subtenant_id FROM maniforge_projects
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND code = $3
		 LIMIT 1`, tenantCode, workspaceSubtenant, projectCode)
	var pr projectRow
	var ws string
	err := row.Scan(&pr.Status, &ws)
	if err == sql.ErrNoRows {
		return nil, workspaceSubtenant
	}
	if err != nil {
		return nil, workspaceSubtenant
	}
	pr.WorkspaceID = ws
	return &pr, ws
}

func (r *Repository) Entitlements(tenantCode string) Entitlements {
	license := r.activeLicense(code.Normalize(tenantCode))
	if license == nil {
		return Entitlements{
			Features: map[string]any{},
			Limits:   map[string]any{},
			License:  nil,
		}
	}

	return Entitlements{
		Features: decodeJSONMap(license.FeaturesJSON),
		Limits:   decodeJSONMap(license.LimitsJSON),
		License: &LicenseInfo{
			PlanCode:  license.PlanCode,
			Status:    license.LicenseStatus,
			ExpiresAt: license.ExpiresAt,
			SeatsMax:  seatsFromNull(license.SeatsMax),
		},
	}
}

func (r *Repository) ListTenants(limit int) ([]map[string]any, error) {
	if limit <= 0 {
		limit = 100
	}
	rows, err := r.db.Query(
		`SELECT code, name, status, suspended_at, metadata_json, created_at, updated_at
		 FROM maniforge_tl_tenants ORDER BY code ASC LIMIT $1`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	return scanRows(rows)
}

func (r *Repository) ListPlans() ([]map[string]any, error) {
	rows, err := r.db.Query(
		`SELECT code, name, status, features_json, limits_json, created_at, updated_at
		 FROM maniforge_tl_license_plans ORDER BY code ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	return scanRows(rows)
}

func (r *Repository) PendingEvents(limit int) ([]map[string]any, error) {
	if limit <= 0 {
		limit = 50
	}
	rows, err := r.db.Query(
		`SELECT id, event_type, tenant_code, subtenant_code, payload_json, created_at
		 FROM maniforge_tl_events WHERE delivered_at IS NULL ORDER BY id ASC LIMIT $1`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	return scanRows(rows)
}

func (r *Repository) ListEvents(tenantCode string, limit int) ([]map[string]any, error) {
	if limit <= 0 {
		limit = 50
	}
	tenantCode = code.Normalize(tenantCode)
	if tenantCode == "" {
		rows, err := r.db.Query(
			`SELECT id, event_type, tenant_code, subtenant_code, payload_json, delivered_at, created_at
			 FROM maniforge_tl_events ORDER BY id DESC LIMIT $1`, limit)
		if err != nil {
			return nil, err
		}
		defer rows.Close()
		return scanRows(rows)
	}
	rows, err := r.db.Query(
		`SELECT id, event_type, tenant_code, subtenant_code, payload_json, delivered_at, created_at
		 FROM maniforge_tl_events
		 WHERE tenant_code = $1
		 ORDER BY id DESC LIMIT $2`, tenantCode, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanRows(rows)
}

func (r *Repository) AckEvent(eventID int64) (bool, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_tl_events SET delivered_at = NOW()
		 WHERE id = $1 AND delivered_at IS NULL`, eventID)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

type tenantRow struct {
	Status string
}

type subtenantRow struct {
	Status string
}

type activeLicenseRow struct {
	PlanCode       string
	LicenseStatus  string
	FeaturesJSON   []byte
	LimitsJSON     []byte
	ExpiresAt      *time.Time
	SeatsMax       sql.NullInt64 // scanned from DB
}

func (r *Repository) findTenant(tenantCode string) (*tenantRow, error) {
	var row tenantRow
	err := r.db.QueryRow(
		`SELECT status FROM maniforge_tl_tenants WHERE code = $1 LIMIT 1`, tenantCode).
		Scan(&row.Status)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &row, nil
}

func (r *Repository) findSubtenant(tenantCode, subtenantCode string) (*subtenantRow, error) {
	var row subtenantRow
	err := r.db.QueryRow(
		`SELECT status FROM maniforge_tl_subtenants WHERE tenant_code = $1 AND code = $2 LIMIT 1`,
		tenantCode, subtenantCode).
		Scan(&row.Status)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &row, nil
}

func (r *Repository) activeLicense(tenantCode string) *activeLicenseRow {
	var row activeLicenseRow
	var expires sql.NullTime
	var seats sql.NullInt64

	err := r.db.QueryRow(
		`SELECT l.plan_code, l.status AS license_status, p.features_json, p.limits_json,
		        l.expires_at, l.seats_max
		 FROM maniforge_tl_tenant_licenses l
		 INNER JOIN maniforge_tl_license_plans p ON p.code = l.plan_code
		 WHERE l.tenant_code = $1 AND l.status = 'active'
		 ORDER BY l.id DESC LIMIT 1`, tenantCode).
		Scan(&row.PlanCode, &row.LicenseStatus, &row.FeaturesJSON, &row.LimitsJSON, &expires, &seats)
	if err != nil {
		return nil
	}

	if expires.Valid {
		t := expires.Time.UTC()
		row.ExpiresAt = &t
	}
	row.SeatsMax = seats
	return &row
}

func seatsFromNull(n sql.NullInt64) *int {
	if !n.Valid {
		return nil
	}
	v := int(n.Int64)
	return &v
}

func decodeJSONMap(raw []byte) map[string]any {
	if len(raw) == 0 {
		return map[string]any{}
	}
	var m map[string]any
	if err := json.Unmarshal(raw, &m); err != nil || m == nil {
		return map[string]any{}
	}
	return m
}

func scanRows(rows *sql.Rows) ([]map[string]any, error) {
	cols, err := rows.Columns()
	if err != nil {
		return nil, err
	}

	var items []map[string]any
	for rows.Next() {
		values := make([]any, len(cols))
		ptrs := make([]any, len(cols))
		for i := range values {
			ptrs[i] = &values[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			return nil, err
		}
		item := make(map[string]any, len(cols))
		for i, col := range cols {
			item[col] = normalizeValue(values[i])
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func normalizeValue(v any) any {
	switch t := v.(type) {
	case nil:
		return nil
	case []byte:
		if len(t) == 0 {
			return nil
		}
		var decoded any
		if json.Unmarshal(t, &decoded) == nil {
			return decoded
		}
		return string(t)
	case time.Time:
		return t.UTC().Format(time.RFC3339)
	default:
		return v
	}
}
