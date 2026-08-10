package repository

import "database/sql"

// CountAuditByManifest — число audit-событий для manifest в tenant (тесты).
func (r *Repository) CountAuditByManifest(tenantID string, projectID int64, manifestCode string) (int, error) {
	var n int
	err := r.db.QueryRow(
		`SELECT COUNT(*) FROM maniforge_manifest_audit_log
		 WHERE tenant_id = $1 AND project_id = $2 AND manifest_code = $3`,
		tenantID, projectID, manifestCode,
	).Scan(&n)
	return n, err
}

// ProjectIDByCode resolves project id for integration tests.
func ProjectIDByCode(db *sql.DB, tenantID, subtenantID, code string) (int64, error) {
	var id int64
	if code == "" {
		code = "main"
	}
	err := db.QueryRow(
		`SELECT id FROM maniforge_projects
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND code = $3 AND status = 'active' LIMIT 1`,
		tenantID, subtenantID, code,
	).Scan(&id)
	if err == sql.ErrNoRows {
		// fallback: any active project in tenant/workspace
		err = db.QueryRow(
			`SELECT id FROM maniforge_projects
			 WHERE tenant_id = $1 AND subtenant_id = $2 AND status = 'active'
			 ORDER BY id LIMIT 1`,
			tenantID, subtenantID,
		).Scan(&id)
	}
	return id, err
}
