// Файл: pd.go
// Назначение: согласия ПДн (152-ФЗ) при регистрации — валидация и запись consent.
// Таблицы: maniforge_pd_consent_templates, maniforge_pd_user_consents.
// См. также: service/registration.go, migrations/pg/006_pd_compliance.sql
package repository

import (
	"database/sql"
	"strings"

	"maniforge/internal/config"
)

type PDRepository struct {
	db  *sql.DB
	cfg config.Config
}

func NewPDRepository(db *sql.DB, cfg config.Config) *PDRepository {
	return &PDRepository{db: db, cfg: cfg}
}

func (r *PDRepository) SeedTenant(tenantID, operatorName string) error {
	tenantID = strings.ToLower(strings.TrimSpace(tenantID))
	if tenantID == "" {
		return nil
	}

	var exists bool
	_ = r.db.QueryRow(
		`SELECT EXISTS(SELECT 1 FROM maniforge_pd_operator_profiles WHERE tenant_id = $1)`, tenantID).
		Scan(&exists)
	if !exists {
		policyURL := strings.TrimRight(r.cfg.AppURL, "/") + "/docs/152FZ_COMPLIANCE.md"
		_, err := r.db.Exec(
			`INSERT INTO maniforge_pd_operator_profiles (
				tenant_id, operator_name, privacy_policy_url, privacy_policy_version,
				data_storage_region, cross_border_transfer_allowed
			) VALUES ($1, $2, $3, '1.0', 'RU', FALSE)`,
			tenantID, operatorName, policyURL)
		if err != nil {
			return err
		}
	}

	for _, item := range defaultPurposes() {
		var found bool
		_ = r.db.QueryRow(
			`SELECT EXISTS(SELECT 1 FROM maniforge_pd_processing_purposes WHERE tenant_id = $1 AND code = $2)`,
			tenantID, item.code).Scan(&found)
		if found {
			continue
		}
		_, err := r.db.Exec(
			`INSERT INTO maniforge_pd_processing_purposes (
				tenant_id, code, title, description, legal_basis, retention_days,
				is_mandatory_for_registration, policy_version
			) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)`,
			tenantID, item.code, item.title, item.description, item.legalBasis, item.retentionDays,
			item.mandatory, item.policyVersion)
		if err != nil {
			return err
		}
	}
	return nil
}

type purposeSeed struct {
	code, title, description, legalBasis, policyVersion string
	retentionDays                                     int
	mandatory                                         bool
}

func defaultPurposes() []purposeSeed {
	return []purposeSeed{
		{
			code: "account", title: "Учётная запись и аутентификация",
			description: "Регистрация, вход, восстановление доступа",
			legalBasis: "contract", retentionDays: 1825, mandatory: true, policyVersion: "1.0",
		},
		{
			code: "support", title: "Поддержка пользователей",
			legalBasis: "legitimate_interest", retentionDays: 1095, mandatory: false, policyVersion: "1.0",
		},
	}
}

func (r *PDRepository) ValidateRegistrationConsents(tenantID string, items []ConsentItem, required bool) map[string]any {
	mandatory, _ := r.listMandatoryForRegistration(tenantID)
	if required && len(mandatory) == 0 {
		return map[string]any{
			"ok": false, "status": 422,
			"error": "Для tenant не настроены обязательные цели обработки (purposes)",
		}
	}

	provided := normalizeConsentItems(items)
	for _, m := range mandatory {
		code := m.code
		expected := m.policyVersion
		if code == "" {
			continue
		}
		version, ok := provided[code]
		if !ok {
			return map[string]any{
				"ok": false, "status": 422,
				"error": "Необходимо согласие для цели: " + code,
				"missing_purpose": code,
			}
		}
		if required && version != expected {
			return map[string]any{
				"ok": false, "status": 422,
				"error": "Устаревшая версия политики для цели: " + code,
				"expected_policy_version": expected,
			}
		}
	}

	for code, version := range provided {
		var active bool
		err := r.db.QueryRow(
			`SELECT is_active FROM maniforge_pd_processing_purposes
			 WHERE tenant_id = $1 AND code = $2 LIMIT 1`, tenantID, code).Scan(&active)
		if err == sql.ErrNoRows || !active {
			return map[string]any{"ok": false, "status": 422, "error": "Неизвестная цель обработки: " + code}
		}
		_ = version
	}
	return nil
}

func (r *PDRepository) RecordRegistrationConsents(userID int64, tenantID, subtenantID string, items []ConsentItem, ip, userAgent string) error {
	for _, item := range items {
		code := item.PurposeCode
		if code == "" {
			code = item.Code
		}
		if code == "" {
			continue
		}
		version := item.PolicyVersion
		if version == "" {
			version = "1.0"
		}
		_, err := r.db.Exec(
			`INSERT INTO maniforge_pd_consents (
				user_id, tenant_id, subtenant_id, purpose_code, policy_version, source, ip_hash, user_agent_hash
			) VALUES ($1, $2, $3, $4, $5, 'registration', $6, $7)`,
			userID, tenantID, subtenantID, code, version, hashString(ip), hashString(userAgent))
		if err != nil {
			return err
		}
	}
	return nil
}

type ConsentItem struct {
	PurposeCode   string `json:"purpose_code"`
	Code          string `json:"code"`
	PolicyVersion string `json:"policy_version"`
}

type mandatoryPurpose struct {
	code, policyVersion string
}

func (r *PDRepository) listMandatoryForRegistration(tenantID string) ([]mandatoryPurpose, error) {
	rows, err := r.db.Query(
		`SELECT code, policy_version FROM maniforge_pd_processing_purposes
		 WHERE tenant_id = $1 AND is_active = TRUE AND is_mandatory_for_registration = TRUE`,
		tenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []mandatoryPurpose
	for rows.Next() {
		var m mandatoryPurpose
		if err := rows.Scan(&m.code, &m.policyVersion); err != nil {
			return nil, err
		}
		out = append(out, m)
	}
	return out, rows.Err()
}

func (r *PDRepository) BuildPrivacyNotice(tenantID string) (map[string]any, int) {
	tenantID = strings.ToLower(strings.TrimSpace(tenantID))
	row := r.db.QueryRow(
		`SELECT operator_name, privacy_policy_url, privacy_policy_version, data_storage_region,
		        cross_border_transfer_allowed
		 FROM maniforge_pd_operator_profiles WHERE tenant_id = $1 LIMIT 1`, tenantID)

	var operatorName, policyURL, policyVersion, region string
	var crossAllowed bool
	err := row.Scan(&operatorName, &policyURL, &policyVersion, &region, &crossAllowed)
	if err == sql.ErrNoRows {
		return map[string]any{"ok": false, "error": "Профиль оператора ПДн не настроен для tenant"}, 404
	}
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}

	purposes, err := r.listActivePurposes(tenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, 500
	}
	if len(purposes) == 0 {
		return map[string]any{"ok": false, "error": "Цели обработки ПДн не опубликованы"}, 404
	}

	return map[string]any{
		"ok": true, "status": 200,
		"notice": map[string]any{
			"operator":                      map[string]any{"name": operatorName},
			"privacy_policy_url":            policyURL,
			"privacy_policy_version":        policyVersion,
			"data_storage_region":           region,
			"cross_border_transfer_allowed": crossAllowed,
			"processing_purposes":           purposes,
		},
	}, 200
}

func (r *PDRepository) listActivePurposes(tenantID string) ([]map[string]any, error) {
	rows, err := r.db.Query(
		`SELECT code, title, description, legal_basis, retention_days, policy_version, is_mandatory_for_registration
		 FROM maniforge_pd_processing_purposes
		 WHERE tenant_id = $1 AND is_active = TRUE ORDER BY code ASC`, tenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var items []map[string]any
	for rows.Next() {
		var code, title, legalBasis, policyVersion string
		var description sql.NullString
		var retention sql.NullInt64
		var mandatory bool
		if err := rows.Scan(&code, &title, &description, &legalBasis, &retention, &policyVersion, &mandatory); err != nil {
			return nil, err
		}
		item := map[string]any{
			"code": code, "title": title, "legal_basis": legalBasis,
			"policy_version": policyVersion, "mandatory_for_registration": mandatory,
		}
		if description.Valid {
			item["description"] = description.String
		}
		if retention.Valid {
			item["retention_days"] = retention.Int64
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func normalizeConsentItems(items []ConsentItem) map[string]string {
	out := map[string]string{}
	for _, item := range items {
		code := item.PurposeCode
		if code == "" {
			code = item.Code
		}
		if code == "" {
			continue
		}
		version := item.PolicyVersion
		if version == "" {
			version = "1.0"
		}
		out[code] = version
	}
	return out
}
