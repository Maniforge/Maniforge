// Файл: product.go
// Назначение: maniforge_products — CRUD и visibility в scope tenant/project.
// См. также: app/Maniforge/Products/Repository/ProductRepository.php
package repository

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
	"time"
)

type ProductRow struct {
	ID                   int64
	TenantID             string
	SubtenantID          string
	ProjectID            sql.NullInt64
	ScopeVisibility      string
	SharedSubtenantIDs   json.RawMessage
	SharedGrantTenantIDs json.RawMessage
	Code                 string
	Name                 string
	Unit                 string
	Description          sql.NullString
	AttributesJSON       json.RawMessage
	Status               string
	CreatedBy            sql.NullInt64
	UpdatedBy            sql.NullInt64
	CreatedAt            time.Time
	UpdatedAt            sql.NullTime
}

type ProductRepository struct {
	db *sql.DB
}

func NewProductRepository(db *sql.DB) *ProductRepository {
	return &ProductRepository{db: db}
}

func localVisibilitySQL(alias string, start int) string {
	return fmt.Sprintf(`(
		%s.tenant_id = $%d AND %s.subtenant_id = $%d
		AND (%s.project_id IS NULL OR %s.project_id = $%d)
	)`, alias, start, alias, start+1, alias, alias, start+2)
}

func (r *ProductRepository) FindVisibleByID(tenantID, subtenantID string, projectID, id int64) (*ProductRow, error) {
	q := `SELECT id, tenant_id, subtenant_id, project_id, scope_visibility,
		shared_subtenant_ids_json, shared_grant_tenant_ids_json,
		code, name, unit, description, attributes_json, status,
		created_by, updated_by, created_at, updated_at
		FROM maniforge_products p
		WHERE p.id = $1 AND ` + localVisibilitySQL("p", 2) + ` AND p.status = 'active'`
	row := r.db.QueryRow(q, id, tenantID, subtenantID, projectID)
	return scanProduct(row)
}

func (r *ProductRepository) FindByCodeInScope(tenantID, subtenantID string, projectID sql.NullInt64, code string) (*ProductRow, error) {
	q := `SELECT id, tenant_id, subtenant_id, project_id, scope_visibility,
		shared_subtenant_ids_json, shared_grant_tenant_ids_json,
		code, name, unit, description, attributes_json, status,
		created_by, updated_by, created_at, updated_at
		FROM maniforge_products
		WHERE tenant_id = $1 AND subtenant_id = $2 AND code = $3 AND status = 'active'`
	args := []any{tenantID, subtenantID, code}
	if projectID.Valid {
		q += ` AND project_id = $4`
		args = append(args, projectID.Int64)
	} else {
		q += ` AND project_id IS NULL`
	}
	row := r.db.QueryRow(q, args...)
	return scanProduct(row)
}

type CreateProductInput struct {
	TenantID             string
	SubtenantID          string
	ProjectID            sql.NullInt64
	ScopeVisibility      string
	Code                 string
	Name                 string
	Unit                 string
	Description          sql.NullString
	AttributesJSON       json.RawMessage
	CreatedBy            int64
	SharedGrantTenantIDs json.RawMessage
}

func (r *ProductRepository) Create(in CreateProductInput) (*ProductRow, error) {
	var id int64
	err := r.db.QueryRow(`
		INSERT INTO maniforge_products (
			tenant_id, subtenant_id, project_id, scope_visibility,
			shared_grant_tenant_ids_json, code, name, unit, description,
			attributes_json, created_by, status
		) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,'active')
		RETURNING id`,
		in.TenantID, in.SubtenantID, nullInt64(in.ProjectID), in.ScopeVisibility,
		nullJSON(in.SharedGrantTenantIDs), in.Code, in.Name, in.Unit,
		nullString(in.Description), nullJSON(in.AttributesJSON), in.CreatedBy,
	).Scan(&id)
	if err != nil {
		return nil, err
	}
	return r.FindByIDInTenant(in.TenantID, id)
}

func (r *ProductRepository) FindByIDInTenant(tenantID string, id int64) (*ProductRow, error) {
	row := r.db.QueryRow(`
		SELECT id, tenant_id, subtenant_id, project_id, scope_visibility,
			shared_subtenant_ids_json, shared_grant_tenant_ids_json,
			code, name, unit, description, attributes_json, status,
			created_by, updated_by, created_at, updated_at
		FROM maniforge_products WHERE id = $1 AND tenant_id = $2`, id, tenantID)
	return scanProduct(row)
}

func scanProduct(row *sql.Row) (*ProductRow, error) {
	var p ProductRow
	var sharedSub, sharedGrant, attrs sql.NullString
	err := row.Scan(
		&p.ID, &p.TenantID, &p.SubtenantID, &p.ProjectID, &p.ScopeVisibility,
		&sharedSub, &sharedGrant,
		&p.Code, &p.Name, &p.Unit, &p.Description, &attrs, &p.Status,
		&p.CreatedBy, &p.UpdatedBy, &p.CreatedAt, &p.UpdatedAt,
	)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	p.SharedSubtenantIDs = nullRaw(sharedSub)
	p.SharedGrantTenantIDs = nullRaw(sharedGrant)
	p.AttributesJSON = nullRaw(attrs)
	return &p, nil
}

func (p ProductRow) ToMap(viewerTenant string) map[string]any {
	out := map[string]any{
		"id": p.ID, "tenant_id": p.TenantID, "subtenant_id": p.SubtenantID,
		"code": p.Code, "name": p.Name, "unit": p.Unit, "status": p.Status,
		"scope_visibility": p.ScopeVisibility,
		"is_delegated_view": p.TenantID != viewerTenant,
	}
	if p.ProjectID.Valid {
		out["project_id"] = p.ProjectID.Int64
	}
	if p.Description.Valid {
		out["description"] = p.Description.String
	}
	if len(p.AttributesJSON) > 0 {
		var data any
		if json.Unmarshal(p.AttributesJSON, &data) == nil {
			out["attributes"] = data
		}
	}
	out["created_at"] = p.CreatedAt.UTC().Format("2006-01-02 15:04:05")
	if p.UpdatedAt.Valid {
		out["updated_at"] = p.UpdatedAt.Time.UTC().Format("2006-01-02 15:04:05")
	}
	return out
}

func nullInt64(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}

func nullString(v sql.NullString) any {
	if v.Valid {
		return v.String
	}
	return nil
}

func nullJSON(b json.RawMessage) any {
	if len(b) == 0 {
		return nil
	}
	return string(b)
}

func nullRaw(v sql.NullString) json.RawMessage {
	if !v.Valid || v.String == "" {
		return nil
	}
	return json.RawMessage(v.String)
}

var slugRe = regexp.MustCompile(`[^a-z0-9]+`)

func GenerateCode(name string) string {
	slug := strings.Trim(slugRe.ReplaceAllString(strings.ToLower(name), "-"), "-")
	if slug == "" {
		slug = "sku"
	}
	if len(slug) > 40 {
		slug = slug[:40]
	}
	b := make([]byte, 3)
	_, _ = rand.Read(b)
	return "sku-" + slug + "-" + hex.EncodeToString(b)
}
