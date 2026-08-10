// Файл: client_manifest.go
// Назначение: правила клиентского конструктора — только custom, не platform.
package model

import (
	"fmt"
	"strings"
)

// AssertClientMayDefineManifest проверяет, что клиент не пытается создать внутренний манифест.
func AssertClientMayDefineManifest(code string, meta map[string]any, reservedCodes func(string) bool) error {
	code = strings.ToLower(strings.TrimSpace(code))
	if reservedCodes != nil && reservedCodes(code) {
		return fmt.Errorf("код %q зарезервирован платформой; используйте POST /manifests/presets/{code}", code)
	}
	if err := rejectPlatformMetadata(meta); err != nil {
		return err
	}
	return nil
}

// AssertClientMayMutateManifest запрещает изменение внутренних (platform) манифестов клиентом.
func AssertClientMayMutateManifest(m *Manifest) error {
	if m == nil {
		return fmt.Errorf("manifest не найден")
	}
	if m.Origin == OriginPlatform {
		return fmt.Errorf("внутренний манифест (platform) управляется только платформой")
	}
	return nil
}

func rejectPlatformMetadata(meta map[string]any) error {
	if meta == nil {
		return nil
	}
	for _, key := range []string{"origin", "preset", "internal", "platform"} {
		if _, ok := meta[key]; ok {
			return fmt.Errorf("metadata.%s задаёт только платформа", key)
		}
	}
	if v, ok := meta["is_platform"].(bool); ok && v {
		return fmt.Errorf("metadata.is_platform задаёт только платформа")
	}
	return nil
}
