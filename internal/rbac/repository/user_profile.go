// Файл: user_profile.go
// Назначение: maniforge_user_profile — мягкий профиль (display_name, avatar, locale, …).
// Изменения не затрагивают security_version и сессии.
// См. также: user.go, service/profile.go
package repository

import (
	"database/sql"
	"strings"
	"time"
)

// UserProfile — изменяемые данные без отзыва сессий.
type UserProfile struct {
	UserID      int64
	DisplayName sql.NullString
	AvatarURL   sql.NullString
	Bio         sql.NullString
	Locale      sql.NullString
	Timezone    sql.NullString
	UpdatedAt   sql.NullTime
}

type ProfileUpdateInput struct {
	DisplayName *string
	AvatarURL   *string
	Bio         *string
	Locale      *string
	Timezone    *string
}

type UserProfileRepository struct {
	db *sql.DB
}

func NewUserProfileRepository(db *sql.DB) *UserProfileRepository {
	return &UserProfileRepository{db: db}
}

func (r *UserProfileRepository) EnsureEmpty(userID int64) error {
	_, err := r.db.Exec(
		`INSERT INTO maniforge_user_profile (user_id) VALUES ($1)
		 ON CONFLICT (user_id) DO NOTHING`, userID)
	return err
}

func (r *UserProfileRepository) FindByUserID(userID int64) (*UserProfile, error) {
	row := r.db.QueryRow(
		`SELECT user_id, display_name, avatar_url, bio, locale, timezone, updated_at
		 FROM maniforge_user_profile WHERE user_id = $1 LIMIT 1`, userID)
	return scanProfileRow(row)
}

func scanProfileRow(row *sql.Row) (*UserProfile, error) {
	var p UserProfile
	err := row.Scan(
		&p.UserID, &p.DisplayName, &p.AvatarURL, &p.Bio, &p.Locale, &p.Timezone, &p.UpdatedAt,
	)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &p, nil
}

func (r *UserProfileRepository) Upsert(userID int64, input ProfileUpdateInput) (*UserProfile, error) {
	current, err := r.FindByUserID(userID)
	if err != nil {
		return nil, err
	}

	displayName := nullableFromCurrent(input.DisplayName, current, func(p *UserProfile) sql.NullString { return p.DisplayName })
	avatarURL := nullableFromCurrent(input.AvatarURL, current, func(p *UserProfile) sql.NullString { return p.AvatarURL })
	bio := nullableFromCurrent(input.Bio, current, func(p *UserProfile) sql.NullString { return p.Bio })
	locale := nullableFromCurrent(input.Locale, current, func(p *UserProfile) sql.NullString { return p.Locale })
	timezone := nullableFromCurrent(input.Timezone, current, func(p *UserProfile) sql.NullString { return p.Timezone })

	_, err = r.db.Exec(
		`INSERT INTO maniforge_user_profile (
			user_id, display_name, avatar_url, bio, locale, timezone, updated_at
		) VALUES ($1, $2, $3, $4, $5, $6, NOW())
		ON CONFLICT (user_id) DO UPDATE SET
			display_name = EXCLUDED.display_name,
			avatar_url = EXCLUDED.avatar_url,
			bio = EXCLUDED.bio,
			locale = EXCLUDED.locale,
			timezone = EXCLUDED.timezone,
			updated_at = NOW()`,
		userID, displayName, avatarURL, bio, locale, timezone)
	if err != nil {
		return nil, err
	}
	return r.FindByUserID(userID)
}

func nullableFromCurrent(
	in *string,
	current *UserProfile,
	getter func(*UserProfile) sql.NullString,
) sql.NullString {
	if in != nil {
		v := strings.TrimSpace(*in)
		if v == "" {
			return sql.NullString{}
		}
		return sql.NullString{String: v, Valid: true}
	}
	if current != nil {
		return getter(current)
	}
	return sql.NullString{}
}

func PublicProfile(p *UserProfile) map[string]any {
	if p == nil {
		return map[string]any{}
	}
	out := map[string]any{}
	if p.DisplayName.Valid && p.DisplayName.String != "" {
		out["display_name"] = p.DisplayName.String
	}
	if p.AvatarURL.Valid && p.AvatarURL.String != "" {
		out["avatar_url"] = p.AvatarURL.String
	}
	if p.Bio.Valid && p.Bio.String != "" {
		out["bio"] = p.Bio.String
	}
	if p.Locale.Valid && p.Locale.String != "" {
		out["locale"] = p.Locale.String
	}
	if p.Timezone.Valid && p.Timezone.String != "" {
		out["timezone"] = p.Timezone.String
	}
	if p.UpdatedAt.Valid {
		out["updated_at"] = p.UpdatedAt.Time.UTC().Format(time.RFC3339)
	}
	return out
}
