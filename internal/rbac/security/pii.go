// Файл: pii.go
// Назначение: blind index для phone lookup, AES-GCM hydrate email/phone из *_enc.
// Зависимости: RBAC_PII_ENCRYPTION_* из config.
// См. также: repository/user.go
package security

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"io"
	"regexp"
	"strings"

	"maniforge/internal/config"
)

type PII struct {
	enabled   bool
	key       []byte
	blindKey  []byte
}

func NewPII(cfg config.Config) *PII {
	p := &PII{enabled: cfg.RBACPIIEncryptionEnabled}
	if raw := strings.TrimSpace(cfg.RBACPIIEncryptionKey); raw != "" {
		if decoded, err := base64.StdEncoding.DecodeString(raw); err == nil && len(decoded) == 32 {
			p.key = decoded
		}
	}
	p.blindKey = p.deriveBlindKey(cfg)
	return p
}

// PackedPII — поля maniforge_users для хранения контактов (plain или blind index + enc).
type PackedPII struct {
	Email         sql.NullString
	Phone         string
	EmailEnc      sql.NullString
	PhoneEnc      sql.NullString
	PIIEncVersion int
}

// PackForStorage — паритет PiiFieldCodec::packForStorage (PHP).
func (p *PII) PackForStorage(email, phone, tenantID, subtenantID string) (PackedPII, error) {
	phone = strings.TrimSpace(phone)
	email = strings.ToLower(strings.TrimSpace(email))
	if !p.enabled || len(p.key) != 32 {
		out := PackedPII{Phone: phone, PIIEncVersion: 0}
		if email != "" {
			out.Email = sql.NullString{String: email, Valid: true}
		}
		return out, nil
	}
	phoneEnc, err := p.Encrypt(phone)
	if err != nil {
		return PackedPII{}, err
	}
	out := PackedPII{
		// phone — global blind index (паритет phoneLookupValueGlobal / findAllByPhone).
		Phone:         p.blindIndex("phone", phone, "*", "*"),
		PhoneEnc:      sql.NullString{String: phoneEnc, Valid: true},
		PIIEncVersion: 1,
	}
	if email != "" {
		out.Email = sql.NullString{String: p.blindIndex("email", email, tenantID, subtenantID), Valid: true}
		enc, err := p.Encrypt(email)
		if err != nil {
			return PackedPII{}, err
		}
		out.EmailEnc = sql.NullString{String: enc, Valid: true}
	}
	return out, nil
}

func (p *PII) PhoneLookupGlobal(phone string) string {
	if p.enabled && len(p.key) == 32 {
		return p.blindIndex("phone", phone, "*", "*")
	}
	return phone
}

func (p *PII) HydrateContact(version int, phone string, email sql.NullString, phoneEnc, emailEnc sql.NullString) (string, sql.NullString) {
	if version < 1 || len(p.key) != 32 {
		return phone, email
	}
	if phoneEnc.Valid && phoneEnc.String != "" {
		if plain := p.decrypt(phoneEnc.String); plain != "" {
			phone = plain
		}
	}
	if emailEnc.Valid && emailEnc.String != "" {
		if plain := p.decrypt(emailEnc.String); plain != "" {
			email = sql.NullString{String: plain, Valid: true}
		}
	}
	return phone, email
}

func (p *PII) blindIndex(field, value, tenantID, subtenantID string) string {
	normalized := value
	if field == "email" {
		normalized = strings.ToLower(strings.TrimSpace(value))
	} else {
		normalized = regexp.MustCompile(`\D+`).ReplaceAllString(strings.TrimSpace(value), "")
	}
	msg := field + "|" + tenantID + "|" + subtenantID + "|" + normalized
	mac := hmac.New(sha256.New, p.blindKey)
	_, _ = mac.Write([]byte(msg))
	return hexEncode(mac.Sum(nil))
}

func hexEncode(b []byte) string {
	const hexdigits = "0123456789abcdef"
	out := make([]byte, len(b)*2)
	for i, v := range b {
		out[i*2] = hexdigits[v>>4]
		out[i*2+1] = hexdigits[v&0x0f]
	}
	return string(out)
}

func (p *PII) deriveBlindKey(cfg config.Config) []byte {
	if dedicated := strings.TrimSpace(cfg.RBACPIIBlindIndexKey); dedicated != "" {
		if decoded, err := base64.StdEncoding.DecodeString(dedicated); err == nil && len(decoded) >= 32 {
			return decoded[:32]
		}
		sum := sha256.Sum256([]byte(dedicated))
		return sum[:]
	}
	if len(p.key) == 32 {
		sum := sha256.Sum256(append(p.key, []byte("|blind")...))
		return sum[:]
	}
	return nil
}

// Encrypt шифрует произвольную строку (AES-256-GCM), формат как у email/phone enc.
func (p *PII) Encrypt(plain string) (string, error) {
	if len(p.key) != 32 {
		return "", errPIIKeyMissing
	}
	block, err := aes.NewCipher(p.key)
	if err != nil {
		return "", err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return "", err
	}
	iv := make([]byte, gcm.NonceSize())
	if _, err := io.ReadFull(rand.Reader, iv); err != nil {
		return "", err
	}
	sealed := gcm.Seal(nil, iv, []byte(plain), nil)
	tagSize := gcm.Overhead()
	ct := sealed[:len(sealed)-tagSize]
	tag := sealed[len(sealed)-tagSize:]
	payload, err := json.Marshal(map[string]any{
		"v": 1,
		"iv": base64.StdEncoding.EncodeToString(iv),
		"tag": base64.StdEncoding.EncodeToString(tag),
		"c":  base64.StdEncoding.EncodeToString(ct),
	})
	if err != nil {
		return "", err
	}
	return base64.StdEncoding.EncodeToString(payload), nil
}

var errPIIKeyMissing = &piiKeyError{}

type piiKeyError struct{}

func (e *piiKeyError) Error() string { return "PII encryption key is not configured" }

// DecryptString расшифровывает значение, зашифрованное Encrypt.
func (p *PII) DecryptString(packed string) string {
	return p.decrypt(packed)
}

func (p *PII) decrypt(packed string) string {
	if len(p.key) != 32 {
		return ""
	}
	raw, err := base64.StdEncoding.DecodeString(packed)
	if err != nil {
		return ""
	}
	var payload struct {
		V   int    `json:"v"`
		IV  string `json:"iv"`
		Tag string `json:"tag"`
		C   string `json:"c"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil || payload.V != 1 {
		return ""
	}
	iv, _ := base64.StdEncoding.DecodeString(payload.IV)
	tag, _ := base64.StdEncoding.DecodeString(payload.Tag)
	ct, _ := base64.StdEncoding.DecodeString(payload.C)
	if len(iv) == 0 || len(tag) == 0 || len(ct) == 0 {
		return ""
	}
	block, err := aes.NewCipher(p.key)
	if err != nil {
		return ""
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return ""
	}
	plain, err := gcm.Open(nil, iv, append(ct, tag...), nil)
	if err != nil {
		return ""
	}
	return string(plain)
}
