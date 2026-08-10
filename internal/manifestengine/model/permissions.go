package model

// AdminRoles — полный доступ к manifests и всем полям.
var AdminRoles = map[string]bool{
	"super_admin":    true,
	"tenant_admin":   true,
	"subtenant_admin": true,
}

// IsManifestAdmin проверяет право управлять схемами manifest.
func IsManifestAdmin(roleCodes []string) bool {
	for _, r := range roleCodes {
		if AdminRoles[r] {
			return true
		}
	}
	return false
}

// CanReadField — read_roles пуст → все; иначе пересечение с roleCodes или admin.
func CanReadField(roleCodes []string, def FieldDef) bool {
	if IsManifestAdmin(roleCodes) || len(def.ReadRoles) == 0 {
		return true
	}
	return hasRole(roleCodes, def.ReadRoles)
}

// CanWriteField — write_roles пуст → все; иначе пересечение или admin.
func CanWriteField(roleCodes []string, def FieldDef) bool {
	if IsManifestAdmin(roleCodes) || len(def.WriteRoles) == 0 {
		return true
	}
	return hasRole(roleCodes, def.WriteRoles)
}

func hasRole(userRoles, required []string) bool {
	set := make(map[string]bool, len(userRoles))
	for _, r := range userRoles {
		set[r] = true
	}
	for _, req := range required {
		if set[req] {
			return true
		}
	}
	return false
}

// FilterReadableData убирает поля без read-доступа из ответа.
func FilterReadableData(roleCodes []string, fields []FieldDef, data map[string]any) map[string]any {
	if IsManifestAdmin(roleCodes) {
		return data
	}
	fieldIndex := make(map[string]FieldDef, len(fields))
	for _, f := range fields {
		fieldIndex[f.Name] = f
	}
	out := make(map[string]any, len(data))
	for k, v := range data {
		def, ok := fieldIndex[k]
		if !ok || CanReadField(roleCodes, def) {
			out[k] = v
		}
	}
	return out
}

// ValidateWritableKeys проверяет write-доступ на ключи patch.
func ValidateWritableKeys(roleCodes []string, fields []FieldDef, keys []string) error {
	if IsManifestAdmin(roleCodes) {
		return nil
	}
	fieldIndex := make(map[string]FieldDef, len(fields))
	for _, f := range fields {
		fieldIndex[f.Name] = f
	}
	for _, key := range keys {
		def, ok := fieldIndex[key]
		if !ok {
			return errForbidden("поле не объявлено в manifest: " + key)
		}
		if !CanWriteField(roleCodes, def) {
			return errForbidden("нет write-доступа к полю: " + key)
		}
	}
	return nil
}

type forbiddenError string

func (e forbiddenError) Error() string { return string(e) }

func errForbidden(msg string) error { return forbiddenError(msg) }

// IsForbidden для handler.
func IsForbidden(err error) bool {
	_, ok := err.(forbiddenError)
	return ok
}
