// Файл: pd_admin.go
// Назначение: PD admin — operator profile, purposes, subject requests, compliance.
// См. также: pd.go, handler/pd_admin.go
package repository

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

func (r *PDRepository) FindOperatorProfile(tenantID string) (map[string]any, error) {
	tenantID = strings.ToLower(strings.TrimSpace(tenantID))
	row := r.db.QueryRow(
		`SELECT tenant_id, operator_name, operator_inn, operator_address, dpo_name, dpo_email, dpo_phone,
		        privacy_policy_url, privacy_policy_version, data_storage_region,
		        cross_border_transfer_allowed, cross_border_basis, roskomnadzor_notified_at, metadata_json
		 FROM maniforge_pd_operator_profiles WHERE tenant_id = $1 LIMIT 1`, tenantID)
	return scanOperatorRow(row)
}

func (r *PDRepository) UpsertOperatorProfile(tenantID string, fields map[string]any) (map[string]any, error) {
	tenantID = strings.ToLower(strings.TrimSpace(tenantID))
	existing, _ := r.FindOperatorProfile(tenantID)
	params := bindOperatorParams(tenantID, fields, existing)

	if existing == nil {
		_, err := r.db.Exec(
			`INSERT INTO maniforge_pd_operator_profiles (
				tenant_id, operator_name, operator_inn, operator_address, dpo_name, dpo_email, dpo_phone,
				privacy_policy_url, privacy_policy_version, data_storage_region,
				cross_border_transfer_allowed, cross_border_basis, roskomnadzor_notified_at, metadata_json
			) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14::jsonb)`,
			params...)
		if err != nil {
			return nil, err
		}
	} else {
		_, err := r.db.Exec(
			`UPDATE maniforge_pd_operator_profiles SET
				operator_name=$2, operator_inn=$3, operator_address=$4, dpo_name=$5, dpo_email=$6, dpo_phone=$7,
				privacy_policy_url=$8, privacy_policy_version=$9, data_storage_region=$10,
				cross_border_transfer_allowed=$11, cross_border_basis=$12, roskomnadzor_notified_at=$13,
				metadata_json=$14::jsonb, updated_at=NOW()
			 WHERE tenant_id=$1`, params...)
		if err != nil {
			return nil, err
		}
	}
	return r.FindOperatorProfile(tenantID)
}

func (r *PDRepository) ListAllPurposes(tenantID string) ([]map[string]any, error) {
	rows, err := r.db.Query(
		`SELECT id, tenant_id, code, title, description, legal_basis, retention_days,
		        is_mandatory_for_registration, is_active, policy_version, created_at, updated_at
		 FROM maniforge_pd_processing_purposes WHERE tenant_id = $1 ORDER BY code ASC`, tenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanPurposeRows(rows)
}

func (r *PDRepository) CreatePurpose(tenantID string, input map[string]any) (map[string]any, error) {
	code := normalizePurposeCode(stringValAny(input["code"]))
	legalBasis := strings.ToLower(strings.TrimSpace(stringValAny(input["legal_basis"])))
	if !isLegalBasis(legalBasis) {
		legalBasis = "consent"
	}
	title := strings.TrimSpace(stringValAny(input["title"]))
	if title == "" {
		title = code
	}
	_, err := r.db.Exec(
		`INSERT INTO maniforge_pd_processing_purposes (
			tenant_id, code, title, description, legal_basis, retention_days,
			is_mandatory_for_registration, is_active, policy_version
		) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)`,
		tenantID, code, title, nullableString(input["description"]), legalBasis,
		nullableInt(input["retention_days"]), boolValAny(input["is_mandatory_for_registration"]),
		boolValAnyDefault(input["is_active"], true), policyVersion(input),
	)
	if err != nil {
		return nil, err
	}
	return r.findPurposeByCode(tenantID, code)
}

func (r *PDRepository) UpdatePurpose(tenantID, code string, input map[string]any) (map[string]any, error) {
	existing, err := r.findPurposeByCode(tenantID, code)
	if err != nil || existing == nil {
		return nil, err
	}
	legalBasis := strings.ToLower(strings.TrimSpace(stringValAny(input["legal_basis"])))
	if legalBasis != "" && !isLegalBasis(legalBasis) {
		legalBasis = stringValAny(existing["legal_basis"])
	}
	_, err = r.db.Exec(
		`UPDATE maniforge_pd_processing_purposes SET
			title=COALESCE(NULLIF($3,''), title),
			description=COALESCE($4, description),
			legal_basis=COALESCE(NULLIF($5,''), legal_basis),
			retention_days=COALESCE($6, retention_days),
			is_mandatory_for_registration=COALESCE($7, is_mandatory_for_registration),
			is_active=COALESCE($8, is_active),
			policy_version=COALESCE(NULLIF($9,''), policy_version),
			updated_at=NOW()
		 WHERE tenant_id=$1 AND code=$2`,
		tenantID, code,
		strings.TrimSpace(stringValAny(input["title"])),
		nullableString(input["description"]),
		legalBasis,
		nullableInt(input["retention_days"]),
		nullableBool(input["is_mandatory_for_registration"]),
		nullableBool(input["is_active"]),
		policyVersion(input),
	)
	if err != nil {
		return nil, err
	}
	return r.findPurposeByCode(tenantID, code)
}

func (r *PDRepository) ListSubjectRequestsForScope(tenantID, subtenantID, status string, limit int) ([]map[string]any, error) {
	if limit < 1 {
		limit = 100
	}
	query := `SELECT id, user_id, tenant_id, subtenant_id, request_type, status, payload_json,
	                 handler_user_id, handler_note, due_at, completed_at, created_at, updated_at
	          FROM maniforge_pd_subject_requests
	          WHERE tenant_id = $1 AND subtenant_id = $2`
	args := []any{tenantID, subtenantID}
	if status != "" {
		query += ` AND status = $3`
		args = append(args, status)
	}
	query += ` ORDER BY created_at DESC LIMIT $` + itoa(len(args)+1)
	args = append(args, limit)

	rows, err := r.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanSubjectRequestRows(rows)
}

func (r *PDRepository) ResolveSubjectRequest(id int64, tenantID, subtenantID, status string, handlerUserID int64, note *string) (map[string]any, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_pd_subject_requests SET
			status=$4, handler_user_id=$5, handler_note=$6,
			completed_at=CASE WHEN $4 IN ('completed','rejected') THEN NOW() ELSE completed_at END,
			updated_at=NOW()
		 WHERE id=$1 AND tenant_id=$2 AND subtenant_id=$3`,
		id, tenantID, subtenantID, status, handlerUserID, note)
	if err != nil {
		return nil, err
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return nil, nil
	}
	return r.findSubjectRequestByID(id)
}

func (r *PDRepository) findPurposeByCode(tenantID, code string) (map[string]any, error) {
	row := r.db.QueryRow(
		`SELECT id, tenant_id, code, title, description, legal_basis, retention_days,
		        is_mandatory_for_registration, is_active, policy_version, created_at, updated_at
		 FROM maniforge_pd_processing_purposes WHERE tenant_id = $1 AND code = $2 LIMIT 1`,
		tenantID, code)
	return scanPurposeRow(row)
}

func (r *PDRepository) findSubjectRequestByID(id int64) (map[string]any, error) {
	row := r.db.QueryRow(
		`SELECT id, user_id, tenant_id, subtenant_id, request_type, status, payload_json,
		        handler_user_id, handler_note, due_at, completed_at, created_at, updated_at
		 FROM maniforge_pd_subject_requests WHERE id = $1 LIMIT 1`, id)
	return scanSubjectRequestRow(row)
}

func (r *PDRepository) BuildComplianceStatus(tenantID string) map[string]any {
	profile, _ := r.FindOperatorProfile(tenantID)
	readiness := operatorReadiness(profile)
	return map[string]any{
		"enforced": false, "exempt": false,
		"operator_ready": readiness["ready"], "operator_missing": readiness["missing"],
		"processor_configured": false, "processor": nil,
		"ready_for_users": readiness["ready"],
	}
}

func scanOperatorRow(row *sql.Row) (map[string]any, error) {
	var (
		tenantID, operatorName, policyVersion, region string
		inn, address, dpoName, dpoEmail, dpoPhone     sql.NullString
		policyURL, crossBasis                         sql.NullString
		crossAllowed                                  bool
		roskomnadzor                                  sql.NullTime
		meta                                          []byte
	)
	err := row.Scan(&tenantID, &operatorName, &inn, &address, &dpoName, &dpoEmail, &dpoPhone,
		&policyURL, &policyVersion, &region, &crossAllowed, &crossBasis, &roskomnadzor, &meta)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string]any{
		"tenant_id": tenantID, "operator_name": operatorName,
		"privacy_policy_version": policyVersion, "data_storage_region": region,
		"cross_border_transfer_allowed": crossAllowed,
	}
	setNullString(out, "operator_inn", inn)
	setNullString(out, "operator_address", address)
	setNullString(out, "dpo_name", dpoName)
	setNullString(out, "dpo_email", dpoEmail)
	setNullString(out, "dpo_phone", dpoPhone)
	setNullString(out, "privacy_policy_url", policyURL)
	setNullString(out, "cross_border_basis", crossBasis)
	if roskomnadzor.Valid {
		out["roskomnadzor_notified_at"] = roskomnadzor.Time.Format("2006-01-02")
	}
	if len(meta) > 0 {
		var m any
		_ = json.Unmarshal(meta, &m)
		out["metadata"] = m
	}
	return out, nil
}

func bindOperatorParams(tenantID string, fields, existing map[string]any) []any {
	meta := fields["metadata"]
	if meta == nil {
		meta = fields["metadata_json"]
	}
	if meta == nil && existing != nil {
		meta = existing["metadata"]
	}
	metaJSON, _ := json.Marshal(meta)
	return []any{
		tenantID,
		strings.TrimSpace(stringValAny(fields["operator_name"])),
		nullableString(fields["operator_inn"]),
		nullableString(fields["operator_address"]),
		nullableString(fields["dpo_name"]),
		nullableString(fields["dpo_email"]),
		nullableString(fields["dpo_phone"]),
		nullableString(fields["privacy_policy_url"]),
		policyVersion(fields),
		strings.TrimSpace(stringValAny(fields["data_storage_region"])),
		boolValAnyDefault(fields["cross_border_transfer_allowed"], false),
		nullableString(fields["cross_border_basis"]),
		nullableDate(fields["roskomnadzor_notified_at"]),
		string(metaJSON),
	}
}

func operatorReadiness(profile map[string]any) map[string]any {
	if profile == nil {
		return map[string]any{"ready": false, "missing": []string{"operator_profile"}}
	}
	var missing []string
	if strings.TrimSpace(stringValAny(profile["operator_name"])) == "" {
		missing = append(missing, "operator_name")
	}
	if strings.TrimSpace(stringValAny(profile["privacy_policy_url"])) == "" {
		missing = append(missing, "privacy_policy_url")
	}
	return map[string]any{"ready": len(missing) == 0, "missing": missing}
}

func normalizePurposeCode(code string) string {
	code = strings.ToLower(strings.TrimSpace(code))
	code = strings.ReplaceAll(code, " ", "_")
	return code
}

func isLegalBasis(v string) bool {
	switch v {
	case "consent", "contract", "legal_obligation", "legitimate_interest":
		return true
	default:
		return false
	}
}

func policyVersion(input map[string]any) string {
	v := strings.TrimSpace(stringValAny(input["privacy_policy_version"]))
	if v == "" {
		v = strings.TrimSpace(stringValAny(input["policy_version"]))
	}
	if v == "" {
		return "1.0"
	}
	return v
}

func stringValAny(v any) string {
	if v == nil {
		return ""
	}
	switch t := v.(type) {
	case string:
		return t
	default:
		return strings.TrimSpace(strings.Trim(fmt.Sprint(t), "\""))
	}
}

func boolValAny(v any) bool {
	switch t := v.(type) {
	case bool:
		return t
	case string:
		return strings.EqualFold(t, "true") || t == "1"
	default:
		return false
	}
}

func boolValAnyDefault(v any, def bool) bool {
	if v == nil {
		return def
	}
	switch t := v.(type) {
	case bool:
		return t
	case string:
		if t == "" {
			return def
		}
		return strings.EqualFold(t, "true") || t == "1"
	default:
		return def
	}
}

func nullableString(v any) interface{} {
	s := strings.TrimSpace(stringValAny(v))
	if s == "" {
		return nil
	}
	return s
}

func nullableInt(v any) interface{} {
	switch t := v.(type) {
	case float64:
		return int(t)
	case int:
		return t
	case int64:
		return int(t)
	default:
		return nil
	}
}

func nullableBool(v any) interface{} {
	if v == nil {
		return nil
	}
	return boolValAny(v)
}

func nullableDate(v any) interface{} {
	s := strings.TrimSpace(stringValAny(v))
	if s == "" {
		return nil
	}
	return s
}

func setNullString(m map[string]any, key string, v sql.NullString) {
	if v.Valid {
		m[key] = v.String
	}
}

func itoa(n int) string {
	return fmt.Sprint(n)
}

func scanPurposeRows(rows *sql.Rows) ([]map[string]any, error) {
	var items []map[string]any
	for rows.Next() {
		item, err := scanPurposeFromRows(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func scanPurposeRow(row *sql.Row) (map[string]any, error) {
	// reuse scan from rows via Query is awkward; duplicate minimal scan
	var (
		id                                                          int64
		tenant, code, title, legalBasis, policyVersion              string
		description                                                 sql.NullString
		retention                                                   sql.NullInt64
		mandatory, active                                           bool
		created                                                     time.Time
		updated                                                     sql.NullTime
	)
	err := row.Scan(&id, &tenant, &code, &title, &description, &legalBasis, &retention,
		&mandatory, &active, &policyVersion, &created, &updated)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return purposeMap(id, tenant, code, title, description, legalBasis, retention, mandatory, active, policyVersion, created, updated), nil
}

func scanPurposeFromRows(rows *sql.Rows) (map[string]any, error) {
	var (
		id                                             int64
		tenant, code, title, legalBasis, policyVersion string
		description                                    sql.NullString
		retention                                      sql.NullInt64
		mandatory, active                              bool
		created                                        time.Time
		updated                                        sql.NullTime
	)
	if err := rows.Scan(&id, &tenant, &code, &title, &description, &legalBasis, &retention,
		&mandatory, &active, &policyVersion, &created, &updated); err != nil {
		return nil, err
	}
	return purposeMap(id, tenant, code, title, description, legalBasis, retention, mandatory, active, policyVersion, created, updated), nil
}

func purposeMap(id int64, tenant, code, title string, description sql.NullString, legalBasis string, retention sql.NullInt64, mandatory, active bool, policyVersion string, created time.Time, updated sql.NullTime) map[string]any {
	item := map[string]any{
		"id": id, "tenant_id": tenant, "code": code, "title": title, "legal_basis": legalBasis,
		"is_mandatory_for_registration": mandatory, "is_active": active, "policy_version": policyVersion,
		"created_at": created.UTC().Format("2006-01-02 15:04:05"),
	}
	if description.Valid {
		item["description"] = description.String
	}
	if retention.Valid {
		item["retention_days"] = retention.Int64
	}
	if updated.Valid {
		item["updated_at"] = updated.Time.UTC().Format("2006-01-02 15:04:05")
	}
	return item
}

func scanSubjectRequestRows(rows *sql.Rows) ([]map[string]any, error) {
	var items []map[string]any
	for rows.Next() {
		item, err := scanSubjectRequestFromRows(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func scanSubjectRequestRow(row *sql.Row) (map[string]any, error) {
	var (
		id int64
		userID int64
		tenant, subtenant, reqType, status string
		payload []byte
		handlerID sql.NullInt64
		note sql.NullString
		dueAt, completedAt, createdAt, updatedAt sql.NullTime
	)
	err := row.Scan(&id, &userID, &tenant, &subtenant, &reqType, &status, &payload,
		&handlerID, &note, &dueAt, &completedAt, &createdAt, &updatedAt)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return subjectRequestMap(id, userID, tenant, subtenant, reqType, status, payload, handlerID, note, dueAt, completedAt, createdAt, updatedAt), nil
}

func scanSubjectRequestFromRows(rows *sql.Rows) (map[string]any, error) {
	var (
		id int64
		userID int64
		tenant, subtenant, reqType, status string
		payload []byte
		handlerID sql.NullInt64
		note sql.NullString
		dueAt, completedAt, createdAt, updatedAt sql.NullTime
	)
	if err := rows.Scan(&id, &userID, &tenant, &subtenant, &reqType, &status, &payload,
		&handlerID, &note, &dueAt, &completedAt, &createdAt, &updatedAt); err != nil {
		return nil, err
	}
	return subjectRequestMap(id, userID, tenant, subtenant, reqType, status, payload, handlerID, note, dueAt, completedAt, createdAt, updatedAt), nil
}

func subjectRequestMap(id, userID int64, tenant, subtenant, reqType, status string, payload []byte, handlerID sql.NullInt64, note sql.NullString, dueAt, completedAt, createdAt, updatedAt sql.NullTime) map[string]any {
	item := map[string]any{
		"id": id, "user_id": userID, "tenant_id": tenant, "subtenant_id": subtenant,
		"request_type": reqType, "status": status,
	}
	if len(payload) > 0 {
		var p any
		_ = json.Unmarshal(payload, &p)
		item["payload_json"] = p
	}
	if handlerID.Valid {
		item["handler_user_id"] = handlerID.Int64
	}
	if note.Valid {
		item["handler_note"] = note.String
	}
	if dueAt.Valid {
		item["due_at"] = dueAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	if completedAt.Valid {
		item["completed_at"] = completedAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	if createdAt.Valid {
		item["created_at"] = createdAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	if updatedAt.Valid {
		item["updated_at"] = updatedAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	return item
}
