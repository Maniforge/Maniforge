// Файл: profile.go
// Назначение: обновление maniforge_user_profile без отзыва сессий.
// Зависимости: repository.UserProfileRepository.
// См. также: handler/me.go, repository/user_profile.go
package service

import (
	"database/sql"
	"fmt"
	"strings"
	"unicode/utf8"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/rbac/repository"
)

type ProfileService struct {
	profiles *repository.UserProfileRepository
}

func NewProfileService(db *sql.DB) *ProfileService {
	return &ProfileService{profiles: repository.NewUserProfileRepository(db)}
}

type ProfileUpdateRequest struct {
	DisplayName *string `json:"display_name"`
	AvatarURL   *string `json:"avatar_url"`
	Bio         *string `json:"bio"`
	Locale      *string `json:"locale"`
	Timezone    *string `json:"timezone"`
}

func (s *ProfileService) Update(userID int64, req ProfileUpdateRequest) (map[string]any, int) {
	if req.DisplayName == nil && req.AvatarURL == nil && req.Bio == nil && req.Locale == nil && req.Timezone == nil {
		return map[string]any{"ok": false, "error": "Укажите поля профиля"}, fiber.StatusUnprocessableEntity
	}
	if err := validateProfileInput(req); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusUnprocessableEntity
	}

	profile, err := s.profiles.Upsert(userID, repository.ProfileUpdateInput{
		DisplayName: req.DisplayName,
		AvatarURL:   req.AvatarURL,
		Bio:         req.Bio,
		Locale:      req.Locale,
		Timezone:    req.Timezone,
	})
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	return map[string]any{
		"ok":      true,
		"profile": repository.PublicProfile(profile),
	}, fiber.StatusOK
}

func validateProfileInput(req ProfileUpdateRequest) error {
	if req.DisplayName != nil && utf8.RuneCountInString(strings.TrimSpace(*req.DisplayName)) > 120 {
		return errProfileField("display_name", 120)
	}
	if req.AvatarURL != nil && utf8.RuneCountInString(strings.TrimSpace(*req.AvatarURL)) > 1024 {
		return errProfileField("avatar_url", 1024)
	}
	if req.Bio != nil && utf8.RuneCountInString(strings.TrimSpace(*req.Bio)) > 1024 {
		return errProfileField("bio", 1024)
	}
	if req.Locale != nil && utf8.RuneCountInString(strings.TrimSpace(*req.Locale)) > 16 {
		return errProfileField("locale", 16)
	}
	if req.Timezone != nil && utf8.RuneCountInString(strings.TrimSpace(*req.Timezone)) > 64 {
		return errProfileField("timezone", 64)
	}
	return nil
}

type profileFieldError string

func (e profileFieldError) Error() string { return string(e) }

func errProfileField(name string, max int) error {
	return profileFieldError(fmt.Sprintf("%s слишком длинное (макс. %d символов)", name, max))
}
