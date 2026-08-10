// Package licensingclient — клиент Tenant Licensing для RBAC runtime-проверок.
//
// Файл: client.go
// Назначение: AssertAccess(tenant, project, workspace); HTTP или in-process fallback.
// Зависимости: tenantlicensing/repository, config.TenantLicensing*.
// См. также: service/auth.go, service/session.go, docs/MANIFORGE_TENANT_LICENSING_SERVICE.md
package licensingclient

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"maniforge/internal/config"
	"maniforge/internal/platform/code"
	"maniforge/internal/tenantlicensing/repository"
)

// Decision — результат проверки licensing (allow/deny + HTTP-статус для RBAC).
type Decision struct {
	OK         bool
	Status     int
	Error      string
	DenyReason string
	Source     string
	State      repository.AccessState
	Temporary  bool
	Warning    string
}

// Client вызывает Tenant Licensing по HTTP или in-process (общая БД).
type Client struct {
	cfg  config.Config
	repo *repository.Repository
	http *http.Client
}

// New создаёт licensing-клиент; db нужен для fallback без HTTP URL.
func New(cfg config.Config, db *sql.DB) *Client {
	timeout := time.Duration(cfg.TenantLicensingTimeoutSec) * time.Second
	if timeout < time.Second {
		timeout = 2 * time.Second
	}
	var repo *repository.Repository
	if db != nil {
		repo = repository.New(db)
	}
	return &Client{
		cfg:  cfg,
		repo: repo,
		http: &http.Client{Timeout: timeout},
	}
}

// AssertAccess проверяет tenant + project (контур работ сессии).
// workspaceSubtenant — технический workspace (subtenant_id), не managed client.
func (c *Client) AssertAccess(tenantCode, projectCode, workspaceSubtenant string) Decision {
	mode := strings.ToLower(c.cfg.TenantLicensingEnforcement)
	if mode == "disabled" {
		return Decision{OK: true, Source: "disabled"}
	}

	tenantCode = code.Normalize(tenantCode)
	projectCode = code.Normalize(projectCode)
	if projectCode == "" {
		projectCode = "main"
	}
	workspaceSubtenant = code.Normalize(workspaceSubtenant)

	state, err := c.fetchAccessState(tenantCode, projectCode, workspaceSubtenant)
	if err != nil {
		if mode == "optional" {
			return Decision{OK: true, Source: "optional", Warning: "tenant licensing unavailable"}
		}
		return Decision{
			OK:        false,
			Status:    http.StatusServiceUnavailable,
			Error:     "Tenant/Licensing service недоступен",
			Temporary: true,
			Source:    "live",
		}
	}

	return c.decision(state, "live")
}

func (c *Client) fetchAccessState(tenantCode, projectCode, workspaceSubtenant string) (repository.AccessState, error) {
	base := strings.TrimRight(c.cfg.TenantLicensingInternalURL, "/")
	if base == "" {
		if c.repo == nil {
			return repository.AccessState{}, fmt.Errorf("licensing repository unavailable")
		}
		return c.repo.AccessStateForProject(tenantCode, projectCode, workspaceSubtenant), nil
	}

	path := fmt.Sprintf("%s/internal/v1/tenants/%s/projects/%s/access-state",
		base,
		url.PathEscape(tenantCode),
		url.PathEscape(projectCode),
	)
	if workspaceSubtenant != "" {
		path += "?workspace=" + url.QueryEscape(workspaceSubtenant)
	}

	req, err := http.NewRequestWithContext(context.Background(), http.MethodGet, path, nil)
	if err != nil {
		return repository.AccessState{}, err
	}
	req.Header.Set("Accept", "application/json")
	if token := c.cfg.TenantLicensingInternalToken; token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}

	resp, err := c.http.Do(req)
	if err != nil {
		return repository.AccessState{}, err
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return repository.AccessState{}, err
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return repository.AccessState{}, fmt.Errorf("licensing HTTP %d", resp.StatusCode)
	}

	var state repository.AccessState
	if err := json.Unmarshal(body, &state); err != nil {
		return repository.AccessState{}, err
	}
	if !state.OK {
		return repository.AccessState{}, fmt.Errorf("licensing unsuccessful response")
	}
	return state, nil
}

func (c *Client) decision(state repository.AccessState, source string) Decision {
	if !state.OK {
		return Decision{
			OK:        false,
			Status:    http.StatusServiceUnavailable,
			Error:     "Tenant/Licensing state недоступен",
			Temporary: true,
			Source:    source,
		}
	}
	if !state.TenantActive {
		return Decision{
			OK:         false,
			Status:     http.StatusForbidden,
			Error:      "Tenant не активен",
			DenyReason: "tenant_not_active",
			Source:     source,
			State:      state,
		}
	}
	if !state.ProjectActive {
		return Decision{
			OK:         false,
			Status:     http.StatusForbidden,
			Error:      "Проект не активен или не найден",
			DenyReason: "project_not_active",
			Source:     source,
			State:      state,
		}
	}
	if !state.LicenseActive {
		return Decision{
			OK:         false,
			Status:     http.StatusPaymentRequired,
			Error:      "Лицензия tenant недействительна",
			DenyReason: "license_not_active",
			Source:     source,
			State:      state,
		}
	}
	return Decision{OK: true, Source: source, State: state}
}

func (c *Client) AssertUserActivationAllowed(tenantCode, workspaceSubtenant string, activeUsers int) Decision {
	decision := c.AssertAccess(tenantCode, "main", workspaceSubtenant)
	if !decision.OK {
		return decision
	}

	seatsMax := 0
	if decision.State.License != nil && decision.State.License.SeatsMax != nil {
		seatsMax = *decision.State.License.SeatsMax
	}
	if seatsMax <= 0 {
		if v, ok := decision.State.Limits["max_users"].(float64); ok && int(v) > 0 {
			seatsMax = int(v)
		}
	}
	if seatsMax <= 0 {
		return decision
	}
	if activeUsers >= seatsMax {
		return Decision{
			OK:         false,
			Status:     402,
			Error:      "Лимит активных пользователей по лицензии исчерпан",
			DenyReason: "seats_quota_exceeded",
			Source:     decision.Source,
			State:      decision.State,
		}
	}
	return decision
}
