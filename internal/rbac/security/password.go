// Package security — криптография и PII для RBAC.
//
// Файл: password.go
// Назначение: VerifyPassword (argon2id + legacy bcrypt), HashPassword (argon2id).
// См. также: service/auth.go, service/user_security.go
package security

import (
	"strings"

	"github.com/alexedwards/argon2id"
	"golang.org/x/crypto/bcrypt"
)

func VerifyPassword(plain, hash string) bool {
	if plain == "" || hash == "" {
		return false
	}

	if strings.HasPrefix(hash, "$argon2") {
		ok, err := argon2id.ComparePasswordAndHash(plain, hash)
		return err == nil && ok
	}

	err := bcrypt.CompareHashAndPassword([]byte(hash), []byte(plain))
	return err == nil
}

func HashPassword(plain string) (string, error) {
	return argon2id.CreateHash(plain, argon2id.DefaultParams)
}
