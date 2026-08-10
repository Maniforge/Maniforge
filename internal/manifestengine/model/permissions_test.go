package model

import "testing"

func TestCanReadFieldWithRoles(t *testing.T) {
	def := FieldDef{Name: "secret", Type: FieldString, ReadRoles: []string{"manager"}}
	if CanReadField([]string{"user"}, def) {
		t.Fatal("user should not read")
	}
	if !CanReadField([]string{"manager"}, def) {
		t.Fatal("manager should read")
	}
	if !CanReadField([]string{"tenant_admin"}, def) {
		t.Fatal("admin bypass")
	}
}

func TestFilterReadableData(t *testing.T) {
	fields := []FieldDef{
		{Name: "public", Type: FieldString},
		{Name: "secret", Type: FieldString, ReadRoles: []string{"manager"}},
	}
	data := map[string]any{"public": "a", "secret": "b"}
	out := FilterReadableData([]string{"user"}, fields, data)
	if _, ok := out["secret"]; ok {
		t.Fatal("secret should be redacted")
	}
	if out["public"] != "a" {
		t.Fatal("public should remain")
	}
}
