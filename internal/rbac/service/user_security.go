// Файл: user_security.go
// Назначение: критичные изменения maniforge_users → security_version++ + RevokeAllForUser.
// Зависимости: repository/user.go, repository/session.go.
// См. также: handler/me.go (PatchIdentity, ChangePassword)
package service

import (
	"database/sql"

	"github.com/gofiber/fiber/v2"
	"maniforge/internal/config"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/security"
)

type UserSecurityService struct {
	users    *repository.UserRepository
	sessions *repository.SessionRepository
}

func NewUserSecurityService(cfg config.Config, db *sql.DB) *UserSecurityService {
	return &UserSecurityService{
		users:    repository.NewUserRepository(db, cfg),
		sessions: repository.NewSessionRepository(db),
	}
}

type IdentityUpdateRequest struct {
	Email   *string `json:"email"`
	Phone   *string `json:"phone"`
	Status  *string `json:"status"`
	Login   *string `json:"login"`
}

type ChangePasswordRequest struct {
	CurrentPassword string `json:"current_password"`
	NewPassword     string `json:"new_password"`
}

// ApplyIdentityUpdate меняет критичные поля users и отзывает все сессии.
func (s *UserSecurityService) ApplyIdentityUpdate(
	userID int64, tenantID, subtenantID string, req IdentityUpdateRequest,
) (map[string]any, int) {
	input := repository.IdentityUpdateInput{
		Email:  req.Email,
		Phone:  req.Phone,
		Status: req.Status,
		Login:  req.Login,
	}
	if input.Email == nil && input.Phone == nil && input.Status == nil && input.Login == nil {
		return map[string]any{"ok": false, "error": "Укажите поля для обновления"}, fiber.StatusUnprocessableEntity
	}
	if input.Phone != nil && !repository.ValidatePhone(*input.Phone) {
		return map[string]any{
			"ok":    false,
			"error": "Телефон: укажите код страны и номер (10–15 цифр в международном формате)",
		}, fiber.StatusUnprocessableEntity
	}

	user, err := s.users.ApplyIdentityUpdate(userID, tenantID, subtenantID, input)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if user == nil {
		return map[string]any{"ok": false, "error": "Пользователь не найден"}, fiber.StatusNotFound
	}

	revoked, err := s.sessions.RevokeAllForUser(userID, "identity_changed")
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	return map[string]any{
		"ok":               true,
		"user":             repository.PublicUser(*user),
		"revoked_sessions": revoked,
		"message":          "Идентичность обновлена. Все сессии завершены — войдите снова",
	}, fiber.StatusOK
}

func (s *UserSecurityService) ChangePassword(
	userID int64, tenantID, subtenantID string, req ChangePasswordRequest,
) (map[string]any, int) {
	if req.CurrentPassword == "" || req.NewPassword == "" {
		return map[string]any{"ok": false, "error": "current_password и new_password обязательны"}, fiber.StatusUnprocessableEntity
	}

	minLen := 12
	if req.CurrentPassword == req.NewPassword {
		return map[string]any{"ok": false, "error": "Новый пароль должен отличаться от текущего"}, fiber.StatusUnprocessableEntity
	}
	if len(req.NewPassword) < minLen {
		return map[string]any{
			"ok":    false,
			"error": "Новый пароль должен быть не короче 12 символов",
		}, fiber.StatusUnprocessableEntity
	}

	user, err := s.users.FindByIDInScope(userID, tenantID, subtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	if user == nil {
		return map[string]any{"ok": false, "error": "Пользователь не найден"}, fiber.StatusNotFound
	}
	if !security.VerifyPassword(req.CurrentPassword, user.PasswordHash) {
		return map[string]any{"ok": false, "error": "Текущий пароль неверный"}, fiber.StatusForbidden
	}
	if security.VerifyPassword(req.NewPassword, user.PasswordHash) {
		return map[string]any{"ok": false, "error": "Новый пароль совпадает с текущим"}, fiber.StatusUnprocessableEntity
	}

	newHash, err := security.HashPassword(req.NewPassword)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	updated, err := s.users.ApplyIdentityUpdate(userID, tenantID, subtenantID, repository.IdentityUpdateInput{
		PasswordHash: &newHash,
	})
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	revoked, err := s.sessions.RevokeAllForUser(userID, "password_changed")
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}

	return map[string]any{
		"ok":               true,
		"user":             repository.PublicUser(*updated),
		"revoked_sessions": revoked,
		"message":          "Пароль обновлен. Все сессии завершены",
	}, fiber.StatusOK
}
