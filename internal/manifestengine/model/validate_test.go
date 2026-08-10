package model

import "testing"

func TestValidateDataUnknownField(t *testing.T) {
	fields := []FieldDef{{Name: "title", Type: FieldString}}
	err := ValidateData(fields, map[string]any{"extra": "x"}, false)
	if err == nil {
		t.Fatal("expected unknown field error")
	}
}

func TestValidateDataMaxLength(t *testing.T) {
	max := 3
	fields := []FieldDef{{Name: "title", Type: FieldString, MaxLength: &max}}
	err := ValidateData(fields, map[string]any{"title": "abcd"}, false)
	if err == nil {
		t.Fatal("expected max_length error")
	}
}

func TestValidateDataNumberMin(t *testing.T) {
	min := 0.0
	fields := []FieldDef{{Name: "price", Type: FieldNumber, Min: &min}}
	err := ValidateData(fields, map[string]any{"price": -1}, false)
	if err == nil {
		t.Fatal("expected min error")
	}
}

func TestParseFieldDefsInvalidType(t *testing.T) {
	_, err := ParseFieldDefs([]FieldDef{{Name: "x", Type: "blob"}})
	if err == nil {
		t.Fatal("expected invalid type")
	}
}

func TestValidateDataNullOptional(t *testing.T) {
	fields := []FieldDef{
		{Name: "title", Type: FieldString, Required: true},
		{Name: "body", Type: FieldString},
	}
	if err := ValidateData(fields, map[string]any{"title": "ok", "body": nil}, false); err != nil {
		t.Fatal(err)
	}
}

func TestValidateDataNullRequired(t *testing.T) {
	fields := []FieldDef{{Name: "title", Type: FieldString, Required: true}}
	err := ValidateData(fields, map[string]any{"title": nil}, false)
	if err == nil {
		t.Fatal("expected required error for null")
	}
}

func TestValidateDataNullRequiredMissingKey(t *testing.T) {
	fields := []FieldDef{
		{Name: "title", Type: FieldString, Required: true},
		{Name: "body", Type: FieldString},
	}
	err := ValidateData(fields, map[string]any{"title": "ok"}, false)
	if err != nil {
		t.Fatal(err)
	}
}
