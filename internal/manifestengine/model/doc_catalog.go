// Файл: doc_catalog.go
// Назначение: type и section custom manifest для каталога /api (Персональные).
package model

import (
	"regexp"
	"strings"
)

const (
	MetadataDocTypeKey    = "type"
	MetadataDocSectionKey = "section"
)

var docCatalogSlugRe = regexp.MustCompile(`[^a-z0-9]+`)

// NormalizeDocSlug приводит type/section к slug или пустую строку.
func NormalizeDocSlug(raw string) string {
	s := strings.ToLower(strings.TrimSpace(raw))
	if s == "" {
		return ""
	}
	s = docCatalogSlugRe.ReplaceAllString(s, "-")
	s = strings.Trim(s, "-")
	if len(s) > 64 {
		s = s[:64]
	}
	return s
}

// NormalizeDocType — алиас для type.
func NormalizeDocType(raw string) string { return NormalizeDocSlug(raw) }

func DocTypeFromMetadata(meta map[string]any) string {
	if meta == nil {
		return ""
	}
	if v, ok := meta[MetadataDocTypeKey].(string); ok {
		return NormalizeDocSlug(v)
	}
	return ""
}

func DocSectionFromMetadata(meta map[string]any) string {
	if meta == nil {
		return ""
	}
	if v, ok := meta[MetadataDocSectionKey].(string); ok {
		return NormalizeDocSlug(v)
	}
	return ""
}

func ApplyDocTypeToMetadata(meta map[string]any, docType string) map[string]any {
	return applyDocCatalogKey(meta, MetadataDocTypeKey, docType)
}

func ApplyDocSectionToMetadata(meta map[string]any, section string) map[string]any {
	return applyDocCatalogKey(meta, MetadataDocSectionKey, section)
}

// ApplyDocCatalogMetadata применяет type и/или section (nil = не менять).
func ApplyDocCatalogMetadata(meta map[string]any, docType, section *string) map[string]any {
	out := meta
	if docType != nil {
		out = ApplyDocTypeToMetadata(out, *docType)
	}
	if section != nil {
		out = ApplyDocSectionToMetadata(out, *section)
	}
	return out
}

func applyDocCatalogKey(meta map[string]any, key, raw string) map[string]any {
	out := map[string]any{}
	for k, v := range meta {
		out[k] = v
	}
	norm := NormalizeDocSlug(raw)
	if norm == "" {
		delete(out, key)
	} else {
		out[key] = norm
	}
	return out
}
