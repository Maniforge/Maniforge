// Package repository — фоновые задачи Tenant Licensing (expire, dispatch).
package repository

import (
	"encoding/json"
	"time"

	"maniforge/internal/platform/code"
)

// ExpireDueLicenses переводит просроченные active-лицензии в expired и ставит license.expired events.
func (r *Repository) ExpireDueLicenses(actor string) (expired, scanned int, err error) {
	if actor == "" {
		actor = "license-expiry-job"
	}
	rows, err := r.db.Query(
		`SELECT id, tenant_code, plan_code
		 FROM maniforge_tl_tenant_licenses
		 WHERE status = 'active'
		   AND expires_at IS NOT NULL
		   AND expires_at <= NOW()
		 ORDER BY id ASC`)
	if err != nil {
		return 0, 0, err
	}
	defer rows.Close()

	for rows.Next() {
		var id int64
		var tenantCode, planCode string
		if err := rows.Scan(&id, &tenantCode, &planCode); err != nil {
			return expired, scanned, err
		}
		scanned++
		tenantCode = code.Normalize(tenantCode)
		planCode = code.Normalize(planCode)

		res, err := r.db.Exec(
			`UPDATE maniforge_tl_tenant_licenses
			 SET status = 'expired', updated_at = NOW()
			 WHERE id = $1 AND status = 'active'`, id)
		if err != nil {
			return expired, scanned, err
		}
		n, _ := res.RowsAffected()
		if n == 0 {
			continue
		}
		expired++
		payload := map[string]any{"plan_code": planCode, "license_id": id}
		_ = r.writeAudit("license.expired", actor, tenantCode, "", payload)
		_ = r.enqueueEvent("license.expired", tenantCode, "", payload)
	}
	return expired, scanned, rows.Err()
}

// PendingEventRow — событие для dispatch в RBAC.
type PendingEventRow struct {
	ID             int64
	EventType      string
	TenantCode     string
	SubtenantCode  string
	PayloadJSON    []byte
}

// PendingEventsRows возвращает необработанные lifecycle events.
func (r *Repository) PendingEventsRows(limit int) ([]PendingEventRow, error) {
	if limit <= 0 {
		limit = 50
	}
	rows, err := r.db.Query(
		`SELECT id, event_type, tenant_code, COALESCE(subtenant_code, ''), payload_json
		 FROM maniforge_tl_events
		 WHERE delivered_at IS NULL
		 ORDER BY id ASC
		 LIMIT $1`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []PendingEventRow
	for rows.Next() {
		var row PendingEventRow
		if err := rows.Scan(&row.ID, &row.EventType, &row.TenantCode, &row.SubtenantCode, &row.PayloadJSON); err != nil {
			return nil, err
		}
		items = append(items, row)
	}
	return items, rows.Err()
}

// DispatchPayload decodes event payload for RBAC internal API.
func (row PendingEventRow) DispatchPayload() map[string]any {
	if len(row.PayloadJSON) == 0 {
		return map[string]any{}
	}
	var m map[string]any
	if err := json.Unmarshal(row.PayloadJSON, &m); err != nil || m == nil {
		return map[string]any{}
	}
	return m
}

// LicenseExpiresInDays helper re-export for scheduler tests.
func SchedulerNowUTC() time.Time {
	return time.Now().UTC()
}
