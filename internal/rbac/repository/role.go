// Файл: role.go
// Назначение: maniforge_roles, maniforge_user_roles — роли, permissions, admin CRUD.
// См. также: service/registration.go, service/role_scope.go
package repository

import (
	"database/sql"
	"fmt"
	"strings"
	"time"
)

type RoleRepository struct {
	db *sql.DB
}

func NewRoleRepository(db *sql.DB) *RoleRepository {
	return &RoleRepository{db: db}
}

// ListRoleCodesForUser возвращает активные коды ролей пользователя в workspace.
func (r *RoleRepository) ListRoleCodesForUser(userID int64, tenantID, subtenantID string) ([]string, error) {
	rows, err := r.db.Query(
		`SELECT r.code FROM maniforge_user_roles ur
		 INNER JOIN maniforge_roles r ON r.id = ur.role_id
		 WHERE ur.user_id = $1 AND ur.tenant_id = $2 AND ur.subtenant_id = $3
		   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
		 ORDER BY r.code ASC`,
		userID, tenantID, subtenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var codes []string
	for rows.Next() {
		var code string
		if err := rows.Scan(&code); err != nil {
			return nil, err
		}
		codes = append(codes, code)
	}
	return codes, rows.Err()
}

// ListPermissionCodesForUser — DISTINCT permissions через роли пользователя в workspace.
func (r *RoleRepository) ListPermissionCodesForUser(userID int64, tenantID, subtenantID string) ([]string, error) {
	rows, err := r.db.Query(
		`SELECT DISTINCT p.code
		 FROM maniforge_user_roles ur
		 INNER JOIN maniforge_role_permissions rp ON rp.role_id = ur.role_id
		 INNER JOIN maniforge_permissions p ON p.id = rp.permission_id
		 WHERE ur.user_id = $1 AND ur.tenant_id = $2 AND ur.subtenant_id = $3
		   AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
		 ORDER BY p.code ASC`,
		userID, tenantID, subtenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var codes []string
	for rows.Next() {
		var code string
		if err := rows.Scan(&code); err != nil {
			return nil, err
		}
		codes = append(codes, code)
	}
	return codes, rows.Err()
}

// HasAnyRole проверяет пересечение кодов ролей пользователя с required.
func (r *RoleRepository) HasAnyRole(userID int64, tenantID, subtenantID string, required []string) (bool, error) {
	codes, err := r.ListRoleCodesForUser(userID, tenantID, subtenantID)
	if err != nil {
		return false, err
	}
	set := make(map[string]struct{}, len(codes))
	for _, c := range codes {
		set[c] = struct{}{}
	}
	for _, rcode := range required {
		if _, ok := set[rcode]; ok {
			return true, nil
		}
	}
	return false, nil
}

func (r *RoleRepository) AssignRoleByCode(userID int64, tenantID, subtenantID, roleCode string, assignedBy int64) bool {
	var roleID int64
	err := r.db.QueryRow(`SELECT id FROM maniforge_roles WHERE code = $1 LIMIT 1`, roleCode).Scan(&roleID)
	if err != nil {
		return false
	}

	var existing int64
	err = r.db.QueryRow(
		`SELECT id FROM maniforge_user_roles
		 WHERE user_id = $1 AND role_id = $2 AND tenant_id = $3 AND subtenant_id = $4
		   AND (expires_at IS NULL OR expires_at > NOW())
		 LIMIT 1`,
		userID, roleID, tenantID, subtenantID).Scan(&existing)
	if err == nil {
		return true
	}
	if err != sql.ErrNoRows {
		return false
	}

	_, err = r.db.Exec(
		`INSERT INTO maniforge_user_roles (user_id, role_id, tenant_id, subtenant_id, assigned_by)
		 VALUES ($1, $2, $3, $4, $5)`,
		userID, roleID, tenantID, subtenantID, assignedBy)
	return err == nil
}

func (r *RoleRepository) HasRoleInScope(userID int64, tenantID, subtenantID, roleCode string) (bool, error) {
	codes, err := r.ListRoleCodesForUser(userID, tenantID, subtenantID)
	if err != nil {
		return false, err
	}
	for _, c := range codes {
		if c == roleCode {
			return true, nil
		}
	}
	return false, nil
}

func (r *RoleRepository) ListUserRoles(userID int64, tenantID, subtenantID string) ([]map[string]any, error) {
	rows, err := r.db.Query(
		`SELECT ur.id, r.code AS role_code, r.name AS role_name, ur.assigned_by, ur.assigned_at, ur.expires_at
		 FROM maniforge_user_roles ur
		 INNER JOIN maniforge_roles r ON r.id = ur.role_id
		 WHERE ur.user_id = $1 AND ur.tenant_id = $2 AND ur.subtenant_id = $3
		 ORDER BY ur.id DESC`,
		userID, tenantID, subtenantID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []map[string]any
	for rows.Next() {
		var (
			id         int64
			roleCode   string
			roleName   string
			assignedBy sql.NullInt64
			assignedAt sql.NullTime
			expiresAt  sql.NullTime
		)
		if err := rows.Scan(&id, &roleCode, &roleName, &assignedBy, &assignedAt, &expiresAt); err != nil {
			return nil, err
		}
		item := map[string]any{"id": id, "role_code": roleCode, "role_name": roleName}
		if assignedBy.Valid {
			item["assigned_by"] = assignedBy.Int64
		}
		if assignedAt.Valid {
			item["assigned_at"] = assignedAt.Time.UTC().Format("2006-01-02 15:04:05")
		}
		if expiresAt.Valid {
			item["expires_at"] = expiresAt.Time.UTC().Format("2006-01-02 15:04:05")
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func (r *RoleRepository) ListRoles(scopePrefix string) ([]map[string]any, error) {
	var rows *sql.Rows
	var err error
	if strings.TrimSpace(scopePrefix) == "" {
		rows, err = r.db.Query(
			`SELECT id, code, name, is_system, created_at FROM maniforge_roles ORDER BY code ASC`)
	} else {
		rows, err = r.db.Query(
			`SELECT id, code, name, is_system, created_at FROM maniforge_roles
			 WHERE is_system = TRUE OR code LIKE $1 ORDER BY is_system DESC, code ASC`,
			scopePrefix+"%")
	}
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanRoleRows(rows)
}

func (r *RoleRepository) ListPermissions() ([]map[string]any, error) {
	rows, err := r.db.Query(
		`SELECT id, code, description FROM maniforge_permissions ORDER BY code ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var items []map[string]any
	for rows.Next() {
		var id int64
		var code, description string
		if err := rows.Scan(&id, &code, &description); err != nil {
			return nil, err
		}
		items = append(items, map[string]any{"id": id, "code": code, "description": description})
	}
	return items, rows.Err()
}

func (r *RoleRepository) ListRolePermissions(roleCode string) ([]map[string]any, error) {
	rows, err := r.db.Query(
		`SELECT p.id, p.code, p.description
		 FROM maniforge_role_permissions rp
		 INNER JOIN maniforge_roles r ON r.id = rp.role_id
		 INNER JOIN maniforge_permissions p ON p.id = rp.permission_id
		 WHERE r.code = $1 ORDER BY p.code ASC`, roleCode)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var items []map[string]any
	for rows.Next() {
		var id int64
		var code, description string
		if err := rows.Scan(&id, &code, &description); err != nil {
			return nil, err
		}
		items = append(items, map[string]any{"id": id, "code": code, "description": description})
	}
	return items, rows.Err()
}

func (r *RoleRepository) FindRoleByCode(roleCode string) (map[string]any, error) {
	row := r.db.QueryRow(
		`SELECT id, code, name, is_system, created_at FROM maniforge_roles WHERE code = $1 LIMIT 1`, roleCode)
	return scanRoleRow(row)
}

func (r *RoleRepository) CreateRole(code, name string) (map[string]any, error) {
	_, err := r.db.Exec(
		`INSERT INTO maniforge_roles (code, name, is_system) VALUES ($1, $2, FALSE)`, code, name)
	if err != nil {
		return nil, err
	}
	return r.FindRoleByCode(code)
}

func (r *RoleRepository) UpdateRole(code, name string) (map[string]any, error) {
	_, err := r.db.Exec(
		`UPDATE maniforge_roles SET name = $1 WHERE code = $2 AND is_system = FALSE`, name, code)
	if err != nil {
		return nil, err
	}
	return r.FindRoleByCode(code)
}

func (r *RoleRepository) DeleteRole(code string) (bool, error) {
	res, err := r.db.Exec(`DELETE FROM maniforge_roles WHERE code = $1 AND is_system = FALSE`, code)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func (r *RoleRepository) ReplaceRolePermissions(roleCode string, permissionCodes []string) (map[string]any, error) {
	role, err := r.FindRoleByCode(roleCode)
	if err != nil {
		return nil, err
	}
	if role == nil {
		return map[string]any{"ok": false, "error": "role_not_found"}, nil
	}
	roleID, _ := role["id"].(int64)

	seen := map[string]struct{}{}
	var normalized []string
	for _, code := range permissionCodes {
		c := strings.TrimSpace(code)
		if c == "" {
			continue
		}
		if _, ok := seen[c]; ok {
			continue
		}
		seen[c] = struct{}{}
		normalized = append(normalized, c)
	}

	permRows, err := r.findPermissionsByCodes(normalized)
	if err != nil {
		return nil, err
	}
	if len(permRows) != len(normalized) {
		found := map[string]struct{}{}
		for _, p := range permRows {
			found[p.code] = struct{}{}
		}
		var missing []string
		for _, c := range normalized {
			if _, ok := found[c]; !ok {
				missing = append(missing, c)
			}
		}
		return map[string]any{"ok": false, "error": "permission_not_found", "missing": missing}, nil
	}

	tx, err := r.db.Begin()
	if err != nil {
		return nil, err
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := tx.Exec(`DELETE FROM maniforge_role_permissions WHERE role_id = $1`, roleID); err != nil {
		return nil, err
	}
	for _, p := range permRows {
		if _, err := tx.Exec(
			`INSERT INTO maniforge_role_permissions (role_id, permission_id) VALUES ($1, $2)`,
			roleID, p.id); err != nil {
			return nil, err
		}
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	perms, err := r.ListRolePermissions(roleCode)
	if err != nil {
		return nil, err
	}
	return map[string]any{"ok": true, "permissions": perms}, nil
}

type permRef struct {
	id   int64
	code string
}

func (r *RoleRepository) findPermissionsByCodes(codes []string) ([]permRef, error) {
	if len(codes) == 0 {
		return nil, nil
	}
	placeholders := make([]string, len(codes))
	args := make([]any, len(codes))
	for i, code := range codes {
		placeholders[i] = fmt.Sprintf("$%d", i+1)
		args[i] = code
	}
	query := fmt.Sprintf(
		`SELECT id, code FROM maniforge_permissions WHERE code IN (%s)`,
		strings.Join(placeholders, ", "))
	rows, err := r.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []permRef
	for rows.Next() {
		var p permRef
		if err := rows.Scan(&p.id, &p.code); err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

func scanRoleRows(rows *sql.Rows) ([]map[string]any, error) {
	var items []map[string]any
	for rows.Next() {
		item, err := scanRoleFromRows(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func scanRoleRow(row *sql.Row) (map[string]any, error) {
	var (
		id       int64
		code     string
		name     string
		isSystem bool
		created  time.Time
	)
	err := row.Scan(&id, &code, &name, &isSystem, &created)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{
		"id": id, "code": code, "name": name, "is_system": isSystem,
		"created_at": created.UTC().Format("2006-01-02 15:04:05"),
	}, nil
}

func scanRoleFromRows(rows *sql.Rows) (map[string]any, error) {
	var (
		id       int64
		code     string
		name     string
		isSystem bool
		created  time.Time
	)
	if err := rows.Scan(&id, &code, &name, &isSystem, &created); err != nil {
		return nil, err
	}
	return map[string]any{
		"id": id, "code": code, "name": name, "is_system": isSystem,
		"created_at": created.UTC().Format("2006-01-02 15:04:05"),
	}, nil
}
