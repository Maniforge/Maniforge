// Файл: policy.go
// Назначение: maniforge_policy_rules — admin policy (IP, UTC window, step-up).
// См. также: service/policy.go
package repository

import (
	"database/sql"
	"encoding/json"
)

type PolicyRuleRepository struct {
	db *sql.DB
}

func NewPolicyRuleRepository(db *sql.DB) *PolicyRuleRepository {
	return &PolicyRuleRepository{db: db}
}

type PolicyRule struct {
	TenantID              string
	SubtenantID           string
	AllowedIPs            []string
	AllowedHourStartUTC   int
	AllowedHourEndUTC     int
	RequireStepUp           bool
	RequireMFAEnrollment    bool
}

func (r *PolicyRuleRepository) GetForScope(tenantID, subtenantID string) (*PolicyRule, error) {
	var (
		ipsJSON []byte
		start   int
		end     int
		stepUp     bool
		requireMFA bool
	)
	err := r.db.QueryRow(
		`SELECT allowed_ips_json, allowed_hour_start_utc, allowed_hour_end_utc, require_step_up, require_mfa_enrollment
		 FROM maniforge_policy_rules
		 WHERE tenant_id = $1 AND subtenant_id = $2 LIMIT 1`,
		tenantID, subtenantID).Scan(&ipsJSON, &start, &end, &stepUp, &requireMFA)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	ips := decodeIPs(ipsJSON)
	return &PolicyRule{
		TenantID: tenantID, SubtenantID: subtenantID,
		AllowedIPs: ips, AllowedHourStartUTC: start, AllowedHourEndUTC: end,
		RequireStepUp: stepUp, RequireMFAEnrollment: requireMFA,
	}, nil
}

func (r *PolicyRuleRepository) UpsertForScope(
	tenantID, subtenantID string, allowedIPs []string, hourStart, hourEnd int,
	requireStepUp, requireMFAEnrollment bool, updatedBy int64,
) error {
	ipsJSON, err := json.Marshal(allowedIPs)
	if err != nil {
		return err
	}
	_, err = r.db.Exec(
		`INSERT INTO maniforge_policy_rules (
			tenant_id, subtenant_id, allowed_ips_json, allowed_hour_start_utc,
			allowed_hour_end_utc, require_step_up, require_mfa_enrollment, updated_by
		) VALUES ($1, $2, $3::jsonb, $4, $5, $6, $7, $8)
		ON CONFLICT (tenant_id, subtenant_id) DO UPDATE SET
			allowed_ips_json = EXCLUDED.allowed_ips_json,
			allowed_hour_start_utc = EXCLUDED.allowed_hour_start_utc,
			allowed_hour_end_utc = EXCLUDED.allowed_hour_end_utc,
			require_step_up = EXCLUDED.require_step_up,
			require_mfa_enrollment = EXCLUDED.require_mfa_enrollment,
			updated_by = EXCLUDED.updated_by,
			updated_at = NOW()`,
		tenantID, subtenantID, string(ipsJSON), hourStart, hourEnd, requireStepUp, requireMFAEnrollment, updatedBy)
	return err
}

func decodeIPs(raw []byte) []string {
	var decoded []any
	if len(raw) == 0 {
		return nil
	}
	if err := json.Unmarshal(raw, &decoded); err != nil {
		return nil
	}
	var ips []string
	for _, v := range decoded {
		s := trimString(v)
		if s != "" {
			ips = append(ips, s)
		}
	}
	return ips
}

func trimString(v any) string {
	switch t := v.(type) {
	case string:
		return t
	default:
		return ""
	}
}
