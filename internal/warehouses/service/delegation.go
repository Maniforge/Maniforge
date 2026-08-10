// Файл: delegation.go
// Назначение: delegation share для stocks (share_with_principal, grant peers).
package service

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/gofiber/fiber/v2"
)

type DelegationShareService struct {
	db *sql.DB
}

func NewDelegationShareService(db *sql.DB) *DelegationShareService {
	return &DelegationShareService{db: db}
}

func (s *DelegationShareService) ListActiveGrantPeers(ownerTenant string) ([]string, error) {
	ownerTenant = strings.ToLower(strings.TrimSpace(ownerTenant))
	rows, err := s.db.Query(
		`SELECT principal_tenant_code, managed_tenant_code
		 FROM maniforge_tl_tenant_grants
		 WHERE status = 'active' AND (principal_tenant_code = $1 OR managed_tenant_code = $1)`,
		ownerTenant)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	seen := map[string]struct{}{}
	var peers []string
	for rows.Next() {
		var principal, managed string
		if err := rows.Scan(&principal, &managed); err != nil {
			return nil, err
		}
		for _, code := range []string{principal, managed} {
			code = strings.ToLower(strings.TrimSpace(code))
			if code == "" || code == ownerTenant {
				continue
			}
			if _, ok := seen[code]; ok {
				continue
			}
			seen[code] = struct{}{}
			peers = append(peers, code)
		}
	}
	return peers, rows.Err()
}

func (s *DelegationShareService) ResolveShareJSON(ownerTenant string, input map[string]any) (json.RawMessage, int, error) {
	if !shareInputPresent(input) {
		return nil, 0, nil
	}
	codes := []string{}
	if asBool(input["share_with_principal"]) || asBool(input["shareWithPrincipal"]) {
		principals, err := s.principalPeersForManaged(ownerTenant)
		if err != nil {
			return nil, fiber.StatusInternalServerError, err
		}
		codes = append(codes, principals...)
	}
	codes = uniqueTenantCodes(codes, ownerTenant)
	if len(codes) == 0 {
		return nil, 0, nil
	}
	for _, peer := range codes {
		if !s.hasActiveGrant(ownerTenant, peer) {
			return nil, fiber.StatusUnprocessableEntity, fmt.Errorf("Нет активного grant между %s и %s", ownerTenant, peer)
		}
	}
	raw, err := json.Marshal(codes)
	return raw, 0, err
}

func (s *DelegationShareService) principalPeersForManaged(managed string) ([]string, error) {
	rows, err := s.db.Query(
		`SELECT principal_tenant_code FROM maniforge_tl_tenant_grants
		 WHERE status = 'active' AND managed_tenant_code = $1 AND principal_tenant_code <> managed_tenant_code`,
		managed)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []string
	for rows.Next() {
		var code string
		if err := rows.Scan(&code); err != nil {
			return nil, err
		}
		out = append(out, strings.ToLower(strings.TrimSpace(code)))
	}
	return out, rows.Err()
}

func (s *DelegationShareService) hasActiveGrant(a, b string) bool {
	var exists bool
	_ = s.db.QueryRow(
		`SELECT EXISTS(
			SELECT 1 FROM maniforge_tl_tenant_grants
			WHERE status = 'active' AND (
				(principal_tenant_code = $1 AND managed_tenant_code = $2)
				OR (principal_tenant_code = $2 AND managed_tenant_code = $1)
			)
		)`, a, b).Scan(&exists)
	return exists
}

func asBool(v any) bool {
	switch t := v.(type) {
	case bool:
		return t
	case string:
		return t == "true" || t == "1"
	case float64:
		return t != 0
	default:
		return false
	}
}

func shareInputPresent(input map[string]any) bool {
	for _, k := range []string{"share_with_principal", "shareWithPrincipal", "delegation_share_tenant_ids"} {
		if _, ok := input[k]; ok {
			return true
		}
	}
	return false
}

func uniqueTenantCodes(codes []string, owner string) []string {
	seen := map[string]struct{}{}
	var out []string
	for _, c := range codes {
		c = strings.ToLower(strings.TrimSpace(c))
		if c == "" || c == owner {
			continue
		}
		if _, ok := seen[c]; ok {
			continue
		}
		seen[c] = struct{}{}
		out = append(out, c)
	}
	return out
}
