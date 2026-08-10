// Файл: mfa.go
// Назначение: TOTP MFA enroll/verify/disable и recovery codes.
// См. также: handler/mfa.go, repository/mfa.go
package service

import (
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"fmt"
	"math/big"
	"strings"

	"github.com/gofiber/fiber/v2"
	"github.com/pquerna/otp"
	"github.com/pquerna/otp/totp"
	"maniforge/internal/config"
	"maniforge/internal/rbac/repository"
	"maniforge/internal/rbac/security"
)

const recoveryCodeCount = 10

type MFAService struct {
	cfg    config.Config
	mfa    *repository.MFARepository
	users  *repository.UserRepository
	pii    *security.PII
	events *repository.SecurityEventRepository
}

func NewMFAService(cfg config.Config, db *sql.DB) *MFAService {
	return &MFAService{
		cfg:    cfg,
		mfa:    repository.NewMFARepository(db),
		users:  repository.NewUserRepository(db, cfg),
		pii:    security.NewPII(cfg),
		events: repository.NewSecurityEventRepository(db, cfg),
	}
}

func (s *MFAService) Status(session *repository.SessionRecord) (map[string]any, int) {
	factor, err := s.mfa.ActiveTOTP(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	enrolled := factor != nil && factor.VerifiedAt.Valid
	unused, _ := s.mfa.UnusedRecoveryCount(session.UserID, session.TenantID, session.SubtenantID)
	return map[string]any{
		"ok": true,
		"mfa": map[string]any{
			"enrolled":            enrolled,
			"factor_type":         "totp",
			"recovery_codes_left": unused,
		},
	}, fiber.StatusOK
}

func (s *MFAService) Enroll(session *repository.SessionRecord, label string) (map[string]any, int) {
	if existing, _ := s.mfa.ActiveTOTP(session.UserID, session.TenantID, session.SubtenantID); existing != nil && existing.VerifiedAt.Valid {
		return map[string]any{"ok": false, "error": "TOTP уже активен; сначала отключите MFA"}, fiber.StatusConflict
	}
	label = strings.TrimSpace(label)
	if label == "" {
		label = "Authenticator"
	}
	key, err := totp.Generate(totp.GenerateOpts{
		Issuer:      s.cfg.AppName,
		AccountName: fmt.Sprintf("%d@%s/%s", session.UserID, session.TenantID, session.SubtenantID),
		Period:      30,
		Digits:      otp.DigitsSix,
		Algorithm:   otp.AlgorithmSHA1,
	})
	if err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	secretEnc, err := s.pii.Encrypt(key.Secret())
	if err != nil {
		return map[string]any{
			"ok": false,
			"error": "Для MFA требуется RBAC_PII_ENCRYPTION_KEY (32 байта base64)",
		}, fiber.StatusServiceUnavailable
	}
	if err := s.mfa.UpsertPendingTOTP(session.UserID, session.TenantID, session.SubtenantID, label, secretEnc); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.events.Write("mfa.enroll.started", &actor, session.TenantID, session.SubtenantID, "info", nil)
	return map[string]any{
		"ok": true,
		"factor_type": "totp",
		"otpauth_url": key.URL(),
		"secret":      key.Secret(),
		"label":       label,
		"hint":        "Подтвердите POST /me/mfa/verify с кодом из приложения",
	}, fiber.StatusOK
}

func (s *MFAService) VerifyEnroll(session *repository.SessionRecord, code string) (map[string]any, int) {
	factor, err := s.mfa.ActiveTOTP(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil || factor == nil {
		return map[string]any{"ok": false, "error": "Сначала вызовите POST /me/mfa/enroll"}, fiber.StatusUnprocessableEntity
	}
	if factor.VerifiedAt.Valid {
		return map[string]any{"ok": false, "error": "TOTP уже подтверждён"}, fiber.StatusConflict
	}
	secret, err := s.decryptSecret(factor.SecretEnc)
	if err != nil || !totp.Validate(strings.TrimSpace(code), secret) {
		return map[string]any{"ok": false, "error": "Неверный TOTP код"}, fiber.StatusForbidden
	}
	if err := s.mfa.MarkVerified(factor.ID); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	plainCodes, hashes := generateRecoveryCodes(recoveryCodeCount)
	if err := s.mfa.ReplaceRecoveryCodes(session.UserID, session.TenantID, session.SubtenantID, hashes); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	actor := session.UserID
	_ = s.events.Write("mfa.enroll.verified", &actor, session.TenantID, session.SubtenantID, "info", nil)
	return map[string]any{
		"ok": true, "enrolled": true,
		"recovery_codes": plainCodes,
		"hint":           "Сохраните recovery codes — показываются один раз",
	}, fiber.StatusOK
}

func (s *MFAService) Disable(session *repository.SessionRecord, password, totpCode, recoveryCode string) (map[string]any, int) {
	if !s.verifySecondFactor(session, password, totpCode, recoveryCode) {
		return map[string]any{"ok": false, "error": "Требуется верный пароль, TOTP или recovery code"}, fiber.StatusForbidden
	}
	if err := s.mfa.RevokeTOTP(session.UserID, session.TenantID, session.SubtenantID); err != nil {
		return map[string]any{"ok": false, "error": err.Error()}, fiber.StatusInternalServerError
	}
	_ = s.mfa.ReplaceRecoveryCodes(session.UserID, session.TenantID, session.SubtenantID, nil)
	actor := session.UserID
	_ = s.events.Write("mfa.disabled", &actor, session.TenantID, session.SubtenantID, "warning", nil)
	return map[string]any{"ok": true, "disabled": true}, fiber.StatusOK
}

// ValidateTOTP проверяет код для активного TOTP factor.
func (s *MFAService) ValidateTOTP(session *repository.SessionRecord, code string) bool {
	factor, err := s.mfa.ActiveTOTP(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil || factor == nil || !factor.VerifiedAt.Valid {
		return false
	}
	secret, err := s.decryptSecret(factor.SecretEnc)
	if err != nil {
		return false
	}
	return totp.Validate(strings.TrimSpace(code), secret)
}

// ValidateRecovery проверяет и потребляет recovery code.
func (s *MFAService) ValidateRecovery(session *repository.SessionRecord, code string) bool {
	code = strings.TrimSpace(code)
	if code == "" {
		return false
	}
	hash := hashRecoveryCode(code)
	ok, err := s.mfa.ConsumeRecoveryCode(session.UserID, session.TenantID, session.SubtenantID, hash)
	return err == nil && ok
}

func (s *MFAService) HasVerifiedTOTP(session *repository.SessionRecord) bool {
	factor, err := s.mfa.ActiveTOTP(session.UserID, session.TenantID, session.SubtenantID)
	return err == nil && factor != nil && factor.VerifiedAt.Valid
}

func (s *MFAService) verifySecondFactor(session *repository.SessionRecord, password, totpCode, recoveryCode string) bool {
	if recoveryCode != "" && s.ValidateRecovery(session, recoveryCode) {
		return true
	}
	if totpCode != "" && s.ValidateTOTP(session, totpCode) {
		return true
	}
	if password == "" {
		return false
	}
	user, err := s.users.FindByIDInScope(session.UserID, session.TenantID, session.SubtenantID)
	if err != nil || user == nil {
		return false
	}
	return security.VerifyPassword(password, user.PasswordHash)
}

func (s *MFAService) decryptSecret(secretEnc string) (string, error) {
	plain := s.pii.DecryptString(secretEnc)
	if plain == "" {
		return "", fmt.Errorf("decrypt failed")
	}
	return plain, nil
}

func generateRecoveryCodes(n int) ([]string, []string) {
	plain := make([]string, 0, n)
	hashes := make([]string, 0, n)
	for i := 0; i < n; i++ {
		code := randomRecoveryCode()
		plain = append(plain, code)
		hashes = append(hashes, hashRecoveryCode(code))
	}
	return plain, hashes
}

func randomRecoveryCode() string {
	const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"
	var b strings.Builder
	for i := 0; i < 10; i++ {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(alphabet))))
		b.WriteByte(alphabet[n.Int64()])
	}
	return b.String()
}

func hashRecoveryCode(code string) string {
	sum := sha256.Sum256([]byte(strings.ToUpper(strings.TrimSpace(code))))
	return hex.EncodeToString(sum[:])
}
