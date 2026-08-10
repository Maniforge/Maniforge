// Файл: origin.go
// Назначение: классификация манифестов — platform (внутренние) vs custom (пользовательские).
package model

import "strings"

const (
	OriginPlatform = "platform" // базовые / preset supply chain
	OriginCustom   = "custom"   // созданы клиентом в конструкторе
)

// NormalizeOrigin валидирует query-параметр origin.
func NormalizeOrigin(raw string) (string, bool) {
	switch strings.ToLower(strings.TrimSpace(raw)) {
	case "", "all":
		return "", true
	case OriginPlatform, "internal":
		return OriginPlatform, true
	case OriginCustom, "user":
		return OriginCustom, true
	default:
		return "", false
	}
}
