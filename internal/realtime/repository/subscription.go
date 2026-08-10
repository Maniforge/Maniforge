// Файл: subscription.go
// Назначение: CRUD maniforge_realtime_subscriptions.
package repository

import (
	"database/sql"
	"encoding/json"
	"time"
)

type Subscription struct {
	ID          int64
	TenantID    string
	SubtenantID string
	ProjectID   int64
	UserID      int64
	Name        string
	Channels    []string
	Status      string
	CreatedAt   time.Time
	UpdatedAt   time.Time
}

type SubscriptionRepository struct {
	db *sql.DB
}

func NewSubscriptionRepository(db *sql.DB) *SubscriptionRepository {
	return &SubscriptionRepository{db: db}
}

func (r *SubscriptionRepository) Create(tenantID, subtenantID string, projectID, userID int64, name string, channels []string) (*Subscription, error) {
	raw, _ := json.Marshal(channels)
	row := r.db.QueryRow(
		`INSERT INTO maniforge_realtime_subscriptions (
			tenant_id, subtenant_id, project_id, user_id, name, channels_json, updated_at
		) VALUES ($1, $2, $3, $4, $5, $6, NOW())
		RETURNING id, created_at, updated_at`,
		tenantID, subtenantID, projectID, userID, name, raw,
	)
	var s Subscription
	s.TenantID, s.SubtenantID, s.ProjectID, s.UserID = tenantID, subtenantID, projectID, userID
	s.Name, s.Channels, s.Status = name, channels, "active"
	err := row.Scan(&s.ID, &s.CreatedAt, &s.UpdatedAt)
	return &s, err
}

func (r *SubscriptionRepository) List(tenantID, subtenantID string, projectID, userID int64) ([]Subscription, error) {
	rows, err := r.db.Query(
		`SELECT id, tenant_id, subtenant_id, project_id, user_id, name, channels_json, status, created_at, updated_at
		 FROM maniforge_realtime_subscriptions
		 WHERE tenant_id = $1 AND subtenant_id = $2 AND project_id = $3 AND user_id = $4 AND status = 'active'
		 ORDER BY id DESC`,
		tenantID, subtenantID, projectID, userID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	return scanSubscriptions(rows)
}

func (r *SubscriptionRepository) GetByID(tenantID, subtenantID string, projectID, userID, id int64) (*Subscription, error) {
	row := r.db.QueryRow(
		`SELECT id, tenant_id, subtenant_id, project_id, user_id, name, channels_json, status, created_at, updated_at
		 FROM maniforge_realtime_subscriptions
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3 AND project_id = $4 AND user_id = $5 AND status = 'active'`,
		id, tenantID, subtenantID, projectID, userID,
	)
	return scanSubscriptionRow(row)
}

func (r *SubscriptionRepository) Update(id int64, tenantID, subtenantID string, projectID, userID int64, name string, channels []string) (*Subscription, error) {
	raw, _ := json.Marshal(channels)
	res, err := r.db.Exec(
		`UPDATE maniforge_realtime_subscriptions
		 SET name = $6, channels_json = $7, updated_at = NOW()
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3 AND project_id = $4 AND user_id = $5 AND status = 'active'`,
		id, tenantID, subtenantID, projectID, userID, name, raw,
	)
	if err != nil {
		return nil, err
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return nil, nil
	}
	return r.GetByID(tenantID, subtenantID, projectID, userID, id)
}

func (r *SubscriptionRepository) Archive(id int64, tenantID, subtenantID string, projectID, userID int64) (bool, error) {
	res, err := r.db.Exec(
		`UPDATE maniforge_realtime_subscriptions SET status = 'archived', updated_at = NOW()
		 WHERE id = $1 AND tenant_id = $2 AND subtenant_id = $3 AND project_id = $4 AND user_id = $5 AND status = 'active'`,
		id, tenantID, subtenantID, projectID, userID,
	)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func (r *SubscriptionRepository) ListManifestCodes(tenantID string, projectID int64) (map[string]string, error) {
	rows, err := r.db.Query(
		`SELECT code, origin FROM maniforge_manifests
		 WHERE tenant_id = $1 AND project_id = $2 AND status = 'active'`,
		tenantID, projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[string]string{}
	for rows.Next() {
		var code, origin string
		if err := rows.Scan(&code, &origin); err != nil {
			return nil, err
		}
		out[code] = origin
	}
	return out, rows.Err()
}

func scanSubscriptions(rows *sql.Rows) ([]Subscription, error) {
	var items []Subscription
	for rows.Next() {
		s, err := scanSubscriptionFromRows(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, *s)
	}
	return items, rows.Err()
}

func scanSubscriptionRow(row *sql.Row) (*Subscription, error) {
	var s Subscription
	var raw []byte
	err := row.Scan(
		&s.ID, &s.TenantID, &s.SubtenantID, &s.ProjectID, &s.UserID,
		&s.Name, &raw, &s.Status, &s.CreatedAt, &s.UpdatedAt,
	)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	_ = json.Unmarshal(raw, &s.Channels)
	return &s, nil
}

func scanSubscriptionFromRows(rows *sql.Rows) (*Subscription, error) {
	var s Subscription
	var raw []byte
	if err := rows.Scan(
		&s.ID, &s.TenantID, &s.SubtenantID, &s.ProjectID, &s.UserID,
		&s.Name, &raw, &s.Status, &s.CreatedAt, &s.UpdatedAt,
	); err != nil {
		return nil, err
	}
	_ = json.Unmarshal(raw, &s.Channels)
	return &s, nil
}

func (s Subscription) ToMap() map[string]any {
	return map[string]any{
		"id": s.ID, "name": s.Name, "channels": s.Channels, "status": s.Status,
		"tenant_id": s.TenantID, "subtenant_id": s.SubtenantID,
		"project_id": s.ProjectID, "user_id": s.UserID,
		"created_at": s.CreatedAt.UTC().Format(time.RFC3339),
		"updated_at": s.UpdatedAt.UTC().Format(time.RFC3339),
	}
}
