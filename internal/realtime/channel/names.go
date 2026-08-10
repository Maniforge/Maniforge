// Package channel — имена WS-каналов (platform + custom).
//
// Файл: names.go
// Назначение: entity.* / data.<code> для подписок клиента.
package channel

import (
	"fmt"
	"strings"
)

const (
	EntityAll      = "entity.all"
	EntityCustom   = "entity.custom"
	EntityPlatform = "entity.platform"
)

func Data(entityCode string) string  { return "data." + entityCode }
func Entity(entityCode string) string { return "entity." + entityCode }

// SuggestAll строит пример каналов для всех manifests проекта.
func SuggestAll(manifests map[string]string) []string {
	out := []string{EntityAll, EntityCustom, EntityPlatform}
	seen := map[string]struct{}{
		EntityAll: {}, EntityCustom: {}, EntityPlatform: {},
	}
	for code := range manifests {
		for _, ch := range []string{Data(code), Entity(code)} {
			if _, ok := seen[ch]; ok {
				continue
			}
			seen[ch] = struct{}{}
			out = append(out, ch)
		}
	}
	return out
}

func ValidateClientChannel(ch string) bool {
	switch ch {
	case EntityAll, EntityCustom, EntityPlatform, "notifications", "tenant":
		return true
	}
	if strings.HasPrefix(ch, "data.") && len(ch) > 5 {
		return true
	}
	if strings.HasPrefix(ch, "entity.") && len(ch) > 7 {
		return true
	}
	return false
}

// ValidateAgainstManifests проверяет data./entity.<code> по активным manifests.
func ValidateAgainstManifests(ch string, manifests map[string]string) error {
	switch ch {
	case EntityAll, EntityCustom, EntityPlatform, "notifications", "tenant":
		return nil
	}
	if strings.HasPrefix(ch, "data.") {
		code := ch[5:]
		if _, ok := manifests[code]; !ok {
			return fmt.Errorf("сущность %q не найдена в project scope", code)
		}
		return nil
	}
	if strings.HasPrefix(ch, "entity.") {
		code := ch[7:]
		if meta := entityMetaCode(code); meta != "" {
			return nil
		}
		if _, ok := manifests[code]; !ok {
			return fmt.Errorf("сущность %q не найдена в project scope", code)
		}
		return nil
	}
	return fmt.Errorf("неизвестный канал %q", ch)
}

func entityMetaCode(code string) string {
	switch code {
	case "all", "custom", "platform":
		return code
	default:
		return ""
	}
}

func InvalidChannelError(ch string) string {
	return fmt.Sprintf(
		"неизвестный канал %q: meta entity.all|entity.custom|entity.platform|notifications|tenant или data.<code>|entity.<code>",
		ch,
	)
}

// MatchesEvent проверяет доставку события на канал подписки.
func MatchesEvent(subChannel, eventChannel string, origin string) bool {
	if subChannel == eventChannel {
		return true
	}
	switch subChannel {
	case EntityAll:
		return strings.HasPrefix(eventChannel, "data.") || strings.HasPrefix(eventChannel, "entity.")
	case EntityCustom:
		return origin == "custom" && (strings.HasPrefix(eventChannel, "data.") || strings.HasPrefix(eventChannel, "entity."))
	case EntityPlatform:
		return origin == "platform" && (strings.HasPrefix(eventChannel, "data.") || strings.HasPrefix(eventChannel, "entity."))
	}
	return false
}
